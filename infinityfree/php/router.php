<?php
declare(strict_types=1);

require __DIR__ . '/_private/common.php';
require __DIR__ . '/_private/pages.php';
require __DIR__ . '/_private/auth.php';
require __DIR__ . '/_private/api.php';

function static_page(string $path, ?array $user): never {
    if ($path === '/' || $path === '/index.php' || $path === '/index.html') $path = '/index.html';
    if ($path === '/stories' || $path === '/stories/') $path = '/stories/index.html';
    if ($path === '/Games/hurufi.html') $path = '/games/hurufi.html';
    if (!preg_match('/\.html$/iD', $path) || str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~(?:^|/)\.~', $path)) fail(404, 'الملف غير موجود.');
    if (!$user && $path !== '/index.html') redirect_to('/login');
    $root = realpath(__DIR__);
    $file = realpath(__DIR__ . $path);
    if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) fail(404, 'الملف غير موجود.');
    $body = file_get_contents($file);
    if ($body === false) fail(500, 'تعذر فتح الصفحة.');
    if (str_contains($body, '__ATHAR_BOOTSTRAP_JSON__')) $body = str_replace('__ATHAR_BOOTSTRAP_JSON__', json_encode_safe(bootstrap_for($user)), $body);
    html_out($body);
}
function playback(string $path): never {
    if (!preg_match('~^/media/play/(assets/media/[a-f0-9]{20}|audio/stories/story-[0-9]{2}|sounds/applause)$~D', $path, $match)) fail(404, 'الملف غير موجود.');
    $file = realpath(__DIR__ . '/' . $match[1] . '.mp3');
    if (!$file || !str_starts_with($file, realpath(__DIR__) . DIRECTORY_SEPARATOR) || !is_file($file)) fail(404, 'الملف غير موجود.');
    $size = filesize($file);
    $start = 0; $end = $size - 1; $status = 200;
    if (isset($_SERVER['HTTP_RANGE'])) {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $_SERVER['HTTP_RANGE'], $m) || ($m[1] === '' && $m[2] === '')) fail(416, 'النطاق غير صالح.');
        $start = $m[1] !== '' ? (int)$m[1] : max(0, $size - (int)$m[2]);
        $end = $m[1] !== '' && $m[2] !== '' ? min((int)$m[2], $size - 1) : $size - 1;
        if ($start > $end || $start >= $size) { header('Content-Range: bytes */' . $size); fail(416, 'النطاق غير صالح.'); }
        $status = 206;
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    http_response_code($status);
    header('Content-Type: application/octet-stream');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
    $stream = fopen($file, 'rb');
    if ($stream === false) fail(500, 'تعذر تشغيل الصوت.');
    fseek($stream, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($stream)) {
        $chunk = fread($stream, min(65536, $left));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $left -= strlen($chunk);
    }
    fclose($stream);
    exit;
}

try {
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (strlen($path) > 2048 || str_contains($path, "\0")) fail(400, 'مسار غير صالح.');
    config();
    response_headers();
    if ($path === '/healthz' && $method === 'GET') { db()->query('SELECT 1'); json_out(['ok'=>true]); }
    if (str_starts_with($path, '/media/play/')) playback($path);
    $user = current_user();
    if (auth_route($path, $method, $user)) exit;
    if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'OPTIONS') same_origin();
    if (!$user) {
        if (str_starts_with($path, '/api/') || $path === '/logout' || !in_array($method, ['GET', 'HEAD'], true)) json_out(['ok'=>false, 'error'=>'auth'], 401);
        if ($path === '/admin') redirect_to('/login');
        static_page($path, null);
    }
    if ($path === '/logout' && $method === 'POST') {
        csrf($user);
        query('DELETE FROM sessions WHERE token_hash=?', [$user['token_hash']]);
        session_cookie('', true);
        header('Clear-Site-Data: "cache", "storage"');
        json_out(['ok'=>true]);
    }
    if ($user['role'] === 'admin') {
        if ($path === '/admin') admin_route($method, $user);
        if (str_starts_with($path, '/api/') || !in_array($method, ['GET','HEAD'], true)) fail(403, 'حساب المدير مخصص للوحة الإدارة فقط.');
        redirect_to('/admin');
    }
    if ($path === '/admin') admin_route($method, $user);
    if ($path === '/import' && $method === 'GET') html_out(import_page($user));
    if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'OPTIONS') {
        csrf($user);
        rate_limit('mutation:' . $user['id'], 240);
    }
    if ($path === '/' && $method === 'GET') query('INSERT INTO visitor_events (user_id) VALUES (?)', [$user['id']]);
    if (str_starts_with($path, '/api/')) api_route($path, $method, $user);
    if (!in_array($method, ['GET','HEAD'], true)) fail(405, 'طريقة الطلب غير مسموحة.');
    static_page($path, $user);
} catch (AppError $e) {
    $isApi = isset($path) && (str_starts_with($path, '/api/') || $path === '/logout' || (isset($method) && $method !== 'GET'));
    if ($isApi) json_out(['ok'=>false, 'error'=>$e->getMessage()] + $e->details, $e->status);
    html_out('<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>أثر</title><p>' . escape($e->getMessage()) . '</p><a href="/">العودة للموقع</a></html>', $e->status);
} catch (Throwable $e) {
    error_log('Athar PHP request failed: ' . $e->getMessage());
    $isApi = isset($path) && (str_starts_with($path, '/api/') || $path === '/logout' || (isset($method) && $method !== 'GET'));
    if ($isApi) json_out(['ok'=>false, 'error'=>'حصل خطأ في السيرفر. حاول مرة أخرى.'], 500);
    html_out('<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>أثر</title><p>حصل خطأ في السيرفر. حاول مرة أخرى.</p></html>', 500);
}
