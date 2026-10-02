<?php
declare(strict_types=1);

/**
 * Single-file, private HearThis API connectivity probe.
 *
 * CLI only. Never imports, updates or deletes MyLive sets, never enables the
 * automatic uploader, and never deletes the local MP3.
 *
 * php iluma/cli-hearthis-private-test.php --check
 * php iluma/cli-hearthis-private-test.php --upload-private
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/env.php';

const HEARTHIS_PRIVATE_TEST_ENDPOINT = 'https://xhr.hearthis.at/upload_api.php';
const HEARTHIS_PRIVATE_TEST_FILE = 'Deseo Radio - Season 6.mp3';

function deseo_private_test_fail(string $message, int $exitCode = 1): never {
    fwrite(STDERR, '[HearThis private test] ' . $message . PHP_EOL);
    exit($exitCode);
}

$command = $argv[1] ?? '';
if (count($argv) !== 2 || !in_array($command, ['--check', '--upload-private'], true)) {
    deseo_private_test_fail(
        'Usage: php iluma/cli-hearthis-private-test.php --check|--upload-private',
        2
    );
}

// Expected server layout: domains/deseoradio.com/{.env,deseo-uploads,public_html}.
$domainRoot = realpath(dirname(__DIR__, 2));
$uploadRoot = $domainRoot ? realpath($domainRoot . '/deseo-uploads') : false;
$source = $uploadRoot ? $uploadRoot . '/' . HEARTHIS_PRIVATE_TEST_FILE : '';
$file = $source !== '' ? realpath($source) : false;
if (!$domainRoot || !$uploadRoot || !is_dir($uploadRoot)
    || !str_starts_with($uploadRoot, $domainRoot . DIRECTORY_SEPARATOR)
    || !$file || $file !== $source || !is_file($file) || is_link($source)
    || !is_readable($file)) {
    deseo_private_test_fail('Expected readable non-symlink MP3 in the domain-level deseo-uploads folder, outside public_html.');
}
$size = filesize($file);
if ($size === false || $size < 1024 || $size > 1073741824) {
    deseo_private_test_fail('Test MP3 is empty, too small, or larger than 1 GB.');
}
if (!function_exists('finfo_open') || !function_exists('curl_init') || !class_exists('CURLFile')) {
    deseo_private_test_fail('The PHP fileinfo and cURL extensions are both required.');
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? (string)finfo_file($finfo, $file) : '';
if ($finfo) finfo_close($finfo);
$header = file_get_contents($file, false, null, 0, 3);
$isMp3Header = is_string($header)
    && (str_starts_with($header, 'ID3')
        || (strlen($header) >= 2 && ord($header[0]) === 0xff && (ord($header[1]) & 0xe0) === 0xe0));
if (!$isMp3Header || !in_array($mime, ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], true)) {
    deseo_private_test_fail('The selected file does not appear to contain MP3 audio.');
}

$key = trim((string)(getenv('HEARTHIS_API_KEY') ?: ''));
$secret = trim((string)(getenv('HEARTHIS_API_SECRET') ?: ''));
$username = strtolower(trim((string)(getenv('HEARTHIS_USERNAME') ?: '')));
$credentialsReady = $key !== '' && $secret !== '' && $username === 'deseoradio';
$receiptPath = $uploadRoot . '/.hearthis-private-test-receipt.json';
$lockPath = $uploadRoot . '/.hearthis-private-test.lock';
$existingReceipt = is_file($receiptPath) ? file_get_contents($receiptPath) : false;
$receipt = is_string($existingReceipt) ? json_decode($existingReceipt, true) : null;

echo 'HearThis private test file: ' . HEARTHIS_PRIVATE_TEST_FILE . PHP_EOL;
echo 'Size: ' . $size . ' bytes; detected type: ' . $mime . PHP_EOL;
echo 'API credentials configured for deseoradio: ' . ($credentialsReady ? 'yes' : 'no') . PHP_EOL;
echo 'Automatic MyLive uploads: ' . (getenv('HEARTHIS_UPLOAD_ENABLED') === '1' ? 'ENABLED (review settings)' : 'OFF') . PHP_EOL;
echo 'Previous attempt receipt: ' . (is_array($receipt)
    ? (string)($receipt['state'] ?? 'unknown') : ($existingReceipt === false ? 'none' : 'unreadable')) . PHP_EOL;
if ($command === '--check') {
    echo 'Dry run only. No API request, no database updates and no file deletion.' . PHP_EOL;
    exit(0);
}
if (!$credentialsReady) deseo_private_test_fail('Configure rotated HEARTHIS_API_KEY, HEARTHIS_API_SECRET and HEARTHIS_USERNAME=deseoradio in the server-side .env.');
if (getenv('HEARTHIS_UPLOAD_ENABLED') === '1') {
    deseo_private_test_fail('For this isolated private test, leave HEARTHIS_UPLOAD_ENABLED=0.');
}
if (!is_writable($uploadRoot)) {
    deseo_private_test_fail('deseo-uploads must be writable to store an attempt receipt and prevent duplicate uploads.');
}
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) deseo_private_test_fail('A test upload is already running.');
if (is_file($receiptPath)) {
    deseo_private_test_fail('A previous attempt is recorded. Inspect HearThis and the receipt before any new test; no automatic retry.');
}
$attempt = [
    'state' => 'request_started',
    'created_at' => gmdate('c'),
    'file' => HEARTHIS_PRIVATE_TEST_FILE,
    'file_sha256' => hash_file('sha256', $file),
    'visibility' => 'private',
];
$receiptBytes = json_encode($attempt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($receiptBytes === false || file_put_contents($receiptPath, $receiptBytes . "\n", LOCK_EX) === false) {
    deseo_private_test_fail('Could not create a duplicate-prevention receipt; upload cancelled.');
}
@chmod($receiptPath, 0600);
echo 'Sending exactly one PRIVATE test upload. No artwork, no MyLive changes.' . PHP_EOL;
$fields = [
    'key' => $key,
    'secret' => $secret,
    'file' => new CURLFile($file, 'audio/mpeg', HEARTHIS_PRIVATE_TEST_FILE),
    'title' => 'Deseo Radio - API Integration Test (PRIVATE)',
    'private' => '1',
    'description' => 'Private connectivity test for Deseo Radio. Not a scheduled DJ Set.',
];
$curl = curl_init(HEARTHIS_PRIVATE_TEST_ENDPOINT);
if ($curl === false) deseo_private_test_fail('Could not initialize cURL; inspect receipt before retrying.');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $fields,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 1800,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_MAXREDIRS => 0,
]);
try {
    $body = curl_exec($curl);
    $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
} finally {
    curl_close($curl);
}
// Never output an untrusted raw response (which might echo request parameters).
$attempt['http_status'] = $http;
$payload = is_string($body) ? json_decode($body, true) : null;
$files = is_array($payload) ? ($payload['files'] ?? null) : null;
$item = is_array($files) && count($files) === 1 && is_array($files[0]) ? $files[0] : null;
$id = $item !== null ? trim((string)($item['id'] ?? '')) : '';
$error = $item !== null ? trim((string)($item['error'] ?? '')) : '';
if ($body !== false && $http >= 200 && $http < 300 && $item !== null
    && $error === '' && ctype_digit($id) && (int)$id > 0) {
    $attempt['state'] = 'private_track_accepted';
    $attempt['track_id'] = $id;
    $attempt['meta_warning_present'] = trim((string)($item['meta_error'] ?? '')) !== '';
    echo 'PRIVATE upload accepted by HearThis; track ID: ' . $id . PHP_EOL;
    if ($attempt['meta_warning_present']) echo 'HearThis returned an optional metadata warning. Inspect the account.' . PHP_EOL;
} else {
    // A lost response, HTTP error or files[] error could still have created
    // a remote track. The receipt always prevents an accidental duplicate.
    $attempt['state'] = 'needs_manual_review';
    $attempt['api_file_error_present'] = $error !== '';
    echo 'Upload not confirmed. HTTP status: ' . $http . '; inspect the HearThis account before any retry.' . PHP_EOL;
}
$written = file_put_contents(
    $receiptPath,
    (string)json_encode($attempt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    LOCK_EX
);
if ($written === false) deseo_private_test_fail('Could not update receipt. Inspect the HearThis account before retry.');
echo 'Receipt saved outside public_html. The local MP3 was NOT deleted.' . PHP_EOL;
exit($attempt['state'] === 'private_track_accepted' ? 0 : 1);
