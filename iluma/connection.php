<?php
// iluma/connection.php
// Database credentials are loaded from environment variables or from a .env
// file kept outside the deploy directory whenever possible.
//
// Recommended Hostinger layout:
//   domains/example.com/.env
//   domains/example.com/public_html/
//
// Keeping .env one level above public_html means Git redeploys of public_html
// cannot overwrite or remove production credentials.

$projectRoot = dirname(__DIR__);
$domainRoot = dirname($projectRoot);

$envCandidates = [];

// Optional explicit path, useful on hosts that expose environment variables.
$customEnvFile = getenv('DESEO_ENV_FILE');
if ($customEnvFile !== false && trim($customEnvFile) !== '') {
    $envCandidates[] = trim($customEnvFile);
}

// Preferred: outside public_html / deployment target.
$envCandidates[] = $domainRoot . '/.env';

// Legacy fallback: inside project root. Supported for compatibility, but the
// parent-directory location above is safer for production deployments.
$envCandidates[] = $projectRoot . '/.env';

// Optional account-home fallback.
$homeDir = getenv('HOME');
if ($homeDir !== false && trim($homeDir) !== '') {
    $envCandidates[] = rtrim($homeDir, '/\\') . '/.deseo-radio.env';
}

$envFile = null;
foreach (array_unique($envCandidates) as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        $envFile = $candidate;
        break;
    }
}

if ($envFile !== null) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

$db_host = getenv('DB_HOST') ?: '';
$db_name = getenv('DB_NAME') ?: '';
$db_user = getenv('DB_USER') ?: '';
$db_pass = getenv('DB_PASS') ?: '';

if ($db_host === '' || $db_name === '' || $db_user === '') {
    throw new RuntimeException(
        'Database configuration is missing. Configure DB_HOST, DB_NAME, DB_USER and DB_PASS.'
    );
}

try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    throw new RuntimeException('Database connection failed.');
}
