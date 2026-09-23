<?php
declare(strict_types=1);

/**
 * Webpushr transport for the Deseo Radio MyLive app.
 *
 * REST credentials are loaded from the shared .env file via includes/env.php.
 * Never expose WEBPUSHR_AUTH_TOKEN to browser-side code.
 */

function deseo_mylive_push_bootstrap(PDO $pdo): void {
    $columnStmt = $pdo->prepare("SHOW COLUMNS FROM dj_portal_accounts LIKE ?");
    $columnStmt->execute(['push_notifications_enabled']);
    if (!$columnStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec(
            "ALTER TABLE dj_portal_accounts
             ADD COLUMN push_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0
             AFTER public_profile_enabled"
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dj_push_subscriptions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT NOT NULL,
            subscriber_id VARCHAR(190) NOT NULL,
            user_agent_hash CHAR(64) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_seen_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_push_subscriber (subscriber_id),
            KEY idx_push_account_active (account_id, is_active),
            CONSTRAINT fk_push_account FOREIGN KEY (account_id) REFERENCES dj_portal_accounts(id)
                ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    deseo_mylive_push_automation_bootstrap($pdo);
}

function deseo_mylive_push_configured(): bool {
    return trim((string)(getenv('WEBPUSHR_KEY') ?: '')) !== ''
        && trim((string)(getenv('WEBPUSHR_AUTH_TOKEN') ?: '')) !== '';
}

function deseo_mylive_push_clip(string $value, int $maxLength): string {
    $value = trim($value);
    if ($value === '') return '';

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return substr($value, 0, $maxLength);
}

function deseo_mylive_push_request(string $endpoint, array $payload): array {
    $key = trim((string)(getenv('WEBPUSHR_KEY') ?: ''));
    $token = trim((string)(getenv('WEBPUSHR_AUTH_TOKEN') ?: ''));

    if ($key === '' || $token === '') {
        throw new RuntimeException('Webpushr API credentials are not configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for Webpushr.');
    }

    $url = 'https://api.webpushr.com/v1/' . ltrim($endpoint, '/');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode Webpushr request.');
    }

    // Webpushr currently limits REST calls to one request per second.
    static $lastRequestAt = 0.0;
    $elapsed = microtime(true) - $lastRequestAt;
    if ($lastRequestAt > 0 && $elapsed < 1.05) {
        usleep((int)((1.05 - $elapsed) * 1000000));
    }

    $attempt = 0;
    do {
        $attempt++;
        $lastRequestAt = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'webpushrKey: ' . $key,
                'webpushrAuthToken: ' . $token,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Webpushr request failed: ' . ($curlError !== '' ? $curlError : 'unknown cURL error'));
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Webpushr returned an invalid response.');
        }

        $isRateLimited = ($decoded['type'] ?? '') === 'rate_limit' || $statusCode === 429;
        if ($isRateLimited && $attempt < 2) {
            usleep(1100000);
            continue;
        }

        if ($statusCode < 200 || $statusCode >= 300 || ($decoded['status'] ?? '') !== 'success') {
            $description = trim((string)($decoded['description'] ?? 'Webpushr API request failed.'));
            throw new RuntimeException($description !== '' ? $description : 'Webpushr API request failed.');
        }

        return $decoded;
    } while ($attempt < 2);

    throw new RuntimeException('Webpushr API request failed.');
}

function deseo_mylive_push_subscription_count(PDO $pdo, int $accountId): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM dj_push_subscriptions
         WHERE account_id = ? AND is_active = 1"
    );
    $stmt->execute([$accountId]);
    return max(0, (int)$stmt->fetchColumn());
}

