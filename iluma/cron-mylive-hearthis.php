<?php
declare(strict_types=1);

// Hostinger cron: run with CLI PHP once per minute, never as a public URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
date_default_timezone_set('Europe/Athens');
require_once __DIR__ . '/../includes/mylive-hearthis.php';

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
