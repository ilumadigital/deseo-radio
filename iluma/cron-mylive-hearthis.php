<?php
declare(strict_types=1);

// Hostinger cron: use CLI PHP once per minute; never expose as a public URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
date_default_timezone_set('Europe/Athens');
require_once __DIR__ . '/../includes/mylive-hearthis.php';

$mode = $argv[1] ?? '--run'; // Preserve existing installed no-argument cron.
if (count($argv) > 2 || !in_array($mode, ['--check', '--run'], true)) {
    fwrite(STDERR, "Usage: php iluma/cron-mylive-hearthis.php --check | --run\n");
    exit(2);
}

if ($mode === '--check') {
    // This path deliberately NEVER calls bootstrap(), advance_broadcasts(),
    // cleanup(), upload_config(), any HearThis API, or the worker. SQL is
    // SELECT/SHOW only, so it is safe to run against production while OFF.
    try {
        $now = new DateTimeImmutable('now', dj_season_athens_timezone());
        $colsStmt = $pdo->query('SHOW COLUMNS FROM dj_portal_sets');
        $columns = [];
        foreach ($colsStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $columns[(string)$col['Field']] = true;
        }
        $required = [
            'id', 'account_id', 'episode_no', 'status', 'scheduled_show_end',
            'file_deleted_at', 'hearthis_status', 'hearthis_url',
            'hearthis_track_id', 'hearthis_source_sha256',
            'hearthis_upload_accepted_at', 'hearthis_set_status',
            'hearthis_podcast_status', 'hearthis_description',
            'hearthis_genre', 'hearthis_tags',
        ];
        $missing = array_values(array_filter(
            $required, static fn(string $column): bool => !isset($columns[$column])
        ));
        echo 'Mode: READ-ONLY PRECHECK (no DB writes, status updates, upload or deletion)' . PHP_EOL;
        echo 'Athens now: ' . $now->format('Y-m-d H:i:s P') . PHP_EOL;
        echo 'Season 6 starts: ' . dj_season_start_at()->format('Y-m-d H:i:s P') . PHP_EOL;
        echo 'HearThis worker switch: ' . (getenv('HEARTHIS_UPLOAD_ENABLED') === '1' ? 'ON' : 'OFF') . PHP_EOL;
        echo 'PHP cURL / CURLFile / SimpleXML: '
            . (function_exists('curl_init') ? 'YES' : 'NO') . ' / '
            . (class_exists('CURLFile') ? 'YES' : 'NO') . ' / '
            . (function_exists('simplexml_load_string') ? 'YES' : 'NO') . PHP_EOL;
        echo 'Required MyLive columns: ' . ($missing ? 'MISSING: ' . implode(', ', $missing) : 'OK') . PHP_EOL;
        if ($missing) exit(1);

        $counts = $pdo->query(
            "SELECT status, COUNT(*) AS total FROM dj_portal_sets GROUP BY status ORDER BY status"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($counts as $row) {
            echo 'DJ Sets ' . (string)$row['status'] . ': ' . (int)$row['total'] . PHP_EOL;
        }
        $dates = $pdo->query(
            "SELECT
                SUM(CASE WHEN status = 'scheduled' AND scheduled_show_end IS NULL THEN 1 ELSE 0 END) AS scheduled_without_end,
                SUM(CASE WHEN status = 'scheduled' AND scheduled_show_end IS NOT NULL THEN 1 ELSE 0 END) AS scheduled_with_end,
                MIN(CASE WHEN status = 'scheduled' THEN scheduled_show_end END) AS earliest_scheduled_end,
                SUM(CASE WHEN hearthis_status = 'uploading' THEN 1 ELSE 0 END) AS uploading,
                SUM(CASE WHEN hearthis_status = 'review_required' THEN 1 ELSE 0 END) AS review_required
             FROM dj_portal_sets"
        )->fetch(PDO::FETCH_ASSOC);
        echo 'SCHEDULED with Show Ends: ' . (int)($dates['scheduled_with_end'] ?? 0) . PHP_EOL;
        echo 'SCHEDULED missing Show Ends: ' . (int)($dates['scheduled_without_end'] ?? 0) . PHP_EOL;
        echo 'Earliest scheduled Show Ends (Athens): '
            . ((string)($dates['earliest_scheduled_end'] ?? '') ?: 'none') . PHP_EOL;
        echo 'HearThis UPLOADING / REVIEW REQUIRED: '
            . (int)($dates['uploading'] ?? 0) . ' / ' . (int)($dates['review_required'] ?? 0) . PHP_EOL;
        // Pure SELECT and local stat checks, no mutations and no account secrets.
        $future = $pdo->prepare(
            "SELECT s.file_path, s.scheduled_show_end, s.status
             FROM dj_portal_sets s
             WHERE s.status = 'scheduled' AND s.file_deleted_at IS NULL"
        );
        $future->execute();
        $unavailableFiles = 0;
        $elapsedSlots = 0;
        foreach ($future->fetchAll(PDO::FETCH_ASSOC) as $scheduled) {
            $audio = deseo_mylive_set_storage_file((string)$scheduled['file_path']);
            if (!$audio['exists'] || $audio['storage_root'] === ''
                || !str_starts_with((string)$audio['real_file'], (string)$audio['storage_root'] . DIRECTORY_SEPARATOR)) {
                $unavailableFiles++;
            }
            if (!empty($scheduled['scheduled_show_end'])
                && (string)$scheduled['scheduled_show_end'] <= $now->format('Y-m-d H:i:s')
                && (string)$scheduled['scheduled_show_end'] >= dj_season_start_at()->format('Y-m-d H:i:s')) {
                $elapsedSlots++;
            }
        }
        $eligibleStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM dj_portal_sets
             WHERE status = 'broadcasted' AND hearthis_status = 'pending'
               AND file_deleted_at IS NULL AND scheduled_show_end IS NOT NULL
               AND scheduled_show_end <= ? AND broadcasted_at >= ?"
        );
        $eligibleStmt->execute([
            $now->format('Y-m-d H:i:s'), dj_season_start_at()->format('Y-m-d H:i:s')
        ]);
        echo 'SCHEDULED slots already elapsed: ' . $elapsedSlots . PHP_EOL;
        echo 'SCHEDULED original MP3 files unavailable: ' . $unavailableFiles . PHP_EOL;
        echo 'Already BROADCASTED eligible pending uploads: ' . (int)$eligibleStmt->fetchColumn() . PHP_EOL;
        echo 'CHECK COMPLETE: No production changes made.' . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        error_log('HearThis cron precheck failed: ' . $e->getMessage());
        fwrite(STDERR, "CHECK FAILED: database/schema/environment unavailable. No changes made; inspect PHP error log.\n");
        exit(1);
    }
}

try {
    $result = deseo_hearthis_run($pdo);
    echo '[' . date('Y-m-d H:i:s') . '] '
        . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . PHP_EOL;
    exit(empty($result['review_required']) ? 0 : 2);
} catch (Throwable $e) {
    error_log('HearThis cron failed: ' . $e->getMessage());
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] HearThis cron failed. Check server logs.' . PHP_EOL);
    exit(1);
}
