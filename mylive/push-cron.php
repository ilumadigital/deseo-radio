<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/mylive-push.php';

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate', true);
    header('Cache-Control: no-store, no-cache, must-revalidate', true);
}

if (PHP_SAPI !== 'cli') {
    $expected = deseo_mylive_push_cron_token();
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $provided = trim((string)($_GET['token'] ?? ''));

    if ($provided === '' && stripos($authorization, 'Bearer ') === 0) {
        $provided = trim(substr($authorization, 7));
    }

    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    deseo_mylive_bootstrap($pdo);
    $summary = deseo_mylive_run_scheduled_pushes($pdo);

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode(
        ['ok' => true, 'summary' => $summary],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $e) {
    error_log('MyLive push cron failed: ' . $e->getMessage());

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode(
        ['ok' => false, 'error' => 'MyLive push cron failed.'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;

    exit(1);
}
