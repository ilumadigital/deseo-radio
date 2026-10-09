<?php
declare(strict_types=1);

/**
 * Deseo Radio — production homepage (redesigned Season 6).
 *
 * The shared renderer keeps CMS schedule, playlists, live player, partners,
 * FAQ and AI discovery identical to /mydemo, but switches to the original
 * production SEO/schema.org/analytics infrastructure in includes/head-meta.php.
 *
 * The legacy program_feed endpoint remains stable for external consumers.
 * Pre-launch rollback: backup/pre-redesign-live-20261010.
 */
if (($_GET['program_feed'] ?? '') === '1') {
    require __DIR__ . '/includes/home-program-feed.php';
    exit;
}

$deseoIsProductionHome = true;
require __DIR__ . '/mydemo.php';
