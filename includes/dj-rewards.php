<?php
declare(strict_types=1);

function deseo_rewards_bootstrap(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_rewards (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT NULL,
        dj_name VARCHAR(180) NOT NULL DEFAULT '',
        business_name VARCHAR(180) NOT NULL,
        contact_name VARCHAR(180) NOT NULL DEFAULT '',
        contact_email VARCHAR(254) NOT NULL DEFAULT '',
        contact_phone VARCHAR(80) NOT NULL DEFAULT '',
        campaign_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reward_percent DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        reward_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(32) NOT NULL DEFAULT 'new',
        dj_note TEXT NOT NULL,
        internal_note TEXT NOT NULL,
        referred_at DATE NULL,
        paid_at DATETIME NULL,
        notification_email_sent_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_rewards_account (account_id, created_at),
        KEY idx_rewards_status (status),
        CONSTRAINT fk_rewards_account FOREIGN KEY (account_id) REFERENCES dj_portal_accounts(id)
            ON UPDATE CASCADE ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $emailColumn = $pdo->query("SHOW COLUMNS FROM dj_rewards LIKE 'notification_email_sent_at'");
        if (!$emailColumn || !$emailColumn->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE dj_rewards ADD COLUMN notification_email_sent_at DATETIME NULL AFTER paid_at");
        }
    } catch (Throwable $e) {
        error_log('Rewards notification email column migration failed: ' . $e->getMessage());
    }
}

function deseo_rewards_statuses(): array {
    return [
        'new' => 'New',
        'in_discussion' => 'In Discussion',
        'confirmed' => 'Confirmed',
        'reward_ready' => 'Reward Ready',
        'paid' => 'Paid',
        'closed' => 'Closed',
    ];
}

function deseo_rewards_status_label(string $status): string {
    $statuses = deseo_rewards_statuses();
    return $statuses[$status] ?? 'New';
}

function deseo_rewards_status_class(string $status): string {
    return match ($status) {
        'new' => 'is-new',
        'in_discussion' => 'is-discussion',
        'confirmed' => 'is-confirmed',
        'reward_ready' => 'is-ready',
        'paid' => 'is-paid',
        'closed' => 'is-closed',
        default => 'is-new',
    };
}

function deseo_rewards_money(float $amount): string {
    return '€' . number_format($amount, 2, ',', '.');
}

function deseo_rewards_for_account(PDO $pdo, int $accountId): array {
    $stmt = $pdo->prepare(
        "SELECT id, account_id, dj_name, business_name, contact_name, contact_email, contact_phone,
                campaign_value, reward_percent, reward_amount, status, dj_note, referred_at,
                paid_at, created_at, updated_at
         FROM dj_rewards
         WHERE account_id = ?
         ORDER BY COALESCE(referred_at, DATE(created_at)) DESC, id DESC"
    );
    $stmt->execute([$accountId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function deseo_rewards_summary(array $rewards): array {
    $pending = 0.0;
    $paid = 0.0;
    $confirmed = 0;

    foreach ($rewards as $reward) {
        $status = (string)($reward['status'] ?? 'new');
        $amount = (float)($reward['reward_amount'] ?? 0);

        if (in_array($status, ['confirmed', 'reward_ready'], true)) {
            $pending += $amount;
        }
        if ($status === 'paid') {
            $paid += $amount;
        }
        if (in_array($status, ['confirmed', 'reward_ready', 'paid'], true)) {
            $confirmed++;
        }
    }

    return [
        'total' => count($rewards),
        'confirmed' => $confirmed,
        'pending' => $pending,
        'paid' => $paid,
    ];
}
