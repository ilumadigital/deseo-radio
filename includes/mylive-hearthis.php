<?php
declare(strict_types=1);

require_once __DIR__ . '/dj-portal.php';

/**
 * Official HearThis Premium write API (hearthis.at/api).
 * Do not turn the worker on until one staging upload has been verified.
 * Credentials remain exclusively in the server-side .env and are passed as
 * HTTPS multipart POST fields, never in a logged URL or GitHub source.
 */
function deseo_hearthis_upload_config(): ?array {
    if (getenv('HEARTHIS_UPLOAD_ENABLED') !== '1') return null;
    $endpoint = 'https://xhr.hearthis.at/upload_api.php';
    $configured = trim((string)(getenv('HEARTHIS_UPLOAD_ENDPOINT') ?: ''));
    $mode = strtolower(trim((string)(getenv('HEARTHIS_UPLOAD_AUTH_MODE') ?: 'post')));
    $key = trim((string)(getenv('HEARTHIS_API_KEY') ?: getenv('HEARTHIS_KEY') ?: ''));
    $secret = trim((string)(getenv('HEARTHIS_API_SECRET') ?: getenv('HEARTHIS_SECRET') ?: ''));
    $username = strtolower(trim((string)(getenv('HEARTHIS_USERNAME') ?: '')));
    if (($configured !== '' && $configured !== $endpoint)
        || $mode !== 'post' || $key === '' || $secret === ''
        || !preg_match('/^[a-z0-9][a-z0-9-]*$/D', $username)) {
        return null; // Fail closed; never upload to an unverified endpoint.
    }
    return compact('endpoint', 'key', 'secret', 'username');
}

function deseo_hearthis_public_url(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
        && in_array(strtolower((string)($parts['host'] ?? '')), ['hearthis.at', 'www.hearthis.at'], true)
        && !isset($parts['user']) && !isset($parts['pass']);
}

/**
 * Validate a square DJ image under a known local storage root. No remote URLs,
 * user-supplied filesystem paths, station logo or 01 fallback can enter upload.
 */
function deseo_hearthis_cover_file(string $path, string $root, string $originalName, ?int $assetId, string $sourcePath = '', string $previewUrl = ''): ?array {
    $rootReal = realpath($root);
    $real = realpath($path);
    if (!$rootReal || !$real || !is_file($real) || is_link($path)
        || !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) return null;
    $info = @getimagesize($real);
    if (!is_array($info) || empty($info[0]) || (int)$info[0] !== (int)$info[1]) return null;
    $mime = (string)($info['mime'] ?? '');
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) return null;
    return [
        'path' => $real, 'mime' => $mime, 'name' => $originalName,
        'asset_id' => $assetId, 'source_path' => $sourcePath, 'preview_url' => $previewUrl,
    ];
}

/**
 * Find the 02 image adjacent to the account's registered original 01 artwork
 * in its exact weekday directory on the existing File Manager. The SQL dump
 * retains original_name (e.g. GregLef01.png), but not the original public file
 * location, because MyLive copied its 01 asset to private randomized storage.
 * Require one unique 01-anchored DJ folder: never pick a similarly named DJ.
 */
