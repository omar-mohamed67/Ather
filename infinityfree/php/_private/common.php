<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

final class AppError extends RuntimeException {
    public function __construct(public int $status, string $message, public array $details = []) {
        parent::__construct($message);
    }
}

function config(): array {
    static $value;
    if ($value !== null) return $value;
    $file = __DIR__ . '/config.php';
    if (!is_file($file)) throw new AppError(503, 'أضف إعدادات قاعدة البيانات في _private/config.php.');
    $value = require $file;
    if (!is_array($value) || empty($value['db_host']) || empty($value['db_name']) || empty($value['db_user']) || empty($value['db_password']) || empty($value['site_url']) || str_contains($value['db_host'], 'XXX') || str_contains($value['site_url'], 'example')) {
        throw new AppError(503, 'أكمل إعدادات قاعدة البيانات والدومين في _private/config.php.');
    }
    return $value;
}

function db(): PDO {
    static $pdo;
    if ($pdo !== null) return $pdo;
    $c = config();
    $pdo = new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function query(string $sql, array $params = []): PDOStatement {
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function one(string $sql, array $params = []): ?array {
    $row = query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function all(string $sql, array $params = []): array { return query($sql, $params)->fetchAll(); }
function scalar(string $sql, array $params = []): mixed { return query($sql, $params)->fetchColumn(); }
function fail(int $status, string $message, array $details = []): never { throw new AppError($status, $message, $details); }
function escape(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_encode_safe(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); }

function json_out(mixed $value, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode_safe($value);
    exit;
}
function html_out(string $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $body;
    exit;
}
function redirect_to(string $path): never {
    header('Location: ' . $path, true, 303);
    exit;
}
function request_json(int $maxBytes): stdClass {
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) fail(415, 'أرسل البيانات بصيغة JSON.');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) fail(413, 'حجم الطلب أكبر من المسموح.');
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) fail(413, 'حجم الطلب أكبر من المسموح.');
    try { $data = json_decode($raw, false, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { fail(400, 'بيانات الطلب غير صالحة.'); }
    if (!$data instanceof stdClass) fail(400, 'بيانات الطلب غير صالحة.');
    return $data;
}
function checked_text(mixed $value, int $max, string $label, bool $optional = false): string {
    if ($optional && $value === null) return '';
    if (!is_string($value) || mb_strlen($value, 'UTF-8') > $max || str_contains($value, "\0")) fail(400, "$label غير صالح.");
    return $value;
}
function checked_profile(mixed $name, mixed $phone): array {
    $name = trim(checked_text($name, 80, 'الاسم'));
    $phone = trim(strtr(checked_text($phone, 40, 'رقم الهاتف'), ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
    if (mb_strlen($name, 'UTF-8') < 2) fail(400, 'اكتب الاسم (حرفين على الأقل).');
    if (!preg_match('/^\+?[\d\s-]+$/D', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 8 || strlen(preg_replace('/\D/', '', $phone)) > 15) fail(400, 'رقم الهاتف غير صحيح.');
    return ['name' => $name, 'phone' => $phone];
}
function checked_password(mixed $value): string {
    $password = checked_text($value, 256, 'كلمة السر');
    if (!preg_match('/^[0-9٠-٩]{6}$/uD', $password)) fail(400, 'كلمة السر لازم تكون ٦ أرقام بالضبط.');
    return $password;
}
function uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 15) | 64);
    $b[8] = chr((ord($b[8]) & 63) | 128);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}
function default_avatar(string $id): string { return 'preset:' . (unpack('N', hash('sha256', $id, true))[1] % 7); }
function iso_date(?string $date): ?string { return $date === null ? null : (new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'); }
function profile_public(array $row): array {
    $created = new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC'));
    return [
        'user' => $row['username'], 'name' => $row['name'], 'phone' => $row['phone'],
        'avatar' => $row['avatar'] ?: default_avatar($row['id']),
        'isVip' => $row['vip_until'] !== null && strtotime($row['vip_until'] . ' UTC') > time(),
        'vipUntil' => iso_date($row['vip_until']),
        'trialEndsAt' => $created->modify('+7 days')->format('Y-m-d\TH:i:s.v\Z'),
    ];
}
function audit(?string $userId, string $action, array $metadata = []): void {
    query('INSERT INTO audit_log (user_id,action,metadata) VALUES (?,?,?)', [$userId, $action, json_encode_safe($metadata)]);
}
function rate_limit(string $key, int $limit, int $windowSeconds = 60): void {
    $hash = hash('sha256', $key);
    query('INSERT INTO request_limits (key_hash,hits,window_end) VALUES (?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE hits=IF(window_end <= UTC_TIMESTAMP(),1,hits+1),window_end=IF(window_end <= UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),window_end)', [$hash, $windowSeconds, $windowSeconds]);
    $row = one('SELECT hits,window_end FROM request_limits WHERE key_hash=?', [$hash]);
    if ((int)$row['hits'] > $limit) {
        $wait = max(1, strtotime($row['window_end'] . ' UTC') - time());
        header('Retry-After: ' . $wait);
        fail(429, "محاولات كثيرة. انتظر $wait ثانية ثم حاول مجددًا.");
    }
}
function normalized_origin(string $url): ?string {
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return null;
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) return null;
    $host = strtolower(rtrim($parts['host'], '.'));
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    return $scheme . '://' . $host . ':' . $port;
}
function same_origin(): void {
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') fail(403, 'الطلب لازم ييجي من نفس الموقع.');
    $siteOrigin = normalized_origin(config()['site_url']);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && (!$siteOrigin || normalized_origin($origin) !== $siteOrigin)) fail(403, 'الطلب لازم ييجي من نفس الموقع.');
    if ($origin === '') {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref === '' || !$siteOrigin || normalized_origin($ref) !== $siteOrigin) fail(403, 'الطلب لازم ييجي من نفس الموقع.');
    }
}
function session_cookie(string $token, bool $expired = false): void {
    setcookie('sid', $token, [
        'expires' => $expired ? time() - 3600 : 0,
        'path' => '/', 'secure' => str_starts_with(config()['site_url'], 'https://'),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
}
function current_user(): ?array {
    $token = $_COOKIE['sid'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
    return one('SELECT s.token_hash,s.csrf_token,u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at > UTC_TIMESTAMP()', [hash('sha256', $token)]);
}
function csrf(array $user): void {
    $given = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($given) || !hash_equals($user['csrf_token'], $given)) fail(403, 'انتهت صلاحية الصفحة. حدّث الصفحة وحاول مرة أخرى.');
}
function create_session(string $userId): void {
    $token = bin2hex(random_bytes(32));
    $hours = max(1, min(168, (int)(config()['session_hours'] ?? 24)));
    query('INSERT INTO sessions (token_hash,user_id,csrf_token,expires_at) VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR))', [hash('sha256', $token), $userId, bin2hex(random_bytes(32)), $hours]);
    session_cookie($token);
}
function settings_for(string $userId): stdClass {
    $out = new stdClass();
    foreach (all('SELECT setting_key,setting_value FROM user_settings WHERE user_id=?', [$userId]) as $row) $out->{$row['setting_key']} = $row['setting_value'];
    return $out;
}
function notifications_for(string $userId): array {
    return array_map(static fn(array $n): array => ['id'=>$n['id'], 'text'=>$n['text'], 'read'=>$n['read_at'] !== null, 'time'=>strtotime($n['created_at'] . ' UTC') * 1000], all('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at,id', [$userId]));
}
function bootstrap_for(?array $user): array {
    return $user ? ['me'=>profile_public($user), 'csrfToken'=>$user['csrf_token'], 'settings'=>settings_for($user['id']), 'notifications'=>notifications_for($user['id'])] : ['me'=>null, 'csrfToken'=>'', 'settings'=>new stdClass(), 'notifications'=>[]];
}
function response_headers(): void {
    header('Cache-Control: no-store'); header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: blob:; media-src 'self' data: blob: https:; connect-src 'self' https://api.quran.com; frame-src 'self' blob:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
    if (str_starts_with(config()['site_url'], 'https://')) header('Strict-Transport-Security: max-age=31536000');
}
