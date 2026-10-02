<?php
declare(strict_types=1);

/**
 * One-off PUBLIC Season 6 launch release, independent of MyLive and its DB.
 * php iluma/cli-hearthis-season6-release.php --check
 * php iluma/cli-hearthis-season6-release.php --publish-public
 * php iluma/cli-hearthis-season6-release.php --finalize
 *
 * --publish-public is an explicit, once-only upload. --finalize will NEVER
 * repeat a possibly successful upload/add request. The local source MP3 is
 * deleted ONLY after public playback, Season 6 membership and an exact
 * confirmed entry with audio enclosure in the station's official podcast RSS.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/hearthis-season6.php';
require_once __DIR__ . '/../includes/hearthis-podcast.php';

const DESEO_S6_RELEASE_FILE = 'DeseoRadio - Season 6 Spot.mp3';
const DESEO_S6_RELEASE_TITLE = 'DeseoRadio - Season 6 Spot';
const DESEO_S6_RELEASE_API = 'https://xhr.hearthis.at/upload_api.php';
const DESEO_S6_RELEASE_RECEIPT = '.hearthis-season6-spot-release-receipt.json';

function deseo_s6_cli_fail(string $message, int $code = 1): never {
    fwrite(STDERR, '[Deseo Season 6 release] ' . $message . PHP_EOL);
    exit($code);
}

function deseo_s6_cli_save(string $path, array $record): void {
    $value = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($value === false || file_put_contents($path, $value . "\n", LOCK_EX) === false) {
        deseo_s6_cli_fail('Receipt could not be saved. STOP: inspect HearThis before any further action.');
    }
    @chmod($path, 0600);
}

function deseo_s6_cli_track_url(string $url): string {
    $url = trim($url);
    $parts = parse_url($url);
    if (!is_array($parts)) return '';
    if (($parts['scheme'] ?? '') === 'http'
        && in_array(strtolower((string)($parts['host'] ?? '')), ['hearthis.at', 'www.hearthis.at'], true)) {
        $url = 'https://' . strtolower((string)$parts['host']) . (string)($parts['path'] ?? '');
        $parts = parse_url($url);
    }
    if (($parts['scheme'] ?? '') !== 'https'
        || !in_array(strtolower((string)($parts['host'] ?? '')), ['hearthis.at', 'www.hearthis.at'], true)
        || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port'])
        || isset($parts['user']) || isset($parts['pass'])) return '';
    $segments = explode('/', trim((string)($parts['path'] ?? ''), '/'));
    return count($segments) === 2 && strtolower(rawurldecode($segments[0])) === 'deseoradio'
        && preg_match('/^[a-z0-9_-]+$/iD', rawurldecode($segments[1])) ? $url : '';
}

function deseo_s6_cli_public_url_by_id(string $trackId): string {
    if (!ctype_digit($trackId)) return '';
    $body = deseo_hearthis_s6_read('https://api-v2.hearthis.at/deseoradio/?type=tracks&count=100');
    if ($body === null) return '';
    $rows = array_is_list($body) ? $body : null;
    if ($rows === null) {
        foreach (['tracks', 'data', 'items'] as $k) {
            if (isset($body[$k]) && is_array($body[$k]) && array_is_list($body[$k])) {
                $rows = $body[$k];
                break;
            }
        }
    }
    if ($rows === null) return '';
    foreach ($rows as $row) {
        if (!is_array($row) || (string)($row['id'] ?? '') !== $trackId) continue;
        return deseo_s6_cli_track_url((string)($row['permalink_url'] ?? ''));
    }
    return '';
}

function deseo_s6_cli_stream_ready(string $stream): bool {
    $parts = parse_url($stream);
    if (!is_array($parts) || !filter_var($stream, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string)($parts['host'] ?? '')), ['hearthis.at', 'www.hearthis.at'], true)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['https', 'http'], true)
        || isset($parts['user']) || isset($parts['pass'])) return false;
    if (($parts['scheme'] ?? '') === 'http') {
        $stream = 'https://' . strtolower((string)$parts['host']) . (string)($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
    $curl = curl_init($stream);
    if ($curl === false) return false;
    $received = 0;
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ['Accept: audio/*', 'Range: bytes=0-1023'],
        CURLOPT_RANGE => '0-1023',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_TIMEOUT => 18,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$received): int {
            $n = strlen($chunk);
            if ($received + $n > 32768) return 0;
            $received += $n;
            return $n;
        },
    ]);
    try {
        $ok = curl_exec($curl);
        $errno = curl_errno($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $mime = strtolower(trim((string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
    } finally { curl_close($curl); }
    return $received > 0 && in_array($http, [200, 206], true)
        && (str_starts_with($mime, 'audio/') || str_starts_with($mime, 'application/octet-stream'))
        && ($ok !== false || $errno === CURLE_WRITE_ERROR);
}

function deseo_s6_cli_public_ready(string $trackUrl, string $trackId): bool {
    $canonical = deseo_s6_cli_track_url($trackUrl);
    if ($canonical === '' || !ctype_digit($trackId)) return false;
    $bits = explode('/', trim((string)parse_url($canonical, PHP_URL_PATH), '/'));
    $body = deseo_hearthis_s6_read(
        'https://api-v2.hearthis.at/deseoradio/' . rawurlencode(rawurldecode($bits[1])) . '/'
    );
    if ($body === null || (string)($body['id'] ?? '') !== $trackId) return false;
    $owner = is_array($body['user'] ?? null) ? $body['user'] : [];
    if (strtolower((string)($owner['permalink'] ?? '')) !== 'deseoradio') return false;
    if (deseo_s6_cli_track_url((string)($body['permalink_url'] ?? '')) !== $canonical) return false;
    if (isset($body['private']) && in_array(strtolower((string)$body['private']), ['1', 'true', 'yes'], true)) return false;
    $remoteTitle = trim((string)($body['title'] ?? $body['name'] ?? ''));
    if ($remoteTitle !== DESEO_S6_RELEASE_TITLE) return false;
    if ((int)($body['duration'] ?? 0) < 1) return false;
    return deseo_s6_cli_stream_ready(trim((string)($body['stream_url'] ?? '')));
}

/**
 * Prevent any wrong/non-audio file from being published or later unlinked.
 * The MP3 must remain under the fixed private domain-level folder.
 */