function deseo_hearthis_file_manager_cover(array $account, array $assets): array {
    $dayDirs = [
        3 => '1. Wed', 4 => '2. Thu', 5 => '3. Fri',
        6 => '4. Sat', 7 => '5. Sun',
    ];
    $dayName = $dayDirs[(int)($account['day_of_week'] ?? 0)] ?? '';
    if ($dayName === '') {
        throw new RuntimeException('No Resident DJ day for automatic artwork 02 resolution; assign the image explicitly.');
    }
    $root = realpath(dirname(__DIR__) . '/iluma/uploads/deseo_djs');
    $day = $root ? realpath($root . DIRECTORY_SEPARATOR . $dayName) : false;
    if (!$root || !$day || !is_dir($day)
        || !str_starts_with($day, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('DJ File Manager weekday artwork folder is unavailable.');
    }
    $seeds = [];
    foreach ($assets as $asset) {
        if ((string)$asset['asset_type'] !== 'artwork') continue;
        $original = basename((string)$asset['original_name']);
        if (preg_match('/^(.+?)01\.(?:png|jpe?g|webp)$/iD', $original, $parts)) {
            $seeds[$original] = $parts[1];
        }
    }
    if (!$seeds) throw new RuntimeException('No registered promotional artwork 01 for this DJ; assign square artwork 02 explicitly.');

    $matches = [];
    foreach (new DirectoryIterator($day) as $directory) {
        if ($directory->isDot() || !$directory->isDir() || $directory->isLink()) continue;
        $folderName = $directory->getFilename();
        if (!preg_match('/^[0-9]+\.\s+\S/u', $folderName)) continue;
        $folder = $directory->getRealPath();
        if (!$folder || !str_starts_with($folder, $day . DIRECTORY_SEPARATOR)) continue;
        foreach ($seeds as $original01 => $stem) {
            // The same-folder 01 source is strongest evidence. In case the
            // original 01 was removed after copying it into MyLive, accept
            // only an exact normalized folder-label = account's 01 basename.
            // Never accept a fuzzy or partial match.
            $original01Path = $folder . DIRECTORY_SEPARATOR . $original01;
            $hasOriginal01 = is_file($original01Path) && !is_link($original01Path);
            $folderLabel = preg_replace('/^[0-9]+\.\s*/u', '', $folderName);
            $folderToken = strtolower((string)preg_replace('/[^a-z0-9]/i', '', (string)$folderLabel));
            $originalToken = strtolower((string)preg_replace('/[^a-z0-9]/i', '', $stem));
            if (!$hasOriginal01 && ($originalToken === '' || $folderToken !== $originalToken)) continue;
            foreach (new DirectoryIterator($folder) as $candidate) {
                if (!$candidate->isFile() || $candidate->isLink()) continue;
                $name = $candidate->getFilename();
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) continue;
                if (strcasecmp((string)pathinfo($name, PATHINFO_FILENAME), $stem . '02') !== 0) continue;
                $relative = 'iluma/uploads/deseo_djs/' . $dayName . '/' . $folderName . '/' . $name;
                $preview = '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
                $cover = deseo_hearthis_cover_file(
                    $candidate->getPathname(), $root, $name, null, $relative, $preview
                );
                if (!$cover) {
                    throw new RuntimeException('The DJ File Manager artwork 02 exists but is not a valid square PNG/JPG/WEBP.');
                }
                $matches[$relative] = $cover;
            }
        }
    }
    if (count($matches) !== 1) {
        throw new RuntimeException(count($matches) > 1
            ? 'Multiple matching DJ artwork 02 files: choose one explicitly in MyLive Assets.'
            : 'Matching DJ artwork 02 not found beside registered 01 in the weekday File Manager folder.');
    }
    return reset($matches);
}

/**
 * A DJ-specific explicit MyLive cover takes priority. Otherwise discover the
 * existing square 02 next to this account's original 01 in the correct day.
 * No file is copied or renamed by automatic discovery.
 */
