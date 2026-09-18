<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-season.php';
require_once __DIR__ . '/../includes/uploads.php';

$bookingId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if (!$bookingId) {
    http_response_code(400);
    exit('Invalid booking.');
}

$stmt = $pdo->prepare(
    "SELECT artist_name, photo_path
     FROM dj_season_bookings
     WHERE id = ? AND season = ?
     LIMIT 1"
);
$stmt->execute([$bookingId, DESEO_DJ_SEASON]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    http_response_code(404);
    exit('Photo not found.');
}

$photoPath = (string)($booking['photo_path'] ?? '');
$absolute = deseo_upload_absolute_from_stored($photoPath);

if ($absolute === null || !is_file($absolute)) {
    http_response_code(404);
    exit('Photo not found.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($absolute);
$allowedMimes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

if (!isset($allowedMimes[$mime])) {
    http_response_code(415);
    exit('Unsupported photo type.');
}

$artist = trim((string)($booking['artist_name'] ?? 'dj'));
$artist = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $artist) ?: 'dj';
$filename = 'deseo-season6-' . trim($artist, '-') . '.' . $allowedMimes[$mime];

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($absolute));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

readfile($absolute);
exit;
