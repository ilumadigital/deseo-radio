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
