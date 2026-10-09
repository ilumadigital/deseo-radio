<?php
declare(strict_types=1);

/** Public, read-only 45-second cached metadata for the private design preview. */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: https://deseoradio.com');
require_once __DIR__ . '/includes/mydemo-nowplaying.php';

$cache = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    . 'deseo-demo-track-' . substr(hash('sha256', __DIR__), 0, 20) . '.json';
$ttl = 45;
$reply = null;
$handle = @fopen($cache, 'c+');
if ($handle && flock($handle, LOCK_EX)) {
    $cached = stream_get_contents($handle);
    $decoded = is_string($cached) ? json_decode($cached, true) : null;
    if (is_array($decoded) && (int)($decoded['_cached_at'] ?? 0) > time() - $ttl) {
        $reply = $decoded;
    } else {
        $reply = md_payload();
        $reply['_cached_at'] = time();
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    }
    flock($handle, LOCK_UN);
}
if ($handle) fclose($handle);
if (!is_array($reply)) $reply = md_payload();
unset($reply['_cached_at']);
echo json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
