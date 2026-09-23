<?php
declare(strict_types=1);

require_once __DIR__ . '/dj-portal.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mylive-push.php';

function deseo_mylive_communications_bootstrap(PDO $pdo): void {
    deseo_mylive_push_bootstrap($pdo);

    $columns = [
        'email_notifications_enabled' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER public_profile_enabled",
        'notify_set_reminder_email' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER email_notifications_enabled",
        'notify_set_reminder_push' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_set_reminder_email",
        'notify_on_air_email' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_set_reminder_push",
        'notify_on_air_push' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_on_air_email",
        'notify_announcements_email' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_on_air_push",
        'notify_announcements_push' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_announcements_email",
    ];

    foreach ($columns as $name => $definition) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM dj_portal_accounts LIKE ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE dj_portal_accounts ADD COLUMN " . $name . " " . $definition);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dj_communication_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT NULL,
            channel VARCHAR(16) NOT NULL,
            kind VARCHAR(32) NOT NULL DEFAULT 'announcement',
            subject VARCHAR(190) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            target_url VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'sent',
            last_error VARCHAR(500) NOT NULL DEFAULT '',
            sent_by VARCHAR(254) NOT NULL DEFAULT '',
            sent_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_comm_account (account_id, created_at),
            KEY idx_comm_channel (channel, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function deseo_mylive_communication_preferences(PDO $pdo, int $accountId): array {
    deseo_mylive_communications_bootstrap($pdo);

    $stmt = $pdo->prepare(
        "SELECT
            email_notifications_enabled,
            push_notifications_enabled,
            notify_set_reminder_email,
            notify_set_reminder_push,
            notify_on_air_email,
            notify_on_air_push,
            notify_announcements_email,
            notify_announcements_push
         FROM dj_portal_accounts
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'email_enabled' => !isset($row['email_notifications_enabled']) || (int)$row['email_notifications_enabled'] === 1,
        'push_enabled' => (int)($row['push_notifications_enabled'] ?? 0) === 1,
        'set_reminder_email' => !isset($row['notify_set_reminder_email']) || (int)$row['notify_set_reminder_email'] === 1,
        'set_reminder_push' => !isset($row['notify_set_reminder_push']) || (int)$row['notify_set_reminder_push'] === 1,
        'on_air_email' => !isset($row['notify_on_air_email']) || (int)$row['notify_on_air_email'] === 1,
        'on_air_push' => !isset($row['notify_on_air_push']) || (int)$row['notify_on_air_push'] === 1,
        'announcements_email' => !isset($row['notify_announcements_email']) || (int)$row['notify_announcements_email'] === 1,
        'announcements_push' => !isset($row['notify_announcements_push']) || (int)$row['notify_announcements_push'] === 1,
    ];
}

function deseo_mylive_save_communication_preferences(PDO $pdo, int $accountId, array $values): array {
    deseo_mylive_communications_bootstrap($pdo);

    $bool = static fn(string $key, bool $default = false): int =>
        array_key_exists($key, $values) ? (!empty($values[$key]) ? 1 : 0) : ($default ? 1 : 0);

    $stmt = $pdo->prepare(
        "UPDATE dj_portal_accounts
         SET email_notifications_enabled = ?,
             push_notifications_enabled = ?,
             notify_set_reminder_email = ?,
             notify_set_reminder_push = ?,
             notify_on_air_email = ?,
             notify_on_air_push = ?,
             notify_announcements_email = ?,
             notify_announcements_push = ?
         WHERE id = ?"
    );
    $stmt->execute([
        $bool('email_enabled', true),
        $bool('push_enabled', false),
        $bool('set_reminder_email', true),
        $bool('set_reminder_push', true),
        $bool('on_air_email', true),
        $bool('on_air_push', true),
        $bool('announcements_email', true),
        $bool('announcements_push', true),
        $accountId,
    ]);

    return deseo_mylive_communication_preferences($pdo, $accountId);
}

function deseo_mylive_communication_allows(PDO $pdo, int $accountId, string $kind, string $channel): bool {
    $prefs = deseo_mylive_communication_preferences($pdo, $accountId);
    $channel = strtolower(trim($channel));
    $kind = strtolower(trim($kind));

    if ($channel === 'email' && empty($prefs['email_enabled'])) return false;
    if ($channel === 'push' && empty($prefs['push_enabled'])) return false;

    $map = [
        'set_reminder' => [
            'email' => 'set_reminder_email',
            'push' => 'set_reminder_push',
        ],
        'on_air' => [
            'email' => 'on_air_email',
            'push' => 'on_air_push',
        ],
        'announcement' => [
            'email' => 'announcements_email',
            'push' => 'announcements_push',
        ],
    ];

    $key = $map[$kind][$channel] ?? null;
    return $key !== null && !empty($prefs[$key]);
}

function deseo_mylive_communication_accounts(PDO $pdo): array {
    deseo_mylive_communications_bootstrap($pdo);

    $stmt = $pdo->query(
        "SELECT a.id, a.artist_name, a.full_name, a.email, a.account_status, a.is_active,
                a.day_of_week, a.start_time, a.end_time,
                a.email_notifications_enabled, a.push_notifications_enabled,
                a.notify_set_reminder_email, a.notify_set_reminder_push,
                a.notify_on_air_email, a.notify_on_air_push,
                a.notify_announcements_email, a.notify_announcements_push,
                (
                    SELECT COUNT(*)
                    FROM dj_push_subscriptions s
                    WHERE s.account_id = a.id AND s.is_active = 1
                ) AS push_devices
         FROM dj_portal_accounts a
         ORDER BY a.artist_name ASC, a.id ASC"
    );

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function deseo_mylive_manual_email(array $account, string $subject, string $message, string $ctaLabel = '', string $ctaUrl = ''): array {
    $artist = trim((string)($account['artist_name'] ?? 'DJ'));
    $subject = trim($subject);
    $message = trim($message);

    if ($subject === '') {
        $subject = 'Deseo Radio · MyLive Update';
    }
    if ($message === '') {
        throw new RuntimeException('Το μήνυμα email δεν μπορεί να είναι κενό.');
    }

    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $body = deseo_mylive_email_section(
        'MESSAGE FROM DESEO RADIO',
        'Ενημέρωση από την ομάδα του Deseo.',
        $safeMessage
    );

    $html = deseo_mylive_email_shell(
        'DESEO RADIO · MYLIVE',
        $subject,
        $artist . ', έχεις νέα ενημέρωση από την ομάδα του Deseo Radio.',
        $body,
        trim($ctaLabel),
        trim($ctaUrl)
    );

    $text = "DESEO RADIO · MYLIVE\n\n"
        . $artist . ", έχεις νέα ενημέρωση από την ομάδα του Deseo Radio.\n\n"
        . $message
        . (trim($ctaUrl) !== '' ? "\n\n" . trim($ctaUrl) : '')
        . "\n\nDeseo Radio · Powered by ILUMA Digital Agency";

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}

function deseo_mylive_communication_log(
    PDO $pdo,
    ?int $accountId,
    string $channel,
    string $kind,
    string $subject,
    string $message,
    string $targetUrl,
    bool $success,
    string $sentBy = '',
    string $error = ''
): void {
    deseo_mylive_communications_bootstrap($pdo);

    $stmt = $pdo->prepare(
        "INSERT INTO dj_communication_log
         (account_id, channel, kind, subject, message, target_url, status, last_error, sent_by, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $accountId,
        substr($channel, 0, 16),
        substr($kind, 0, 32),
        substr($subject, 0, 190),
        $message,
        substr($targetUrl, 0, 500),
        $success ? 'sent' : 'failed',
        substr($error, 0, 500),
        substr($sentBy, 0, 254),
        $success ? date('Y-m-d H:i:s') : null,
    ]);
}
