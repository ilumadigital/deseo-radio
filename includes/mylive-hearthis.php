<?php
declare(strict_types=1);

require_once __DIR__ . '/dj-portal.php';

/**
 * Upload transport is deliberately OFF until the HearThis write endpoint,
 * authentication scheme and response payload have been checked with the
 * account's actual API documentation. Never infer a write endpoint from /api-v2.
 */
function deseo_hearthis_upload_config(): ?array {
    if (getenv('HEARTHIS_UPLOAD_ENABLED') !== '1') return null;
    $endpoint = trim((string)(getenv('HEARTHIS_UPLOAD_ENDPOINT') ?: ''));
    $key = trim((string)(getenv('HEARTHIS_API_KEY') ?: getenv('HEARTHIS_KEY') ?: ''));
    $secret = trim((string)(getenv('HEARTHIS_API_SECRET') ?: getenv('HEARTHIS_SECRET') ?: ''));
    $username = trim((string)(getenv('HEARTHIS_USERNAME') ?: ''));
    $mode = trim((string)(getenv('HEARTHIS_UPLOAD_AUTH_MODE') ?: ''));
    $parts = parse_url($endpoint);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || $host === ''
        || ($host !== 'hearthis.at' && !str_ends_with($host, '.hearthis.at'))
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || $key === '' || $secret === '' || $username === ''
        || !in_array($mode, ['basic', 'headers'], true)) {
        // A fail-closed transport protects both credentials and local MP3 files.
        return null;
    }
    return compact('endpoint', 'key', 'secret', 'username', 'mode');
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
            // Original 01 must still be present in this source folder. That is
            // our account-specific provenance check, not a fuzzy name search.
            if (!is_file($folder . DIRECTORY_SEPARATOR . $original01)
                || is_link($folder . DIRECTORY_SEPARATOR . $original01)) continue;
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

