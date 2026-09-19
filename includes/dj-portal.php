<?php
declare(strict_types=1);

require_once __DIR__ . '/../iluma/connection.php';
require_once __DIR__ . '/dj-season.php';

const DESEO_MYLive_MAX_BYTES = 1073741824; // 1 GB

function deseo_mylive_bootstrap(PDO $pdo): void {
    dj_season_bootstrap($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_portal_accounts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        booking_id BIGINT NOT NULL,
        email VARCHAR(254) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_portal_booking (booking_id),
        UNIQUE KEY uniq_portal_email (email),
        CONSTRAINT fk_portal_booking FOREIGN KEY (booking_id) REFERENCES dj_season_bookings(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        'samesite' => 'Lax',
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
        "SELECT a.id, a.booking_id, a.email, a.is_active, a.last_login_at,
                b.artist_name, b.full_name, b.photo_path, b.status,
                COALESCE(b.final_day_of_week, s.day_of_week) AS day_of_week,
                COALESCE(b.final_start_time, s.start_time) AS start_time,
                COALESCE(b.final_end_time, s.end_time) AS end_time
         FROM dj_portal_accounts a
         INNER JOIN dj_season_bookings b ON b.id = a.booking_id
         INNER JOIN dj_season_slots s ON s.id = b.slot_id
         WHERE a.id = ? AND a.is_active = 1 AND b.season = ?
         LIMIT 1"
    );
    $stmt->execute([$accountId, DESEO_DJ_SEASON]);
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

function deseo_mylive_format_bytes(int $bytes): string {
    if ($bytes <= 0) return '0 MB';
    $mb = $bytes / 1048576;
    if ($mb < 1024) return number_format($mb, 1) . ' MB';
    return number_format($mb / 1024, 2) . ' GB';
}
