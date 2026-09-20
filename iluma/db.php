<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Athens');

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate', true);
    header('Cache-Control: private, no-store, no-cache, must-revalidate', true);
    header('Pragma: no-cache', true);
}

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
define('ADMIN_PASSWORD', (string)$adminPassword);

$managerPassword = getenv('RADIO_MANAGER_PASSWORD');
define(
    'RADIO_MANAGER_PASSWORD',
    $managerPassword !== false ? trim((string)$managerPassword) : ''
);

const DESEO_CMS_ADMIN_EMAIL = 'greg@iluma.gr';
const DESEO_CMS_MANAGER_EMAIL = 'radio@iluma.gr';

try {
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
        photo_path VARCHAR(1000) DEFAULT '',
        mylive_account_id BIGINT NULL,
        day_of_week TINYINT NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        UNIQUE KEY uniq_program_day_start (day_of_week, start_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Keep one row per day/start slot and enforce it at database level as well.
    // The CMS also removes any time-overlapping rows before save.
    $pdo->exec(
        "DELETE older FROM program older
         INNER JOIN program newer
           ON older.day_of_week = newer.day_of_week
          AND older.start_time = newer.start_time
          AND older.id < newer.id"
    );

    try {
        $pdo->exec("ALTER TABLE program MODIFY photo_path VARCHAR(1000) DEFAULT ''");
    } catch (Throwable $photoPathMigrationError) {
        error_log('Program photo_path migration failed: ' . $photoPathMigrationError->getMessage());
    }

    $profileColumnStmt = $pdo->query("SHOW COLUMNS FROM program LIKE 'mylive_account_id'");
    if (!$profileColumnStmt || !$profileColumnStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE program ADD COLUMN mylive_account_id BIGINT NULL AFTER photo_path");
    }

    $indexStmt = $pdo->query("SHOW INDEX FROM program WHERE Key_name = 'uniq_program_day_start'");
    if (!$indexStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE program ADD UNIQUE KEY uniq_program_day_start (day_of_week, start_time)");
    }
} catch (Throwable $schemaError) {
    error_log('Core CMS schema check failed: ' . $schemaError->getMessage());
}

function admin_current_email(): string {
    return strtolower(trim((string)($_SESSION['iluma_user_email'] ?? '')));
}

function admin_current_role(): string {
    $role = strtolower(trim((string)($_SESSION['iluma_user_role'] ?? '')));
    return in_array($role, ['administrator', 'manager'], true) ? $role : '';
}

function admin_is_logged_in(): bool {
    $email = admin_current_email();
    $role = admin_current_role();

    if ($role === 'administrator') {
        return $email === DESEO_CMS_ADMIN_EMAIL;
    }

    if ($role === 'manager') {
        return $email === DESEO_CMS_MANAGER_EMAIL;
    }

    return false;
}

function admin_is_administrator(): bool {
    return admin_is_logged_in() && admin_current_role() === 'administrator';
}

function admin_is_manager(): bool {
    return admin_is_logged_in() && admin_current_role() === 'manager';
}

function admin_authenticate_credentials(string $email, string $password): ?array {
    $email = strtolower(trim($email));

    if ($email === DESEO_CMS_ADMIN_EMAIL && hash_equals(ADMIN_PASSWORD, $password)) {
        return [
            'email' => DESEO_CMS_ADMIN_EMAIL,
            'role' => 'administrator',
        ];
    }

    if (
        $email === DESEO_CMS_MANAGER_EMAIL
        && RADIO_MANAGER_PASSWORD !== ''
        && hash_equals(RADIO_MANAGER_PASSWORD, $password)
    ) {
        return [
            'email' => DESEO_CMS_MANAGER_EMAIL,
            'role' => 'manager',
        ];
    }

    return null;
}

function admin_can_access(string $resource): bool {
    if (!admin_is_logged_in()) {
        return false;
    }

    if (in_array($resource, ['audience', 'rewards'], true)) {
        return admin_is_administrator();
    }

    return true;
}

function admin_require_access(string $resource): void {
    admin_require_login();

    if (!admin_can_access($resource)) {
        header('Location: index.php', true, 302);
        exit;
    }
}

function admin_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function admin_verify_csrf(?string $token): bool {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], $token);
}

function admin_require_login(): void {
    if (!admin_is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function admin_e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$currentFile = basename($_SERVER['PHP_SELF'] ?? '');
if ($currentFile !== 'index.php') {
    admin_require_login();

    if ($currentFile === 'audience.php') {
        admin_require_access('audience');
    }
}
