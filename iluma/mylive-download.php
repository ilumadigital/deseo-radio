<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-portal.php';

deseo_mylive_bootstrap($pdo);

$type = (string)($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);

if ($id < 1 || !in_array($type, ['set', 'asset'], true)) {
    http_response_code(400);
    exit('Invalid request.');
}

if ($type === 'set') {
    $stmt = $pdo->prepare(
        "SELECT stored_name AS download_name, file_path, mime_type
         FROM dj_portal_sets WHERE id = ? LIMIT 1"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT original_name AS download_name, file_path, mime_type
         FROM dj_portal_assets WHERE id = ? LIMIT 1"
    );
}
$stmt->execute([$id]);
$fileRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fileRow) {
    http_response_code(404);
    exit('File not found.');
}

$relative = ltrim((string)$fileRow['file_path'], '/');
$base = realpath(dirname(__DIR__) . '/mylive/storage');
$file = realpath(dirname(__DIR__) . '/mylive/' . $relative);

if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit('File not found.');
}

$name = basename((string)$fileRow['download_name']);
$mime = (string)$fileRow['mime_type'] ?: 'application/octet-stream';

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate', true);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header(
    'Content-Disposition: attachment; filename="' . rawurlencode($name) . '"'
    . "; filename*=UTF-8''" . rawurlencode($name)
);
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
readfile($file);
exit;
