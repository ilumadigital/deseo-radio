<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';

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
