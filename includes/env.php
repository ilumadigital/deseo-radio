<?php
declare(strict_types=1);

// Shared .env loader for the public site and ILUMA CMS.
// Recommended Hostinger layout:
//   domains/example.com/.env
//   domains/example.com/public_html/

$projectRoot = dirname(__DIR__);
$domainRoot = dirname($projectRoot);

$envCandidates = [];

$customEnvFile = getenv('DESEO_ENV_FILE');
if ($customEnvFile !== false && trim($customEnvFile) !== '') {
    $envCandidates[] = trim($customEnvFile);
}

$envCandidates[] = $domainRoot . '/.env';
$envCandidates[] = $projectRoot . '/.env';

// Hostinger / Hestia style layouts commonly keep .env one level above public_html.
// Resolve it from the actual web document root as well, so SMTP and DB loading do
// not depend on where the repository directory happens to sit.
$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
if ($documentRoot !== false && $documentRoot !== '') {
    $envCandidates[] = dirname($documentRoot) . '/.env';
    $envCandidates[] = $documentRoot . '/.env';
}

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
        if ($line === '' || (isset($line[0]) && $line[0] === '#') || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $existing = $key !== '' ? getenv($key) : false;
        if ($key === '' || ($existing !== false && trim((string)$existing) !== '')) {
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
