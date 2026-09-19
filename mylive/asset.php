<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/dj-portal.php';

deseo_mylive_session_start();
deseo_mylive_bootstrap($pdo);

if (!deseo_mylive_logged_in()) {
    header('Location: /mylive/');
    exit;
}

$activeAccount = deseo_mylive_account($pdo, deseo_mylive_account_id());
if (!$activeAccount) {
    $_SESSION = [];
    header('Location: /mylive/');
    exit;
}

$assetId = (int)($_GET['id'] ?? 0);
$viewInline = (int)($_GET['view'] ?? 0) === 1;

$stmt = $pdo->prepare(
    "SELECT id, title, original_name, stored_name, file_path, file_size, mime_type
     FROM dj_portal_assets
     WHERE id = ? AND account_id = ?
     LIMIT 1"
);
$stmt->execute([$assetId, deseo_mylive_account_id()]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$asset) {
    http_response_code(404);
    exit('File not found.');
}

$relative = ltrim((string)$asset['file_path'], '/');
$base = realpath(__DIR__ . '/storage');
$file = realpath(__DIR__ . '/' . $relative);

if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit('File not found.');
}

$mime = (string)$asset['mime_type'] ?: 'application/octet-stream';
$downloadName = basename((string)$asset['original_name']);
$disposition = ($viewInline && str_starts_with($mime, 'image/')) ? 'inline' : 'attachment';

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header(
    'Content-Disposition: ' . $disposition
    . '; filename="' . rawurlencode($downloadName) . '"'
    . "; filename*=UTF-8''" . rawurlencode($downloadName)
);
header('Cache-Control: private, no-store');
readfile($file);
exit;
