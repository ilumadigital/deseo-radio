<?php
// iluma/db.php
session_start();

$adminPassword = getenv('ADMIN_PASSWORD');
if ($adminPassword === false || $adminPassword === '') {
    // connection.php also loads the local .env file when present.
    require_once __DIR__ . '/connection.php';
    $adminPassword = getenv('ADMIN_PASSWORD');
} else {
    require_once __DIR__ . '/connection.php';
}

if ($adminPassword === false || $adminPassword === '') {
    throw new RuntimeException('ADMIN_PASSWORD is not configured.');
}

define('ADMIN_PASSWORD', $adminPassword);

// Δημιουργία Πινάκων (MySQL Syntax)
$pdo->exec("CREATE TABLE IF NOT EXISTS airplay (
    id INT AUTO_INCREMENT PRIMARY KEY,
    spotify_url VARCHAR(255),
    track_name VARCHAR(255),
    artist_name VARCHAR(255),
    artwork_url TEXT,
    position INT DEFAULT 0
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS program (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dj_name VARCHAR(255),
    photo_path VARCHAR(255),
    day_of_week INT,
    start_time TIME,
    end_time TIME
)");

// Έλεγχος Login
$current_file = basename($_SERVER['PHP_SELF']);
if ($current_file !== 'index.php' && !isset($_SESSION['iluma_admin'])) {
    header("Location: index.php");
    exit;
}
