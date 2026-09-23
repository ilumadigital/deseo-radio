<?php
declare(strict_types=1);

require_once __DIR__ . '/../iluma/connection.php';
require_once __DIR__ . '/../includes/mylive-email-reminders.php';
require_once __DIR__ . '/../includes/mylive-push-reminders.php';

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate', true);
    header('Cache-Control: no-store, no-cache, must-revalidate', true);
}

deseo_mylive_maybe_run_email_scheduler($pdo);
deseo_mylive_maybe_run_push_scheduler($pdo);

http_response_code(204);
