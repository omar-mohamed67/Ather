<?php
declare(strict_types=1);

const WELCOME_MESSAGE = 'برجاء الاستمتاع بموقع أثر وأتمنى أن ينال إعجابكم. برجاء العلم بأنه بعد 7 أيام تنتهي الفترة المجانية، وسيتم توفير عروض خاصة لسيادتكم ❤️';

function throttle_key(string $name): string {
    return hash('sha256', mb_strtolower(trim($name), 'UTF-8') . "\0" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}
function check_login_throttle(string $key): void {
    $row = one('SELECT locked_until FROM login_attempts WHERE key_hash=?', [$key]);
    $remaining = $row && $row['locked_until'] ? (int)ceil(strtotime($row['locked_until'] . ' UTC') - time()) : 0;
    if ($remaining > 0) { header('Retry-After: ' . $remaining); fail(429, "محاولات دخول كثيرة. انتظر $remaining ثانية ثم حاول مجددًا."); }
}
function fail_login_throttle(string $key): void {
    query('INSERT INTO login_attempts (key_hash,failures,updated_at) VALUES (?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE failures=IF(updated_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR),1,failures+1), updated_at=UTC_TIMESTAMP()', [$key]);
    $failures = (int)scalar('SELECT failures FROM login_attempts WHERE key_hash=?', [$key]);
    if ($failures < 5) return;
    $delay = min(900, 30 * (2 ** min(10, $failures - 5)));
    query('UPDATE login_attempts SET locked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE key_hash=?', [$delay, $key]);
    header('Retry-After: ' . $delay);
    fail(429, "محاولات دخول كثيرة. انتظر $delay ثانية ثم حاول مجددًا.");
}

function create_account(string $name, string $phone, string $password, bool $setup): string {
    $id = uuid();
    $locked = false;
    try {
        if ($setup) {
            $lockName = 'athar_setup_' . substr(hash('sha256', config()['db_name']), 0, 40);
            if ((int)scalar('SELECT GET_LOCK(?,5)', [$lockName]) !== 1) fail(503, 'تعذر بدء إنشاء حساب المدير. حاول مرة أخرى.');
            $locked = true;
        }
        db()->beginTransaction();
        if ($setup && (int)scalar('SELECT COUNT(*) FROM users') !== 0) fail(409, 'تم إنشاء حساب المدير بالفعل.');
        query('INSERT INTO users (id,username,name,phone,avatar,password_hash,role) VALUES (?,?,?,?,?,?,?)', [$id, $name, $name, $phone, default_avatar($id), password_hash($password, PASSWORD_DEFAULT), $setup ? 'admin' : 'user']);
        query('INSERT INTO schedules (user_id,payload) VALUES (?,?)', [$id, '{"schedule":{},"students":[]}']);
        query('INSERT INTO notifications (user_id,id,text) VALUES (?,?,?)', [$id, 'welcome', WELCOME_MESSAGE]);
        audit($id, 'account.created');
        db()->commit();
    } catch (PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        if ($e->getCode() === '23000') fail(409, 'الاسم مستخدم بالفعل، اختار اسمًا مختلفًا.');
        throw $e;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    } finally {
        if ($locked) query('SELECT RELEASE_LOCK(?)', [$lockName]);
    }
    return $id;
}

function auth_route(string $path, string $method, ?array $user): bool {
    if (!in_array($path, ['/login', '/signup', '/setup'], true)) return false;
    if (!in_array($method, ['GET', 'POST'], true)) fail(405, 'طريقة الطلب غير مسموحة.');
    $mode = substr($path, 1);
    $hasUsers = (int)scalar('SELECT COUNT(*) FROM users') > 0;
    if ($mode === 'setup' && $hasUsers) redirect_to('/login');
    if ($mode !== 'setup' && !$hasUsers) redirect_to('/setup');
    if ($mode === 'signup' && !(config()['allow_signup'] ?? true)) fail(403, 'إنشاء حسابات جديدة غير مُفعّل.');
    if ($method === 'GET') {
        if ($user) redirect_to($user['role'] === 'admin' ? '/admin' : '/');
        html_out(auth_page($mode));
    }
    try {
        same_origin();
        rate_limit('auth:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 15);
        $name = trim(checked_text($_POST['name'] ?? $_POST['user'] ?? null, 80, 'الاسم'));
        $password = checked_text($_POST['pass'] ?? null, 256, 'كلمة السر');
        if ($mode === 'login') {
            $key = throttle_key($name);
            check_login_throttle($key);
            $candidates = all('SELECT * FROM users WHERE username=? OR name=? ORDER BY IF(username=?,0,1) LIMIT 20', [$name, $name, $name]);
            $found = null;
            foreach ($candidates as $candidate) if (password_verify($password, $candidate['password_hash'])) { $found = $candidate; break; }
            if (!$found) {
                fail_login_throttle($key);
                fail(401, 'الاسم أو كلمة السر غير صحيحة.');
            }
            query('DELETE FROM login_attempts WHERE key_hash=?', [$key]);
            query('UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=?', [$found['id']]);
            audit($found['id'], 'account.login');
            $id = $found['id'];
            $isAdmin = $found['role'] === 'admin';
        } else {
            $profile = checked_profile($name, $_POST['phone'] ?? null);
            $password = checked_password($password);
            if ($password !== ($_POST['pass2'] ?? null)) fail(400, 'كلمتا السر غير متطابقتين.');
            $id = create_account($profile['name'], $profile['phone'], $password, $mode === 'setup');
            $isAdmin = $mode === 'setup';
        }
        create_session($id);
        redirect_to($isAdmin ? '/admin' : '/');
    } catch (AppError $e) {
        if ($e->status === 429 && !headers_sent()) http_response_code(429);
        html_out(auth_page($mode, $e->getMessage()), $e->status);
    }
}

function admin_route(string $method, array $user): never {
    if ($user['role'] !== 'admin') fail(403, 'الصفحة دي للمدير فقط.');
    if (!in_array($method, ['GET', 'POST'], true)) fail(405, 'طريقة الطلب غير مسموحة.');
    $notice = '';
    if ($method === 'POST') {
        same_origin(); csrf($user);
        $id = checked_text($_POST['user_id'] ?? null, 36, 'الحساب');
        $date = checked_text($_POST['vip_until'] ?? '', 10, 'تاريخ انتهاء VIP');
        $until = null;
        if ($date !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Africa/Cairo'));
            if (!$parsed || $parsed->format('Y-m-d') !== $date) fail(400, 'اختار تاريخ انتهاء صحيح.');
            $until = $parsed->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $target = one('SELECT username FROM users WHERE id=? AND role=\'user\'', [$id]);
        if (!$target) fail(404, 'الحساب غير موجود أو لا يمكن تعديل VIP له.');
        query('UPDATE users SET vip_until=? WHERE id=?', [$until, $id]);
        audit($user['id'], $until ? 'admin.vip-enabled' : 'admin.vip-disabled', ['target'=>$id, 'username'=>$target['username'], 'vipUntil'=>$date]);
        $notice = ($until ? 'تم تفعيل VIP للحساب ' : 'تم إيقاف VIP للحساب ') . $target['username'] . '.';
    }
    html_out(admin_page($user, $notice));
}