function deseo_s6_cli_valid_spot_mp3(string $path): bool {
    if (!is_file($path) || !is_readable($path) || !function_exists('finfo_open')) return false;
    $size = filesize($path);
    if ($size === false || $size < 1024 || $size > 1073741824) return false;
    $head = file_get_contents($path, false, null, 0, 3);
    if (!is_string($head) || !(str_starts_with($head, 'ID3')
        || (strlen($head) >= 2 && ord($head[0]) === 0xff
            && (ord($head[1]) & 0xe0) === 0xe0))) return false;
    $info = finfo_open(FILEINFO_MIME_TYPE);
    if (!$info) return false;
    try {
        $mime = (string)finfo_file($info, $path);
    } finally {
        finfo_close($info);
    }
    return in_array($mime, ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], true);
}

$command = $argv[1] ?? '';
if (count($argv) !== 2 || !in_array($command, ['--check', '--publish-public', '--finalize'], true)) {
    deseo_s6_cli_fail('Usage: --check | --publish-public | --finalize', 2);
}
$root = realpath(dirname(__DIR__, 2));
$folder = $root ? realpath($root . '/deseo-uploads') : false;
$expected = $folder ? $folder . '/' . DESEO_S6_RELEASE_FILE : '';
$file = $expected !== '' ? realpath($expected) : false;
$receiptPath = $folder ? $folder . '/' . DESEO_S6_RELEASE_RECEIPT : '';
// The earlier PRIVATE test was for a DIFFERENT MP3. Its receipt is unrelated
// and is deliberately neither modified nor required for this release.
if (!$root || !$folder || !str_starts_with($folder, $root . DIRECTORY_SEPARATOR)
    || !is_dir($folder) || !is_writable($folder)) {
    deseo_s6_cli_fail('The domain-level deseo-uploads folder is missing or not writable.');
}
$lock = fopen($folder . '/.hearthis-season6-spot-release.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) deseo_s6_cli_fail('A Season 6 release command is already running.');
$receiptBytes = is_file($receiptPath) ? file_get_contents($receiptPath) : false;
$record = is_string($receiptBytes) ? json_decode($receiptBytes, true) : null;
if ($receiptBytes !== false && !is_array($record)) deseo_s6_cli_fail('Existing release receipt is invalid: STOP. Do not upload again.');
$key = trim((string)(getenv('HEARTHIS_API_KEY') ?: ''));
$secret = trim((string)(getenv('HEARTHIS_API_SECRET') ?: ''));
$username = strtolower(trim((string)(getenv('HEARTHIS_USERNAME') ?: '')));
$config = ['key' => $key, 'secret' => $secret];
$playlist = deseo_hearthis_s6_playlist('deseoradio');
$setId = $playlist['id'] ?? null;
$setListing = $setId !== null ? deseo_hearthis_s6_track_listing($setId) : null;
$podcastReadable = deseo_hearthis_podcast_feed_readable();

echo 'Season 6 release title: ' . DESEO_S6_RELEASE_TITLE . PHP_EOL;
echo 'Season 6 existing set: ' . DESEO_HEARTHIS_SEASON6_URL . PHP_EOL;
echo 'Set numeric ID resolved: ' . ($setId ?? 'NO (no network write will be made)') . PHP_EOL;
echo 'Actual playlist URL: ' . ($playlist['url'] ?? 'UNKNOWN') . PHP_EOL;
echo 'Podcast RSS: ' . DESEO_HEARTHIS_PODCAST_RSS . PHP_EOL;
echo 'Podcast RSS readable: ' . ($podcastReadable ? 'YES' : 'NO (no upload/deletion allowed)') . PHP_EOL;
echo 'Canonical playlist track read: ' . ($setListing !== null
    ? 'OK (' . count($setListing['tracks']) . ' tracks)' : 'UNVERIFIED (no network write will be made)') . PHP_EOL;
echo 'Old PRIVATE test receipt: unrelated; preserved without modification.' . PHP_EOL;
echo 'PUBLIC release receipt: ' . (is_array($record) ? (string)($record['state'] ?? 'unknown') : 'none') . PHP_EOL;
$sourceValid = $file && $file === $expected && !is_link($expected)
    && deseo_s6_cli_valid_spot_mp3($file);
echo 'Exact Spot MP3: ' . ($sourceValid ? 'valid and readable' : 'missing/invalid') . PHP_EOL;
echo 'Credentials configured for deseoradio: ' . ($key !== '' && $secret !== '' && $username === 'deseoradio' ? 'yes' : 'no') . PHP_EOL;
echo 'Automatic MyLive upload: ' . (getenv('HEARTHIS_UPLOAD_ENABLED') === '1' ? 'ON (STOP)' : 'OFF') . PHP_EOL;
if ($command === '--check') {
    echo 'Dry run only: no upload, set modification or deletion.' . PHP_EOL;
    if (!$sourceValid || $setId === null || $setListing === null || !$podcastReadable || $key === '' || $secret === ''
        || $username !== 'deseoradio' || getenv('HEARTHIS_UPLOAD_ENABLED') === '1') {
        deseo_s6_cli_fail('Preflight not ready; do not publish. Check exact Spot MP3, Season 6 set, credentials and disabled worker.');
    }
    exit(0);
}
if ($username !== 'deseoradio' || $key === '' || $secret === '') deseo_s6_cli_fail('Valid rotated HearThis credentials are required in server .env.');
if (getenv('HEARTHIS_UPLOAD_ENABLED') === '1') deseo_s6_cli_fail('Keep the normal MyLive worker OFF during this isolated release.');

if ($command === '--publish-public') {
    if ($record !== null) deseo_s6_cli_fail('Release receipt exists; another upload is prohibited. Use --finalize or inspect receipt.');
    if ($setId === null || $setListing === null || !$podcastReadable) deseo_s6_cli_fail('Season 6 set listing and the station podcast RSS must BOTH be readable before upload.');
    if (!$file || $file !== $expected || !is_file($file) || is_link($expected) || !is_readable($file)) {
        deseo_s6_cli_fail('Expected original MP3 must still exist outside public_html.');
    }
    if (!deseo_s6_cli_valid_spot_mp3($file)) {
        deseo_s6_cli_fail('The selected Season 6 Spot is not a valid readable MP3.');
    }
    $sha = hash_file('sha256', $file);
    if (!is_string($sha) || $sha === '') deseo_s6_cli_fail('Could not hash the Spot MP3; no upload made.');
    // An independent spot-specific receipt is saved before its FIRST upload.
    // The historical private-test MP3 and receipt are never modified.
    $record = [
        'state' => 'upload_in_flight', 'created_at' => gmdate('c'),
        'title' => DESEO_S6_RELEASE_TITLE, 'file' => DESEO_S6_RELEASE_FILE,
        'file_sha256' => $sha, 'visibility' => 'public', 'set_id' => $setId,
        'set_state' => 'pending', 'podcast_state' => 'pending',
    ];
    deseo_s6_cli_save($receiptPath, $record);
    $curl = curl_init(DESEO_S6_RELEASE_API);
    if ($curl === false) deseo_s6_cli_fail('Upload initialization failed; inspect receipt before retry.');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'key' => $key, 'secret' => $secret,
            'file' => new CURLFile($file, 'audio/mpeg', DESEO_S6_RELEASE_FILE),
            'title' => DESEO_S6_RELEASE_TITLE,
            'private' => '0',
            'description' => 'DeseoRadio - Season 6 Spot.' . "\n\n"
                . 'Season 6 DJ Sets begin 14/10/2026.' . "\n"
                . '24 Resident DJs, Guest DJ slots and exclusive weekly DJ Sets.' . "\n"
                . 'Listen Live: https://deseoradio.com' . "\n"
                . 'Season 6: ' . DESEO_HEARTHIS_SEASON6_URL . "\n\n"
                . 'Stay Tuned, στο Soundtrack της ζωής σου!',
            'tags' => 'Deseo Radio,Season 6,Electronic Music',
        ],
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 1800,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    try {
        $body = curl_exec($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    } finally { curl_close($curl); }
    $payload = is_string($body) ? json_decode($body, true) : null;
    $files = is_array($payload) ? ($payload['files'] ?? null) : null;
    $row = is_array($files) && count($files) === 1 && is_array($files[0]) ? $files[0] : null;
    $id = is_array($row) ? trim((string)($row['id'] ?? '')) : '';
    $errorPresent = is_array($row) && trim((string)($row['error'] ?? '')) !== '';
    $record['http_status'] = $http;
    $record['error_reported'] = $errorPresent;
    if ($body !== false && $http >= 200 && $http < 300 && is_array($row)
        && !$errorPresent && ctype_digit($id) && (int)$id > 0) {
        $record['track_id'] = $id;
        $full = is_array($row['full'] ?? null) ? $row['full'] : [];
        $record['public_url'] = deseo_s6_cli_track_url((string)($full['permalink_url'] ?? $row['permalink_url'] ?? ''));
        $record['meta_warning_present'] = trim((string)($row['meta_error'] ?? '')) !== '';
        $record['state'] = 'upload_accepted';
        echo 'PUBLIC upload accepted. Track ID: ' . $id . PHP_EOL;
        if ($record['public_url'] !== '') echo 'Public permalink: ' . $record['public_url'] . PHP_EOL;
        else echo 'Public URL is awaiting discovery from the account feed; file retained.' . PHP_EOL;
        echo 'Next: run --finalize (this never uploads the MP3 again).' . PHP_EOL;
    } else {
        $record['state'] = 'needs_manual_review';
        echo 'PUBLIC upload outcome uncertain / rejected (HTTP ' . $http . '). Inspect HearThis BEFORE any new attempt.' . PHP_EOL;
    }
    deseo_s6_cli_save($receiptPath, $record);
    echo 'Private-test receipt remains unchanged. Season 6 Spot MP3 retained.' . PHP_EOL;
    exit($record['state'] === 'upload_accepted' ? 0 : 1);
}

// --finalize: public audio, Season 6 membership and exact podcast RSS item must ALL be proven.
if (!is_array($record) || !in_array((string)($record['state'] ?? ''), ['upload_accepted', 'finalizing', 'completed'], true)
    || !ctype_digit((string)($record['track_id'] ?? ''))
    || (string)($record['file'] ?? '') !== DESEO_S6_RELEASE_FILE
    || (string)($record['title'] ?? '') !== DESEO_S6_RELEASE_TITLE
    || (string)($record['visibility'] ?? '') !== 'public'
    || !preg_match('/^[a-f0-9]{64}$/D', (string)($record['file_sha256'] ?? ''))) {
    deseo_s6_cli_fail('No accepted public release. STOP: never auto-retry an uncertain upload.');
}
if ($record['state'] === 'completed') {
    echo 'Already completed. No additional POST or file deletion attempted.' . PHP_EOL;
    exit(0);
}
$id = (string)$record['track_id'];
$recordedSet = (string)($record['set_id'] ?? '');
if ($setId === null || $recordedSet !== $setId) deseo_s6_cli_fail('Public Season 6 set cannot be independently matched to recorded ID; source retained.');
$url = deseo_s6_cli_track_url((string)($record['public_url'] ?? ''));
if ($url === '') {
    $url = deseo_s6_cli_public_url_by_id($id);
    if ($url === '') deseo_s6_cli_fail('The new PUBLIC track permalink is not yet retrievable; source retained.');
    $record['public_url'] = $url;
    deseo_s6_cli_save($receiptPath, $record);
}
if (!deseo_s6_cli_public_ready($url, $id)) {
    echo 'Public track/audio is still processing or its playback check did not pass. Source retained; rerun --finalize later.' . PHP_EOL;
    exit(0);
}
echo 'Public track ID / ownership / audible stream verified: ' . $id . PHP_EOL;
$membership = deseo_hearthis_s6_contains_track($id, $setId);
if ($membership === null) deseo_s6_cli_fail('Season 6 set membership read unavailable or response unexpected; source retained.');
if ($membership === false) {
    $setState = (string)($record['set_state'] ?? 'pending');
    if ($setState !== 'pending') deseo_s6_cli_fail('Previous add request might already have succeeded. Inspect set; NO repeated add. MP3 retained.');
    $record['set_state'] = 'adding_uncertain';
    $record['set_started_at'] = gmdate('c');
    deseo_s6_cli_save($receiptPath, $record);
    $accepted = deseo_hearthis_s6_add_track($id, $setId, $config);
    $record['set_post_accepted'] = $accepted;
    deseo_s6_cli_save($receiptPath, $record);
    echo 'Season 6 add requested; now confirming actual membership via public read.' . PHP_EOL;
    $membership = deseo_hearthis_s6_contains_track($id, $setId);
    if ($membership !== true) {
        echo 'Membership not independently confirmed yet. Local MP3 retained. Re-run --finalize later; add will NOT be repeated.' . PHP_EOL;
        exit(0);
    }
}
$record['set_state'] = 'confirmed';
$record['set_confirmed_at'] = gmdate('c');
deseo_s6_cli_save($receiptPath, $record);
if (!deseo_hearthis_podcast_contains_track($id, $url, DESEO_S6_RELEASE_TITLE)) {
    echo 'PODCAST RSS PENDING: the exact public Spot and audio enclosure are not confirmed in ' .
        DESEO_HEARTHIS_PODCAST_RSS . '. Local MP3 retained. Check the HearThis dashboard toggle and retry --finalize later.' . PHP_EOL;
    exit(0);
}
$record['podcast_state'] = 'confirmed';
$record['podcast_confirmed_at'] = gmdate('c');
deseo_s6_cli_save($receiptPath, $record);
echo 'Podcast RSS: exact Spot episode with audio enclosure confirmed.' . PHP_EOL;
if (!deseo_s6_cli_public_ready($url, $id)) deseo_s6_cli_fail('Public audio no longer available: source retained.');
if (deseo_hearthis_s6_contains_track($id, $setId) !== true) deseo_s6_cli_fail('Season 6 membership changed: source retained.');
if (($record['podcast_state'] ?? '') !== 'confirmed'
    || !deseo_hearthis_podcast_contains_track($id, $url, DESEO_S6_RELEASE_TITLE)) {
    deseo_s6_cli_fail('Podcast RSS item/enclosure no longer verified: source retained.');
}
if ((string)$record['state'] === 'finalizing' && !file_exists($expected)
    && !empty($record['cleanup_authorized_at'])) {
    // Recover a successful unlink followed by an interrupted receipt update.
    // Both remote checks above have passed again; never unlink another file.
    $record['state'] = 'completed';
    $record['deleted_at'] = gmdate('c');
    $record['receipt_recovered_after_unlink'] = true;
    deseo_s6_cli_save($receiptPath, $record);
    echo 'SUCCESS: public track and set still verified; prior file cleanup recovered.' . PHP_EOL;
    exit(0);
}
if (!$file || $file !== $expected || !is_file($file) || is_link($expected)
    || hash_file('sha256', $file) !== (string)$record['file_sha256']) {
    deseo_s6_cli_fail('Local file absent/changed. No deletion performed.');
}
$record['state'] = 'finalizing';
$record['cleanup_authorized_at'] = gmdate('c');
deseo_s6_cli_save($receiptPath, $record);
if (!unlink($file)) deseo_s6_cli_fail('Could not delete the verified MP3; manual check needed. Receipt retained.');
$record['state'] = 'completed';
$record['deleted_at'] = gmdate('c');
deseo_s6_cli_save($receiptPath, $record);
echo 'SUCCESS: public Spot, Season 6 set AND podcast RSS verified; exact Spot MP3 removed from deseo-uploads.' . PHP_EOL;
echo 'HearThis URL: ' . $url . PHP_EOL;
