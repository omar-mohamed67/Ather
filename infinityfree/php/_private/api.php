<?php
declare(strict_types=1);

function read_schedule(string $id): array {
    $row = one('SELECT revision,payload FROM schedules WHERE user_id=?', [$id]);
    if (!$row) return ['schedule'=>new stdClass(), 'students'=>[], 'revision'=>0];
    $payload = json_decode($row['payload'], false, 512, JSON_THROW_ON_ERROR);
    return ['schedule'=>$payload->schedule ?? new stdClass(), 'students'=>$payload->students ?? [], 'revision'=>(int)$row['revision']];
}
function validate_schedule(stdClass $data): void {
    if (!isset($data->schedule, $data->students, $data->revision) || !$data->schedule instanceof stdClass || !is_array($data->students) || !is_int($data->revision) || $data->revision < 0) fail(400, 'بيانات الجدول غير صالحة.');
    $days = ['السبت','الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة'];
    $periods = ['الأولى','الثانية','الثالثة','الرابعة','الخامسة','السادسة'];
    $ampms = ['', 'صباحًا', 'مساءً'];
    if (count(get_object_vars($data->schedule)) > 42 || count($data->students) > 20000) fail(400, 'عدد التسجيلات أكبر من المسموح في الطلب.');
    foreach (get_object_vars($data->schedule) as $key => $slot) {
        if (!$slot instanceof stdClass || !str_contains($key, '-')) fail(400, 'خانة الجدول غير صالحة.');
        [$day, $period] = explode('-', $key, 2);
        if (!in_array($day, $days, true) || !in_array($period, $periods, true) || !in_array($slot->ampm ?? '', $ampms, true)) fail(400, 'بيانات اليوم أو الحصة غير صالحة.');
        checked_text($slot->students ?? null, 3000, 'أسماء الطلاب');
        checked_text($slot->time ?? null, 120, 'التوقيت', true);
    }
    $ids = [];
    foreach ($data->students as $student) {
        if (!$student instanceof stdClass || !is_string($student->id ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $student->id) || isset($ids[$student->id])) fail(400, 'معرّف التسجيل غير صالح أو مكرر.');
        $ids[$student->id] = true;
        if (!in_array($student->day ?? null, $days, true) || !in_array($student->period ?? null, $periods, true) || !in_array($student->ampm ?? '', $ampms, true)) fail(400, 'بيانات اليوم أو الحصة غير صالحة.');
        checked_text($student->name ?? null, 120, 'اسم الطالب');
        checked_text($student->time ?? null, 120, 'التوقيت', true);
        checked_text($student->note ?? null, 10000, 'الملاحظة', true);
        if (!empty($student->date)) {
            if (!is_string($student->date)) fail(400, 'تاريخ الحصة غير صالح.');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $student->date, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d') !== $student->date) fail(400, 'تاريخ الحصة غير صالح.');
        }
        if (isset($student->members)) {
            if (!is_array($student->members) || count($student->members) > 100) fail(400, 'أفراد المجموعة غير صالحين.');
            foreach ($student->members as $member) checked_text($member, 120, 'أفراد المجموعة');
        }
    }
}
function save_schedule(string $id, stdClass $data): array {
    validate_schedule($data);
    $payload = json_encode_safe(['schedule'=>$data->schedule, 'students'=>$data->students]);
    db()->beginTransaction();
    try {
        $row = one('SELECT revision FROM schedules WHERE user_id=? FOR UPDATE', [$id]);
        if (!$row) fail(404, 'الجدول غير موجود.');
        if ((int)$row['revision'] !== $data->revision) fail(409, 'الجدول اتعدل من صفحة أو جهاز تاني. حدّث الصفحة قبل الحفظ.', ['revision'=>(int)$row['revision']]);
        $revision = $data->revision + 1;
        query('UPDATE schedules SET revision=?,payload=? WHERE user_id=?', [$revision, $payload, $id]);
        audit($id, 'schedule.saved', ['revision'=>$revision, 'slots'=>count(get_object_vars($data->schedule)), 'registrations'=>count($data->students)]);
        db()->commit();
        return ['ok'=>true, 'revision'=>$revision];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}
function validated_settings(stdClass $changes): array {
    $allowed = ['theme','hurufi-theme','hurufi-muted','hurufi-progress-v1','hurufi-arcade-v1','qsrcid','quran-options','site-tour-v1-complete','ui-language'];
    $items = get_object_vars($changes);
    if (count($items) > 10) fail(400, 'الإعدادات غير صالحة.');
    foreach ($items as $key => $value) {
        if (!in_array($key, $allowed, true) || ($value !== null && (!is_string($value) || mb_strlen($value, 'UTF-8') > 100000 || str_contains($value, "\0")))) fail(400, 'قيمة الإعداد غير صالحة.');
        if ($value !== null && in_array($key, ['theme','hurufi-theme'], true) && !in_array($value, ['light','dark'], true)) fail(400, 'المظهر غير صالح.');
        if ($value !== null && $key === 'ui-language' && !in_array($value, ['ar','en'], true)) fail(400, 'اللغة غير صالحة.');
        if ($value !== null && $key === 'hurufi-muted' && !in_array($value, ['true','false'], true)) fail(400, 'إعداد الصوت غير صالح.');
        if ($value !== null && $key === 'site-tour-v1-complete' && $value !== 'yes') fail(400, 'حالة الجولة التعريفية غير صالحة.');
        if ($value !== null && in_array($key, ['hurufi-progress-v1','hurufi-arcade-v1','quran-options'], true)) {
            $decoded = json_decode($value);
            if (!$decoded instanceof stdClass) fail(400, 'بيانات تقدم اللعبة غير صالحة.');
        }
    }
    if (array_key_exists('hurufi-theme', $items)) $items['theme'] = $items['hurufi-theme'];
    elseif (array_key_exists('theme', $items)) $items['hurufi-theme'] = $items['theme'];
    return $items;
}
function save_settings(string $id, stdClass $changes): void {
    $items = validated_settings($changes);
    db()->beginTransaction();
    try {
        foreach ($items as $key => $value) {
            if ($value === null) query('DELETE FROM user_settings WHERE user_id=? AND setting_key=?', [$id, $key]);
            else query('INSERT INTO user_settings (user_id,setting_key,setting_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP()', [$id, $key, $value]);
        }
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}
function import_account(array $user, stdClass $backup): array {
    if (($backup->format ?? null) !== 'athar-account-v2' || !(($backup->schedule ?? null) instanceof stdClass) || !is_array($backup->students ?? null) || !(($backup->settings ?? null) instanceof stdClass)) fail(400, 'ملف النسخة الاحتياطية غير صالح.');
    $current = read_schedule($user['id']);
    $incoming = (object)['schedule'=>$backup->schedule, 'students'=>$backup->students, 'revision'=>$current['revision']];
    validate_schedule($incoming);
    $settings = validated_settings($backup->settings);
    $payload = json_encode_safe(['schedule'=>$incoming->schedule, 'students'=>$incoming->students]);
    db()->beginTransaction();
    try {
        $row = one('SELECT revision FROM schedules WHERE user_id=? FOR UPDATE', [$user['id']]);
        if (!$row || (int)$row['revision'] !== $current['revision']) fail(409, 'الجدول اتعدل من جهاز تاني. حدّث الصفحة وحاول مجددًا.');
        query('UPDATE schedules SET revision=?,payload=? WHERE user_id=?', [$current['revision'] + 1, $payload, $user['id']]);
        foreach ($settings as $key => $value) {
            if ($value === null) query('DELETE FROM user_settings WHERE user_id=? AND setting_key=?', [$user['id'], $key]);
            else query('INSERT INTO user_settings (user_id,setting_key,setting_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP()', [$user['id'], $key, $value]);
        }
        audit($user['id'], 'account.backup-imported', ['registrations'=>count($incoming->students)]);
        db()->commit();
        return ['ok'=>true, 'students'=>count($incoming->students)];
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}
function export_account(array $user): array {
    return ['format'=>'athar-account-v2', 'exportedAt'=>gmdate('Y-m-d\TH:i:s\Z'), 'profile'=>profile_public($user)] + read_schedule($user['id']) + ['settings'=>settings_for($user['id']), 'notifications'=>notifications_for($user['id'])];
}
function send_telegram(array $user): array {
    $c = config();
    if (empty($c['telegram_token']) || empty($c['telegram_chat_id'])) return ['ok'=>false, 'msg'=>'النسخ على تيليجرام غير مُفعّل. يمكنك تنزيل نسخة من /api/export.'];
    if (!function_exists('curl_init')) return ['ok'=>false, 'msg'=>'إرسال النسخة غير متاح على هذه الاستضافة.'];
    $token = $c['telegram_token'];
    if (!preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D', $token)) return ['ok'=>false, 'msg'=>'إعداد تيليجرام غير صحيح.'];
    $boundary = 'athar' . bin2hex(random_bytes(12));
    $json = json_encode_safe(export_account($user));
    $body = "--$boundary\r\nContent-Disposition: form-data; name=\"chat_id\"\r\n\r\n" . $c['telegram_chat_id'] . "\r\n--$boundary\r\nContent-Disposition: form-data; name=\"caption\"\r\n\r\nنسخة احتياطية لحساب أثر\r\n--$boundary\r\nContent-Disposition: form-data; name=\"document\"; filename=\"athar-backup.json\"\r\nContent-Type: application/json\r\n\r\n$json\r\n--$boundary--\r\n";
    $handle = curl_init('https://api.telegram.org/bot' . $token . '/sendDocument');
    curl_setopt_array($handle, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>['Content-Type: multipart/form-data; boundary=' . $boundary], CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20]);
    $response = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($status !== 200 || !$response || !json_decode($response)?->ok) return ['ok'=>false, 'msg'=>'تعذر إرسال النسخة إلى تيليجرام.'];
    audit($user['id'], 'backup.telegram');
    return ['ok'=>true, 'msg'=>'تم إرسال النسخة الاحتياطية ✅'];
}
function api_route(string $path, string $method, array $user): never {
    $id = $user['id'];
    if ($path === '/api/me' && $method === 'GET') json_out(profile_public($user));
    if ($path === '/api/bootstrap' && $method === 'GET') json_out(bootstrap_for($user));
    if ($path === '/api/schedule' && $method === 'GET') json_out(read_schedule($id));
    if ($path === '/api/schedule' && $method === 'POST') json_out(save_schedule($id, request_json(8 * 1024 * 1024)));
    if ($path === '/api/settings' && $method === 'GET') json_out(settings_for($id));
    if ($path === '/api/settings' && $method === 'PATCH') {
        $data = request_json(512000);
        if (!(($data->changes ?? null) instanceof stdClass)) fail(400, 'الإعدادات غير صالحة.');
        save_settings($id, $data->changes); json_out(['ok'=>true]);
    }
    if ($path === '/api/notifications' && $method === 'GET') json_out(notifications_for($id));
    if ($path === '/api/notifications/read' && $method === 'POST') {
        $data = request_json(12000);
        if (!is_array($data->ids ?? null) || count($data->ids) > 100) fail(400, 'الإشعارات غير صالحة.');
        foreach ($data->ids as $noticeId) {
            if (!is_string($noticeId) || strlen($noticeId) > 100) fail(400, 'الإشعارات غير صالحة.');
            query('UPDATE notifications SET read_at=UTC_TIMESTAMP() WHERE user_id=? AND id=? AND read_at IS NULL', [$id, $noticeId]);
        }
        json_out(['ok'=>true]);
    }
    if ($path === '/api/profile' && $method === 'POST') {
        $data = request_json(12000); $profile = checked_profile($data->name ?? null, $data->phone ?? null);
        query('UPDATE users SET name=?,phone=? WHERE id=?', [$profile['name'], $profile['phone'], $id]);
        json_out(['ok'=>true] + $profile);
    }
    if ($path === '/api/avatar' && $method === 'POST') {
        $data = request_json(600000); $image = $data->image ?? null;
        if (!is_string($image) || strlen($image) > 400000 || ($image !== '' && !preg_match('~^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$~D', $image))) fail(400, 'الصورة غير صالحة أو كبيرة.');
        if ($image !== '') {
            $bytes = base64_decode(substr($image, strpos($image, ',') + 1), true);
            $info = $bytes ? getimagesizefromstring($bytes) : false;
            if (!$info || !in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true) || !str_contains(substr($image, 0, 30), $info['mime'])) fail(400, 'محتوى الصورة غير صالح.');
        }
        $avatar = $image ?: default_avatar($id);
        query('UPDATE users SET avatar=? WHERE id=?', [$avatar, $id]);
        json_out(['ok'=>true, 'avatar'=>$avatar]);
    }
    if ($path === '/api/password' && $method === 'POST') {
        rate_limit('password:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . $id, 15);
        $data = request_json(12000);
        if (!is_string($data->old ?? null) || !password_verify($data->old, $user['password_hash'])) fail(401, 'كلمة السر الحالية غلط.');
        $new = checked_password($data->pass ?? null);
        db()->beginTransaction();
        try {
            query('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $id]);
            query('DELETE FROM sessions WHERE user_id=? AND token_hash<>?', [$id, $user['token_hash']]);
            audit($id, 'account.password-changed');
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
        json_out(['ok'=>true]);
    }
    if ($path === '/api/export' && $method === 'GET') {
        header('Content-Disposition: attachment; filename="athar-account-backup.json"');
        json_out(export_account($user));
    }
    if ($path === '/api/import' && $method === 'POST') json_out(import_account($user, request_json(8 * 1024 * 1024)));
    if ($path === '/api/backup-now' && $method === 'POST') {
        rate_limit('backup:' . $id, 2);
        json_out(send_telegram($user));
    }
    fail(404, 'الخدمة غير موجودة.');
}
