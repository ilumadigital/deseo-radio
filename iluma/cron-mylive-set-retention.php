<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Athens');

require_once __DIR__ . '/../includes/dj-portal.php';

try {
    deseo_mylive_bootstrap($pdo);
    $result = deseo_mylive_cleanup_broadcasted_sets($pdo);

    echo '[' . date('Y-m-d H:i:s') . '] '
        . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    error_log('MyLive set retention cron failed: ' . $e->getMessage());
    fwrite(
        STDERR,
        '[' . date('Y-m-d H:i:s') . '] MyLive set retention cron failed: '
        . $e->getMessage()
        . PHP_EOL
    );
    exit(1);
}
