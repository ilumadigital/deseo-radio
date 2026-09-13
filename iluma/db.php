<?php
declare(strict_types=1);\n\ndate_default_timezone_set('Europe/Athens');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/connection.php';

$adminPassword = getenv('ADMIN_PASSWORD');
if ($adminPassword === false || trim($adminPassword) === '') {
    throw new RuntimeException('ADMIN_PASSWORD is not configured.');
}
define('ADMIN_PASSWORD', (string) $adminPassword);

$pdo->exec("CREATE TABLE IF NOT EXISTS airplay (
    id INT AUTO_INCREMENT PRIMARY KEY,
    spotify_url VARCHAR(255) NOT NULL,
    track_name VARCHAR(255) NOT NULL,
    artist_name VARCHAR(255) DEFAULT '',
    artwork_url TEXT,
    position INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS program (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dj_name VARCHAR(255) NOT NULL,
    photo_path VARCHAR(255) DEFAULT '',
    day_of_week TINYINT NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS playlists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    spotify_url VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    artwork_url TEXT,
    position INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_playlists_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function admin_is_logged_in(): bool {
    return !empty($_SESSION['iluma_admin']);
}

function admin_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function admin_verify_csrf(?string $token): bool {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function admin_require_login(): void {
    if (!admin_is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function admin_e(?string $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$currentFile = basename($_SERVER['PHP_SELF'] ?? '');
if ($currentFile !== 'index.php') {
    admin_require_login();
}