function deseo_hearthis_cover(PDO $pdo, int $accountId): array {
    $stmt = $pdo->prepare(
        "SELECT id, asset_type, original_name, file_path, mime_type
         FROM dj_portal_assets WHERE account_id = ?
         ORDER BY CASE WHEN asset_type = 'hearthis_cover' THEN 0 ELSE 1 END,
                  id DESC"
    );
    $stmt->execute([$accountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $storageRoot = realpath(dirname(__DIR__) . '/mylive/storage/assets');
    foreach ($rows as $asset) {
        $original = (string)$asset['original_name'];
        if (!preg_match('/(?:^|[^0-9])02\.(?:png|jpe?g|webp)$/iD', $original)) continue;
        $relative = ltrim((string)$asset['file_path'], '/');
        if (!preg_match('~^storage/assets/' . $accountId . '/[^/]+$~D', $relative)) continue;
        $absolute = dirname(__DIR__) . '/mylive/' . $relative;
        $cover = deseo_hearthis_cover_file(
            $absolute, (string)$storageRoot, $original, (int)$asset['id'], '',
            '/iluma/mylive-download.php?type=asset&id=' . (int)$asset['id'] . '&preview=1'
        );
        if ($cover) return $cover;
        if ((string)$asset['asset_type'] === 'hearthis_cover') {
            throw new RuntimeException('Assigned MyLive artwork 02 is missing or not square; correct this DJ asset.');
        }
    }

    $accountStmt = $pdo->prepare(
        "SELECT id, day_of_week, artist_name FROM dj_portal_accounts WHERE id = ? LIMIT 1"
    );
    $accountStmt->execute([$accountId]);
    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) throw new RuntimeException('DJ account does not exist.');
    return deseo_hearthis_file_manager_cover($account, $rows);
}

function deseo_hearthis_metadata(array $set): array {
    $artist = trim((string)$set['artist_name']);
    $episode = 'EP' . str_pad((string)(int)$set['episode_no'], 3, '0', STR_PAD_LEFT);
    $dateLine = '';
    if (!empty($set['scheduled_show_end'])) {
        try {
            $end = new DateTimeImmutable((string)$set['scheduled_show_end'], dj_season_athens_timezone());
            // Midnight belongs to the preceding broadcast day.
            $dateLine = 'Original broadcast: ' . $end->modify('-1 second')->format('d.m.Y')
                . ' (Athens local time)' . "\n";
        } catch (Throwable $ignored) {
            // Never fabricate a broadcast date from an invalid value.
        }
    }
    return [
        'title' => $artist . ' – Deseo Radio | S06 ' . $episode,
        'description' => 'Exclusive DJ Set by ' . $artist . ' for Deseo Radio · Season 6.' . "\n\n"
            . $dateLine
            . 'Listen Live: https://deseoradio.com' . "\n"
            . 'Season 6 DJ Sets: https://hearthis.at/deseoradio/set/season-6/' . "\n\n"
            . 'Stay Tuned, στο Soundtrack της ζωής σου!',
        'genre' => 'Radioshow',
    ];
}

/**
 * Older HearThis read objects can return an HTTP public permalink. Normalize
 * that one canonical provider domain to HTTPS before persisting; never follow
 * any URL supplied by an untrusted response.
 */
function deseo_hearthis_https_permalink(string $url): string {
    $url = trim($url);
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
        || !in_array(strtolower((string)($parts['host'] ?? '')), ['hearthis.at', 'www.hearthis.at'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || isset($parts['query']) || isset($parts['fragment'])) return $url;
    return 'https://' . strtolower((string)$parts['host']) . (string)($parts['path'] ?? '');
}

/**
 * Require a public track permalink under the account that owns the credentials.
 * A profile, playlist, another artist's track, or arbitrary hearthis.at URL
 * must never authorize deletion of an MP3.
 */
function deseo_hearthis_owned_track_url(string $url, string $username): bool {
    if (!deseo_hearthis_public_url($url)) return false;
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) return false;
    $segments = explode('/', trim((string)($parts['path'] ?? ''), '/'));
    return count($segments) === 2
        && strtolower(rawurldecode($segments[0])) === $username
        && preg_match('/^[a-z0-9][a-z0-9_-]*$/iD', rawurldecode($segments[1])) === 1;
}

/**
 * Upload to the documented Premium endpoint. Optional artwork is deliberately
 * omitted if its file is not a supported JPG/PNG <= 10 MB. HearThis may then
 * select embedded ID3 artwork or its account/platform default.
 *
 * The documented response shape is {"files":[{"id":"...", "error":"",
 * "full":{...}, "meta_error":"..."}]}; it is NOT a top-level track object.
 */
function deseo_hearthis_upload_track(array $set, array $config, ?array $cover): array {
    if (!function_exists('curl_init') || !class_exists('CURLFile')) {
        throw new RuntimeException('PHP cURL extension is required.');
    }
    $file = deseo_mylive_set_storage_file((string)$set['file_path']);
    if (!$file['exists'] || $file['storage_root'] === ''
        || !str_starts_with((string)$file['real_file'], (string)$file['storage_root'] . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Audio file unavailable inside protected MyLive storage.');
    }

    $metadata = deseo_hearthis_metadata($set);
    $fields = [
        'key' => $config['key'],
        'secret' => $config['secret'],
        'file' => new CURLFile((string)$file['real_file'], 'audio/mpeg', (string)$set['stored_name']),
        'title' => $metadata['title'],
        'private' => '0',
        'description' => $metadata['description'],
        'genre' => $metadata['genre'],
        'tags' => 'Deseo Radio,Season 6,DJ Set',
    ];
    if ($cover !== null
        && in_array((string)$cover['mime'], ['image/png', 'image/jpeg'], true)
        && is_file((string)$cover['path'])
        && filesize((string)$cover['path']) <= 10 * 1024 * 1024) {
        // Official field is "image" (or "cover"), not the provisional "artwork".
        $fields['image'] = new CURLFile(
            (string)$cover['path'], (string)$cover['mime'], (string)$cover['name']
        );
    }

    $curl = curl_init($config['endpoint']);
    if ($curl === false) throw new RuntimeException('Could not initialize HearThis upload.');
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
        if ($body === false) {
            throw new RuntimeException('Upload transport outcome uncertain; inspect HearThis before any retry.');
        }
        if ($http < 200 || $http >= 300) {
            throw new RuntimeException('HearThis upload returned HTTP ' . $http . '; manual verification required.');
        }
        $payload = json_decode((string)$body, true);
        $files = is_array($payload) ? ($payload['files'] ?? null) : null;
        if (!is_array($files) || count($files) !== 1 || !is_array($files[0])) {
            throw new RuntimeException('Unrecognized HearThis files[] response; inspect account before retrying.');
        }
        $item = $files[0];
        if (trim((string)($item['error'] ?? '')) !== '') {
            throw new RuntimeException('HearThis reported an upload error; inspect account before retrying.');
        }
        $id = trim((string)($item['id'] ?? ''));
        if ($id === '' || !ctype_digit($id) || (int)$id < 1) {
            throw new RuntimeException('HearThis returned no valid track ID; inspect account before retrying.');
        }
        $full = is_array($item['full'] ?? null) ? $item['full'] : [];
        if (isset($full['id']) && (string)$full['id'] !== $id) {
            // An ID mismatch is a quarantined remote outcome, not a retry.
            return ['id' => $id, 'url' => '', 'warning' => 'Track IDs differ in the upload response.'];
        }
        $owner = is_array($full['user'] ?? null) ? (string)($full['user']['permalink'] ?? '') : '';
        if ($owner !== '' && strtolower($owner) !== $config['username']) {
            return ['id' => $id, 'url' => '', 'warning' => 'Returned track belongs to a different account.'];
        }
        $url = deseo_hearthis_https_permalink((string)($full['permalink_url'] ?? $item['permalink_url'] ?? ''));
        if (!deseo_hearthis_owned_track_url($url, $config['username'])) {
            return ['id' => $id, 'url' => '', 'warning' => 'Track ID received without a valid owned public track permalink.'];
        }
        return [
            'id' => $id,
            'url' => $url,
            'warning' => substr(trim((string)($item['meta_error'] ?? '')), 0, 250),
        ];
    } finally {
        curl_close($curl);
    }
}

/**
 * Make a bounded, anonymous range request to the advertised public stream.
 * Never download an entire DJ set for verification or transmit API credentials
 * to the stream/CDN. All redirects are HTTPS-only; unready streams are retried.
 */
function deseo_hearthis_stream_playable(string $stream): bool {
    $parts = parse_url($stream);
    if (!is_array($parts) || !filter_var($stream, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string)($parts['host'] ?? '')), ['hearthis.at', 'www.hearthis.at'], true)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['https', 'http'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;
    if (($parts['scheme'] ?? '') === 'http') {
        // Some legacy player objects expose HTTP listen links; enforce TLS.
        $stream = 'https://' . $parts['host'] . (string)($parts['path'] ?? '')
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
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received): int {
            $length = strlen($chunk);
            if ($received + $length > 32768) return 0; // Abort an ignored Range.
            $received += $length;
            return $length;
        },
    ]);
    try {
        $ok = curl_exec($curl);
        $errno = curl_errno($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $mime = strtolower(trim((string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
    } finally {
        curl_close($curl);
    }
    return $received > 0 && in_array($http, [200, 206], true)
        && (str_starts_with($mime, 'audio/') || str_starts_with($mime, 'application/octet-stream'))
        && ($ok !== false || $errno === CURLE_WRITE_ERROR);
}

/**
 * Confirm independently from the public, anonymous read API that the uploaded
 * track belongs to this account, has finished processing, is not private, and
 * exposes a real streaming URL. No keys/secrets are sent to this read endpoint.
 */
function deseo_hearthis_public_track_ready(string $url, string $id, string $username): bool {
    if (!deseo_hearthis_owned_track_url($url, $username) || !ctype_digit($id)) return false;
    $segments = explode('/', trim((string)parse_url($url, PHP_URL_PATH), '/'));
    $slug = rawurldecode($segments[1]);
    $readUrl = 'https://api-v2.hearthis.at/' . rawurlencode($username) . '/' . rawurlencode($slug) . '/';
    $curl = curl_init($readUrl);
    if ($curl === false) return false;
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    try {
        $body = curl_exec($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    } finally {
        curl_close($curl);
    }
    if ($body === false || $http !== 200) return false;
    $track = json_decode((string)$body, true);
    if (!is_array($track) || (string)($track['id'] ?? '') !== $id) return false;
    $user = is_array($track['user'] ?? null) ? $track['user'] : [];
    if (strtolower((string)($user['permalink'] ?? '')) !== $username) return false;
    if (deseo_hearthis_https_permalink((string)($track['permalink_url'] ?? '')) !== $url) return false;
    if (isset($track['private']) && in_array(strtolower((string)$track['private']), ['1', 'true', 'yes'], true)) return false;
    if ((int)($track['duration'] ?? 0) <= 0) return false;
    $stream = trim((string)($track['stream_url'] ?? ''));
    $parts = parse_url($stream);
    if (!is_array($parts) || !filter_var($stream, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || !in_array(strtolower((string)($parts['host'] ?? '')), ['hearthis.at', 'www.hearthis.at'], true)
        || isset($parts['user']) || isset($parts['pass'])) return false;
    return deseo_hearthis_stream_playable($stream);
}

/** Prepare existing scheduled rows and complete slots in Europe/Athens. */
function deseo_hearthis_advance_broadcasts(PDO $pdo, DateTimeImmutable $now): int {
    $stmt = $pdo->query(
        "SELECT s.id, s.account_id, s.scheduled_show_end
         FROM dj_portal_sets s WHERE s.status = 'scheduled' AND s.file_deleted_at IS NULL
         ORDER BY s.account_id, s.episode_no"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $nowSql = $now->format('Y-m-d H:i:s');
    // Never strand a completed, pre-reserved slot after a cron/server outage.
    $advanced = 0;
    foreach ($rows as $row) {
        $end = (string)($row['scheduled_show_end'] ?? '');
        if ($end === '') {
            // Resolve each legacy row in episode order, respecting reserved dates.
            $end = (string)(deseo_mylive_next_show_end($pdo, (int)$row['account_id'], $now) ?? '');
            if ($end !== '') {
                $pdo->prepare(
                    "UPDATE dj_portal_sets SET scheduled_show_end = ?
                     WHERE id = ? AND status = 'scheduled' AND scheduled_show_end IS NULL"
                )->execute([$end, (int)$row['id']]);
            }
        }
        if ($end === '' || $end > $nowSql
            || $end < dj_season_start_at()->format('Y-m-d H:i:s')) continue;
        // A slot was planned and its broadcast window has completed.
        $pdo->prepare(
            "UPDATE dj_portal_sets SET status = 'broadcasted', broadcasted_at = scheduled_show_end,
                 delete_after = NULL
             WHERE id = ? AND status = 'scheduled' AND scheduled_show_end = ?
               AND file_deleted_at IS NULL"
        )->execute([(int)$row['id'], $end]);
        $advanced++;
    }
    return $advanced;
}

/**
 * CLI-only worker, serialized with a MySQL advisory lock. An ambiguous HTTP
 * outcome is review_required, NOT an automatic retry that might double-post.
 */
function deseo_hearthis_run(PDO $pdo, int $limit = 2): array {
    deseo_mylive_bootstrap($pdo);
    $summary = [
        'advanced' => 0, 'uploaded' => 0, 'synced' => 0,
        'verifying' => 0, 'review_required' => 0, 'default_artwork' => 0,
        'disabled' => false, 'retention' => [],
    ];
    $lock = $pdo->query("SELECT GET_LOCK('deseo_mylive_hearthis_worker', 0)");
    if (!$lock || (int)$lock->fetchColumn() !== 1) {
        $summary['locked'] = true;
        return $summary;
    }
    try {
        $now = new DateTimeImmutable('now', dj_season_athens_timezone());
        $nowSql = $now->format('Y-m-d H:i:s');
        $summary['advanced'] = deseo_hearthis_advance_broadcasts($pdo, $now);
        // A terminated request may have published remotely. Never auto-retry
        // this uncertain result, even if no URL came back to the worker.
        $pdo->prepare(
            "UPDATE dj_portal_sets SET hearthis_status = 'review_required',
                hearthis_error = 'Interrupted upload: verify account before retrying'
             WHERE hearthis_status = 'uploading' AND hearthis_started_at < ?"
        )->execute([$now->modify('-2 hours')->format('Y-m-d H:i:s')]);

        $config = deseo_hearthis_upload_config();
        if ($config === null) {
            $summary['disabled'] = true;
            $summary['retention'] = deseo_mylive_cleanup_broadcasted_sets($pdo);
            return $summary;
        }
        $uploadLimit = max(1, min(5, $limit));
        $stmt = $pdo->prepare(
            "SELECT s.id, s.account_id, s.episode_no, s.file_path, s.stored_name,
                    s.broadcasted_at, s.scheduled_show_end, a.artist_name
             FROM dj_portal_sets s
             INNER JOIN dj_portal_accounts a ON a.id = s.account_id
             WHERE s.status = 'broadcasted' AND s.hearthis_status = 'pending'
               AND s.file_deleted_at IS NULL AND s.broadcasted_at >= ?
               AND s.scheduled_show_end IS NOT NULL AND s.scheduled_show_end <= ?
             ORDER BY s.broadcasted_at ASC, s.id ASC LIMIT 100"
        );
        $stmt->execute([dj_season_start_at()->format('Y-m-d H:i:s'), $nowSql]);
        $attempted = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $set) {
            if ($attempted >= $uploadLimit) break;
            $id = (int)$set['id'];
            $file = deseo_mylive_set_storage_file((string)$set['file_path']);
            if (!$file['exists'] || $file['storage_root'] === ''
                || !str_starts_with((string)$file['real_file'], (string)$file['storage_root'] . DIRECTORY_SEPARATOR)) {
                $pdo->prepare(
                    "UPDATE dj_portal_sets SET hearthis_status = 'review_required',
                         hearthis_error = 'Audio file missing or outside protected storage'
                     WHERE id = ? AND hearthis_status = 'pending'"
                )->execute([$id]);
                $summary['review_required']++;
                continue;
            }

            // Artwork is optional, never a reason to withhold the audio archive.
            $cover = null;
            try {
                $cover = deseo_hearthis_cover($pdo, (int)$set['account_id']);
            } catch (PDOException $databaseError) {
                throw $databaseError;
            } catch (RuntimeException $coverError) {
                // Omit custom image: provider default or embedded ID3 will apply.
            }
            if ($cover !== null) {
                $path = (string)$cover['path'];
                $bytes = is_file($path) ? filesize($path) : false;
                if (!in_array((string)$cover['mime'], ['image/png', 'image/jpeg'], true)
                    || $bytes === false || $bytes < 1 || $bytes > 10 * 1024 * 1024) {
                    // Documented upload API accepts only <= 10 MB JPG/PNG.
                    $cover = null;
                }
            }
            if ($cover === null) $summary['default_artwork']++;
            $started = (new DateTimeImmutable('now', dj_season_athens_timezone()))->format('Y-m-d H:i:s');
            $claim = $pdo->prepare(
                "UPDATE dj_portal_sets SET hearthis_status = 'uploading',
                    hearthis_cover_asset_id = ?, hearthis_cover_source_path = ?,
                    hearthis_started_at = ?, hearthis_error = '',
                    hearthis_attempts = hearthis_attempts + 1
                 WHERE id = ? AND status = 'broadcasted' AND hearthis_status = 'pending'
                   AND file_deleted_at IS NULL"
            );
            $claim->execute([
                $cover !== null ? $cover['asset_id'] : null,
                $cover !== null ? (string)$cover['source_path'] : null,
                $started, $id
            ]);
            if ($claim->rowCount() !== 1) continue;
            $attempted++;
            try {
                $track = deseo_hearthis_upload_track($set, $config, $cover);
                if ($track['url'] === '') {
                    // The API confirms an ID but not a usable permalink. Never
                    // blindly re-upload; save the ID for manual reconciliation.
                    $pdo->prepare(
                        "UPDATE dj_portal_sets SET hearthis_status = 'review_required',
                             hearthis_track_id = ?, hearthis_error = ?
                         WHERE id = ? AND hearthis_status = 'uploading'"
                    )->execute([$track['id'], substr((string)$track['warning'], 0, 500), $id]);
                    $summary['review_required']++;
                    continue;
                }
                $save = $pdo->prepare(
                    "UPDATE dj_portal_sets SET hearthis_status = 'verifying',
                         hearthis_url = ?, hearthis_track_id = ?, hearthis_error = ?
                     WHERE id = ? AND status = 'broadcasted' AND hearthis_status = 'uploading'
                       AND file_deleted_at IS NULL"
                );
                $save->execute([$track['url'], $track['id'],
                    (string)$track['warning'], $id]);
                if ($save->rowCount() !== 1) {
                    throw new RuntimeException('Database state changed after remote upload; manual verification required.');
                }
                // Remote upload accepted; the local MP3 must remain until the
                // separately fetched public track and stream pass verification.
                $summary['uploaded']++;
            } catch (Throwable $error) {
                // Never log raw API bodies, credentials or private file paths.
                error_log('HearThis upload needs review for set ' . $id . ': ' . $error->getMessage());
                $pdo->prepare(
                    "UPDATE dj_portal_sets SET hearthis_status = 'review_required', hearthis_error = ?
                     WHERE id = ? AND hearthis_status = 'uploading'"
                )->execute([substr($error->getMessage(), 0, 500), $id]);
                $summary['review_required']++;
            }
        }

        // Check processed public tracks separately (including prior cron runs).
        // No upload is ever repeated while awaiting provider-side processing.
        $verify = $pdo->prepare(
            "SELECT id, hearthis_url, hearthis_track_id
             FROM dj_portal_sets WHERE status = 'broadcasted'
               AND hearthis_status = 'verifying' AND file_deleted_at IS NULL
               AND hearthis_url IS NOT NULL AND hearthis_track_id IS NOT NULL
             ORDER BY hearthis_started_at ASC, id ASC LIMIT 5"
        );
        $verify->execute();
        foreach ($verify->fetchAll(PDO::FETCH_ASSOC) as $track) {
            $id = (int)$track['id'];
            try {
                if (!deseo_hearthis_public_track_ready(
                    (string)$track['hearthis_url'], (string)$track['hearthis_track_id'],
                    (string)$config['username']
                )) {
                    $summary['verifying']++;
                    continue;
                }
                $pdo->prepare(
                    "UPDATE dj_portal_sets SET hearthis_status = 'synced',
                         hearthis_synced_at = ?, hearthis_error = ''
                     WHERE id = ? AND status = 'broadcasted'
                       AND hearthis_status = 'verifying' AND file_deleted_at IS NULL
                       AND hearthis_url = ? AND hearthis_track_id = ?"
                )->execute([
                    (new DateTimeImmutable('now', dj_season_athens_timezone()))->format('Y-m-d H:i:s'),
                    $id, $track['hearthis_url'], $track['hearthis_track_id']
                ]);
                $summary['synced']++;
            } catch (Throwable $verifyError) {
                // Never turn an uncertain verification into an upload retry.
                $summary['verifying']++;
                error_log('HearThis public verification postponed for set ' . $id . '.');
            }
        }
        $summary['retention'] = deseo_mylive_cleanup_broadcasted_sets($pdo);
        return $summary;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('deseo_mylive_hearthis_worker')");
    }
}
