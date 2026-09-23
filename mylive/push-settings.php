<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/mylive-push.php';

deseo_mylive_session_start();
deseo_mylive_bootstrap($pdo);
deseo_mylive_push_bootstrap($pdo);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, no-cache, must-revalidate', true);
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

if (!deseo_mylive_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session expired.']);
    exit;
}

$accountId = deseo_mylive_account_id();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok' => true,
        'configured' => deseo_mylive_push_configured(),
        'enabled' => deseo_mylive_push_account_enabled($pdo, $accountId),
        'subscriptions' => deseo_mylive_push_subscription_count($pdo, $accountId),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!deseo_mylive_verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Session expired.']);
    exit;
}

$enabled = (string)($_POST['enabled'] ?? '0') === '1';
$sid = trim((string)($_POST['subscriber_id'] ?? ''));

try {
    $result = deseo_mylive_push_sync_subscription(
        $pdo,
        $accountId,
        $sid,
        $enabled,
        (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
    );

    echo json_encode([
        'ok' => true,
        'enabled' => (bool)$result['enabled'],
        'subscriptions' => (int)$result['subscriptions'],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
