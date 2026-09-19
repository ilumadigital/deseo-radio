<?php
declare(strict_types=1);

require_once __DIR__ . '/../iluma/connection.php';
require_once __DIR__ . '/dj-season.php';

const DESEO_MYLive_MAX_BYTES = 1073741824; // 1 GB
const DESEO_MYLive_ASSET_MAX_BYTES = 268435456; // 256 MB

function deseo_mylive_column_exists(PDO $pdo, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: '';
    $stmt = $pdo->prepare("SHOW COLUMNS FROM " . $table . " LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function deseo_mylive_bootstrap(PDO $pdo): void {
    dj_season_bootstrap($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_portal_accounts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        booking_id BIGINT NULL,
        artist_name VARCHAR(180) NOT NULL DEFAULT '',
        full_name VARCHAR(180) NOT NULL DEFAULT '',
        email VARCHAR(254) NOT NULL,
        day_of_week TINYINT NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        password_hash VARCHAR(255) NOT NULL,
        must_change_password TINYINT(1) NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        onboarding_email_sent_at DATETIME NULL,
        access_email_sent_at DATETIME NULL,
        last_login_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_portal_booking (booking_id),
        UNIQUE KEY uniq_portal_email (email),
        CONSTRAINT fk_portal_booking FOREIGN KEY (booking_id) REFERENCES dj_season_bookings(id)
            ON UPDATE CASCADE ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'artist_name' => "VARCHAR(180) NOT NULL DEFAULT '' AFTER booking_id",
        'full_name' => "VARCHAR(180) NOT NULL DEFAULT '' AFTER artist_name",
        'day_of_week' => "TINYINT NULL AFTER email",
        'start_time' => "TIME NULL AFTER day_of_week",
        'end_time' => "TIME NULL AFTER start_time",
        'must_change_password' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash",
        'onboarding_email_sent_at' => "DATETIME NULL AFTER is_active",
        'access_email_sent_at' => "DATETIME NULL AFTER onboarding_email_sent_at"
    ];
    foreach ($columns as $name => $definition) {
        if (!deseo_mylive_column_exists($pdo, 'dj_portal_accounts', $name)) {
            $pdo->exec("ALTER TABLE dj_portal_accounts ADD COLUMN " . $name . " " . $definition);
        }
    }

    try {
        $pdo->exec("ALTER TABLE dj_portal_accounts MODIFY booking_id BIGINT NULL");

        $ruleStmt = $pdo->query(
            "SELECT DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'dj_portal_accounts'
               AND CONSTRAINT_NAME = 'fk_portal_booking'
             LIMIT 1"
        );
        $deleteRule = $ruleStmt ? strtoupper((string)$ruleStmt->fetchColumn()) : '';
        if ($deleteRule !== '' && $deleteRule !== 'SET NULL') {
            $pdo->exec("ALTER TABLE dj_portal_accounts DROP FOREIGN KEY fk_portal_booking");
            $pdo->exec(
                "ALTER TABLE dj_portal_accounts
                 ADD CONSTRAINT fk_portal_booking
                 FOREIGN KEY (booking_id) REFERENCES dj_season_bookings(id)
                 ON UPDATE CASCADE ON DELETE SET NULL"
            );
        }
    } catch (Throwable $e) {
        error_log('MyLive booking link migration: ' . $e->getMessage());
    }

    try {
        $pdo->exec(
            "UPDATE dj_portal_accounts a
             INNER JOIN dj_season_bookings b ON b.id = a.booking_id
             INNER JOIN dj_season_slots s ON s.id = b.slot_id
             SET a.artist_name = CASE WHEN a.artist_name = '' THEN b.artist_name ELSE a.artist_name END,
                 a.full_name = CASE WHEN a.full_name = '' THEN b.full_name ELSE a.full_name END,
                 a.day_of_week = COALESCE(a.day_of_week, b.final_day_of_week, s.day_of_week),
                 a.start_time = COALESCE(a.start_time, b.final_start_time, s.start_time),
                 a.end_time = COALESCE(a.end_time, b.final_end_time, s.end_time)"
        );
    } catch (Throwable $e) {
        error_log('MyLive profile sync: ' . $e->getMessage());
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_portal_sets (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT NOT NULL,
        episode_no INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size BIGINT NOT NULL DEFAULT 0,
        mime_type VARCHAR(100) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'received',
        admin_note VARCHAR(500) NOT NULL DEFAULT '',
        uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_portal_episode (account_id, episode_no),
        KEY idx_portal_sets_account (account_id, uploaded_at),
        CONSTRAINT fk_portal_sets_account FOREIGN KEY (account_id) REFERENCES dj_portal_accounts(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_portal_assets (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT NOT NULL,
        asset_type VARCHAR(32) NOT NULL DEFAULT 'other',
        title VARCHAR(180) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size BIGINT NOT NULL DEFAULT 0,
        mime_type VARCHAR(100) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_portal_assets_account (account_id, created_at),
        CONSTRAINT fk_portal_assets_account FOREIGN KEY (account_id) REFERENCES dj_portal_accounts(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function deseo_mylive_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/mylive',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

function deseo_mylive_csrf(): string {
    deseo_mylive_session_start();
    if (empty($_SESSION['mylive_csrf'])) {
        $_SESSION['mylive_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['mylive_csrf'];
}

function deseo_mylive_verify_csrf(?string $token): bool {
    deseo_mylive_session_start();
    return is_string($token)
        && isset($_SESSION['mylive_csrf'])
        && hash_equals((string)$_SESSION['mylive_csrf'], $token);
}

function deseo_mylive_account_id(): int {
    deseo_mylive_session_start();
    return (int)($_SESSION['mylive_account_id'] ?? 0);
}

function deseo_mylive_logged_in(): bool {
    return deseo_mylive_account_id() > 0;
}

function deseo_mylive_e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function deseo_mylive_slug(string $value): string {
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');
    return $value !== '' ? substr($value, 0, 80) : 'DJ';
}

function deseo_mylive_next_episode(PDO $pdo, int $accountId): int {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(episode_no), 0) + 1 FROM dj_portal_sets WHERE account_id = ?");
    $stmt->execute([$accountId]);
    return max(1, (int)$stmt->fetchColumn());
}

function deseo_mylive_account(PDO $pdo, int $accountId): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, booking_id, artist_name, full_name, email, day_of_week, start_time, end_time,
                must_change_password, is_active, onboarding_email_sent_at, access_email_sent_at,
                last_login_at, created_at, updated_at
         FROM dj_portal_accounts
         WHERE id = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->execute([$accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function deseo_mylive_sets(PDO $pdo, int $accountId): array {
    $stmt = $pdo->prepare(
        "SELECT id, episode_no, original_name, stored_name, file_size, mime_type, status, admin_note, uploaded_at
         FROM dj_portal_sets
         WHERE account_id = ?
         ORDER BY episode_no DESC"
    );
    $stmt->execute([$accountId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function deseo_mylive_assets(PDO $pdo, int $accountId): array {
    $stmt = $pdo->prepare(
        "SELECT id, asset_type, title, original_name, stored_name, file_size, mime_type, created_at
         FROM dj_portal_assets
         WHERE account_id = ?
         ORDER BY
            CASE asset_type
                WHEN 'artwork' THEN 1
                WHEN 'dj_spot' THEN 2
                WHEN 'dj_spot_30' THEN 3
                ELSE 4
            END,
            created_at DESC"
    );
    $stmt->execute([$accountId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function deseo_mylive_asset_label(string $type): string {
    return match ($type) {
        'artwork' => 'Promotional Artwork',
        'dj_spot' => 'Personal DJ Imaging',
        'dj_spot_30' => "30' Imaging",
        default => 'Additional Asset'
    };
}

function deseo_mylive_day_label(?int $day): string {
    if (!$day) return '—';
    return dj_season_day_label($day);
}

function deseo_mylive_format_time(?string $time): string {
    return $time ? substr($time, 0, 5) : '—';
}

function deseo_mylive_slot(array $account): string {
    $day = deseo_mylive_day_label(isset($account['day_of_week']) ? (int)$account['day_of_week'] : null);
    $start = deseo_mylive_format_time((string)($account['start_time'] ?? ''));
    if ($day === '—' && $start === '—') return 'Slot to be announced';
    return trim($day . ' · ' . $start, " ·");
}

function deseo_mylive_format_bytes(int $bytes): string {
    if ($bytes <= 0) return '0 MB';
    $mb = $bytes / 1048576;
    if ($mb < 1024) return number_format($mb, 1) . ' MB';
    return number_format($mb / 1024, 2) . ' GB';
}
