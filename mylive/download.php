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

$setId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT id, stored_name, file_path, file_size, mime_type
     FROM dj_portal_sets
     WHERE id = ? AND account_id = ?
     LIMIT 1"
);
$stmt->execute([$setId, deseo_mylive_account_id()]);
$set = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$set) {
    http_response_code(404);
    exit('File not found.');
}

$relative = ltrim((string)$set['file_path'], '/');
$base = realpath(__DIR__ . '/storage');
$file = realpath(__DIR__ . '/' . $relative);

if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit('File not found.');
}

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Content-Type: ' . ((string)$set['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Content-Disposition: attachment; filename="' . rawurlencode((string)$set['stored_name']) . '"; filename*=UTF-8\'\'' . rawurlencode((string)$set['stored_name']));
header('Cache-Control: private, no-store');
readfile($file);
exit;