function deseo_hearthis_upload_track(array $set, array $config, array $cover): array {
    if (!function_exists('curl_init') || !class_exists('CURLFile')) {
        throw new RuntimeException('PHP cURL extension is required.');
    }
    $file = deseo_mylive_set_storage_file((string)$set['file_path']);
    if (!$file['exists'] || $file['storage_root'] === ''
        || !str_starts_with((string)$file['real_file'], (string)$file['storage_root'] . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Audio file unavailable inside protected MyLive storage.');
    }
    $metadata = deseo_hearthis_metadata($set);
    // Field names are provisional until the HearThis write API is verified.
    // Config remains fail-closed; no default logo or 01 cover is ever sent.
    $fields = [
        'file' => new CURLFile((string)$file['real_file'], 'audio/mpeg', (string)$set['stored_name']),
        'artwork' => new CURLFile((string)$cover['path'], (string)$cover['mime'], (string)$cover['name']),
        'title' => $metadata['title'],
        'description' => $metadata['description'],
        'genre' => $metadata['genre'],
        'username' => $config['username'],
    ];
    $headers = ['Accept: application/json'];
    $curl = curl_init($config['endpoint']);
    if ($curl === false) throw new RuntimeException('Could not initialize upload.');
    if ($config['mode'] === 'headers') {
        $headers[] = 'X-API-Key: ' . $config['key'];
        $headers[] = 'X-API-Secret: ' . $config['secret'];
    } else {
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $config['key'] . ':' . $config['secret']);
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => $headers,
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
        if ($body === false) throw new RuntimeException('Transport result is uncertain; verify on HearThis before retrying.');
        if ($http < 200 || $http >= 300) {
            throw new RuntimeException('HearThis HTTP ' . $http . '; verify on HearThis before retrying.');
        }
        $payload = json_decode((string)$body, true);
        if (!is_array($payload)) throw new RuntimeException('Upload response is not JSON; manual verification required.');
        $track = is_array($payload['track'] ?? null) ? $payload['track'] : $payload;
        $url = trim((string)($track['permalink_url'] ?? $track['url'] ?? $track['link'] ?? ''));
        if (!deseo_hearthis_public_url($url)) {
            throw new RuntimeException('Upload response has no verified public HearThis URL; manual verification required.');
        }
        $id = trim((string)($track['id'] ?? $track['track_id'] ?? ''));
        return ['url' => $url, 'id' => substr($id, 0, 120)];
    } finally {
        curl_close($curl);
    }
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
    $cutoff = $now->modify('-6 hours')->format('Y-m-d H:i:s');
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
        if ($end === '' || $end > $nowSql || $end < $cutoff
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
    $summary = ['advanced' => 0, 'uploaded' => 0, 'review_required' => 0, 'disabled' => false, 'retention' => []];
    $lock = $pdo->query("SELECT GET_LOCK('deseo_mylive_hearthis_worker', 0)");
    if (!$lock || (int)$lock->fetchColumn() !== 1) {
        $summary['locked'] = true;
        return $summary;
    }
    try {
        $now = new DateTimeImmutable('now', dj_season_athens_timezone());
        $summary['advanced'] = deseo_hearthis_advance_broadcasts($pdo, $now);
        // A PHP process killed mid-request may have succeeded remotely.
        // Quarantine that attempt rather than uploading the same episode again.
        $pdo->prepare(
            "UPDATE dj_portal_sets SET hearthis_status = 'review_required',
                hearthis_error = 'Interrupted upload: verify the account before retrying'
             WHERE hearthis_status = 'uploading' AND hearthis_started_at < ?"
        )->execute([$now->modify('-2 hours')->format('Y-m-d H:i:s')]);
        $config = deseo_hearthis_upload_config();
        if ($config === null) {
            $summary['disabled'] = true;
            $summary['retention'] = deseo_mylive_cleanup_broadcasted_sets($pdo);
            return $summary;
        }
        $stmt = $pdo->prepare(
            "SELECT s.id, s.account_id, s.episode_no, s.file_path, s.stored_name,
                    s.broadcasted_at, s.scheduled_show_end, a.artist_name
             FROM dj_portal_sets s
             INNER JOIN dj_portal_accounts a ON a.id = s.account_id
             WHERE s.status = 'broadcasted' AND s.hearthis_status = 'pending'
               AND s.file_deleted_at IS NULL AND s.broadcasted_at >= ?
             ORDER BY s.broadcasted_at ASC, s.id ASC LIMIT 100"
        );
        $stmt->execute([dj_season_start_at()->format('Y-m-d H:i:s')]);
        $uploadLimit = max(1, min(5, $limit));
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
            // Do not upload audio without the exact DJ-specific 02 image.
            // Missing/invalid cover is a reversible pending condition; an assigned
            // cover will be picked up automatically on the next cron iteration.
            try {
                $cover = deseo_hearthis_cover($pdo, (int)$set['account_id']);
            } catch (Throwable $coverError) {
                $pdo->prepare(
                    "UPDATE dj_portal_sets SET hearthis_error = ?
                     WHERE id = ? AND hearthis_status = 'pending'"
                )->execute([substr($coverError->getMessage(), 0, 500), $id]);
                $summary['missing_artwork'] = ($summary['missing_artwork'] ?? 0) + 1;
                continue;
            }
            $started = $now->format('Y-m-d H:i:s');
            $claim = $pdo->prepare(
                "UPDATE dj_portal_sets SET hearthis_status = 'uploading',
                    hearthis_cover_asset_id = ?,
                    hearthis_started_at = ?, hearthis_error = '',
                    hearthis_attempts = hearthis_attempts + 1
                 WHERE id = ? AND status = 'broadcasted' AND hearthis_status = 'pending'
                   AND file_deleted_at IS NULL"
            );
            $claim->execute([(int)$cover['asset_id'], $started, $id]);
            if ($claim->rowCount() !== 1) continue;
            $attempted++;
            try {
                $track = deseo_hearthis_upload_track($set, $config, $cover);
                $save = $pdo->prepare(
                    "UPDATE dj_portal_sets SET hearthis_status = 'synced',
                         hearthis_url = ?, hearthis_track_id = ?,
                         hearthis_synced_at = ?, hearthis_error = ''
                     WHERE id = ? AND status = 'broadcasted' AND hearthis_status = 'uploading'
                       AND file_deleted_at IS NULL"
                );
                $save->execute([$track['url'], $track['id'],
                    (new DateTimeImmutable('now', dj_season_athens_timezone()))->format('Y-m-d H:i:s'), $id]);
                if ($save->rowCount() !== 1) {
                    throw new RuntimeException('Database sync state was changed; manual verification required.');
                }
                $summary['uploaded']++;
            } catch (Throwable $error) {
                // Do not log response bodies, auth tokens or private storage paths.
                error_log('HearThis upload needs review for set ' . $id . ': ' . $error->getMessage());
                $pdo->prepare(
                    "UPDATE dj_portal_sets SET hearthis_status = 'review_required', hearthis_error = ?
                     WHERE id = ? AND hearthis_status = 'uploading'"
                )->execute([substr($error->getMessage(), 0, 500), $id]);
                $summary['review_required']++;
            }
        }
        $summary['retention'] = deseo_mylive_cleanup_broadcasted_sets($pdo);
        return $summary;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('deseo_mylive_hearthis_worker')");
    }
}
