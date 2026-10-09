<?php
declare(strict_types=1);

/* A dedicated subprocess-style smoke script. The API exits intentionally;
   the shutdown verifier inspects buffered JSON after that exit. */
$_GET = ['program_feed' => '1'];
$_COOKIE = [];
$_SERVER['REQUEST_URI'] = '/?program_feed=1';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$deseo_launch_disable_jobs = true;

ob_start();
register_shutdown_function(static function (): void {
    $payload = (string)ob_get_contents();
    ob_end_clean();
    $data = json_decode($payload, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, "Launch legacy program feed did not return valid JSON\n");
        exit(1);
    }
    foreach (['ok', 'generated_at', 'live', 'next', 'today'] as $field) {
        if (!array_key_exists($field, $data)) {
            fwrite(STDERR, "Launch legacy program feed missing {$field}\n");
            exit(1);
        }
    }
    if (!is_array($data['today'])) {
        fwrite(STDERR, "Launch legacy program feed today must be an array\n");
        exit(1);
    }
    echo "Launch legacy /?program_feed=1: valid JSON contract OK\n";
});
require __DIR__ . '/../index-launch.php';