function deseo_mylive_push_account_enabled(PDO $pdo, int $accountId): bool {
    $stmt = $pdo->prepare(
        "SELECT push_notifications_enabled
         FROM dj_portal_accounts
         WHERE id = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->execute([$accountId]);
    return (int)$stmt->fetchColumn() === 1;
}

function deseo_mylive_push_sync_subscription(
    PDO $pdo,
    int $accountId,
    string $subscriberId,
    bool $enabled,
    string $userAgent = ''
): array {
    if ($accountId < 1) {
        throw new RuntimeException('Invalid MyLive account.');
    }

    $subscriberId = trim($subscriberId);
    if ($enabled && ($subscriberId === '' || strlen($subscriberId) > 190)) {
        throw new RuntimeException('The browser push subscription is not ready yet.');
    }

    $pdo->beginTransaction();
    try {
        $accountStmt = $pdo->prepare(
            "SELECT id
             FROM dj_portal_accounts
             WHERE id = ? AND is_active = 1 AND account_status = 'active'
             FOR UPDATE"
        );
        $accountStmt->execute([$accountId]);
        if (!$accountStmt->fetchColumn()) {
            throw new RuntimeException('The MyLive account is not active.');
        }

        if ($enabled) {
            $uaHash = $userAgent !== '' ? hash('sha256', $userAgent) : '';

            $stmt = $pdo->prepare(
                "INSERT INTO dj_push_subscriptions
                 (account_id, subscriber_id, user_agent_hash, is_active, last_seen_at)
                 VALUES (?, ?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE
                    account_id = VALUES(account_id),
                    user_agent_hash = VALUES(user_agent_hash),
                    is_active = 1,
                    last_seen_at = NOW()"
            );
            $stmt->execute([$accountId, $subscriberId, $uaHash]);

            $pdo->prepare(
                "UPDATE dj_portal_accounts
                 SET push_notifications_enabled = 1
                 WHERE id = ?"
            )->execute([$accountId]);
        } else {
            $pdo->prepare(
                "UPDATE dj_portal_accounts
                 SET push_notifications_enabled = 0
                 WHERE id = ?"
            )->execute([$accountId]);

            $pdo->prepare(
                "UPDATE dj_push_subscriptions
                 SET is_active = 0, last_seen_at = NOW()
                 WHERE account_id = ?"
            )->execute([$accountId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'enabled' => $enabled,
        'subscriptions' => deseo_mylive_push_subscription_count($pdo, $accountId),
    ];
}

function deseo_mylive_push_send_to_account(
    PDO $pdo,
    int $accountId,
    string $title,
    string $message,
    string $targetUrl,
    array $options = []
): array {
    if (!deseo_mylive_push_configured()) {
        throw new RuntimeException('Webpushr API credentials are not configured.');
    }
    if (!deseo_mylive_push_account_enabled($pdo, $accountId)) {
        throw new RuntimeException('Push notifications are disabled for this DJ.');
    }

    $payload = [
        'title' => deseo_mylive_push_clip($title, 100),
        'message' => deseo_mylive_push_clip($message, 255),
        'target_url' => deseo_mylive_push_clip($targetUrl, 255),
        'attribute' => [
            'mylive_user_id' => (string)$accountId,
        ],
        'name' => deseo_mylive_push_clip((string)($options['name'] ?? 'Deseo Radio · MyLive'), 100),
        'auto_hide' => isset($options['auto_hide']) ? ((int)$options['auto_hide'] === 0 ? 0 : 1) : 1,
    ];

    if (!empty($options['expire_push'])) {
        $payload['expire_push'] = (string)$options['expire_push'];
    }
    if (!empty($options['icon'])) {
        $payload['icon'] = (string)$options['icon'];
    }
    if (!empty($options['action_buttons']) && is_array($options['action_buttons'])) {
        $payload['action_buttons'] = $options['action_buttons'];
    }

    return deseo_mylive_push_request('notification/send/attribute', $payload);
}

function deseo_mylive_push_automation_bootstrap(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dj_push_automation_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT NOT NULL,
            program_id INT NOT NULL,
            episode_no INT NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            event_key VARCHAR(190) NOT NULL,
            show_start DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            last_error VARCHAR(500) NOT NULL DEFAULT '',
            campaign_id VARCHAR(64) NOT NULL DEFAULT '',
            sent_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_dj_push_event (event_key),
            KEY idx_dj_push_account (account_id, show_start),
            KEY idx_dj_push_status (status, show_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function deseo_mylive_push_event_sent(PDO $pdo, string $eventKey): bool {
    $stmt = $pdo->prepare(
        "SELECT sent_at
         FROM dj_push_automation_log
         WHERE event_key = ?
         LIMIT 1"
    );
    $stmt->execute([$eventKey]);
    $sentAt = $stmt->fetchColumn();

    return is_string($sentAt) && trim($sentAt) !== '';
}

function deseo_mylive_push_event_log(
    PDO $pdo,
    int $accountId,
    int $programId,
    int $episodeNo,
    string $eventType,
    string $eventKey,
    DateTimeImmutable $showStart,
    bool $success,
    string $error = '',
    string $campaignId = ''
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO dj_push_automation_log
         (account_id, program_id, episode_no, event_type, event_key, show_start, status, last_error, campaign_id, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            last_error = VALUES(last_error),
            campaign_id = CASE
                WHEN VALUES(campaign_id) <> '' THEN VALUES(campaign_id)
                ELSE campaign_id
            END,
            sent_at = CASE
                WHEN VALUES(sent_at) IS NOT NULL THEN VALUES(sent_at)
                ELSE sent_at
            END"
    );

    $sentAt = $success
        ? (new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->format('Y-m-d H:i:s')
        : null;

    $stmt->execute([
        $accountId,
        $programId,
        $episodeNo,
        $eventType,
        $eventKey,
        $showStart->format('Y-m-d H:i:s'),
        $success ? 'sent' : 'failed',
        substr($error, 0, 500),
        substr($campaignId, 0, 64),
        $sentAt,
    ]);
}
