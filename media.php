<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/uploads.php';

$scope = trim((string)($_GET['scope'] ?? ''));
$filename = deseo_upload_safe_filename((string)($_GET['file'] ?? ''));

if ($filename === null || !in_array($scope, deseo_upload_allowed_scopes(), true)) {
    http_response_code(400);
    exit('Invalid media request.');
}

$absolute = deseo_upload_persistent_path($scope, $filename);

if ($absolute === null || !is_file($absolute)) {
    // Backward-compatible one-time migration if a legacy file still exists.
    $legacyStored = $scope === 'djs'
        ? '/iluma/uploads/djs/' . $filename
        : '/iluma/uploads/' . $filename;

    $absolute = deseo_upload_migrate_legacy($legacyStored);
}

if ($absolute === null || !is_file($absolute)) {
    http_response_code(404);
    exit('Media not found.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($absolute);
$allowed = ['image/jpeg', 'image/png', 'image/webp'];

if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    exit('Unsupported media type.');
}

$mtime = (int)filemtime($absolute);
$etag = '"' . sha1($filename . '|' . $mtime . '|' . filesize($absolute)) . '"';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($absolute));
header('Cache-Control: public, max-age=604800, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

readfile($absolute);
exit;
