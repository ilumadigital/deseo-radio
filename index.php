<?php
declare(strict_types=1);

/**
 * Deseo Radio — production homepage (redesigned Season 6).
 *
 * The includes/homepage.php renderer powers the production site,
 * with canonical SEO/schema.org/analytics from includes/head-meta.php.
 * Its CMS schedule, playlists, iRadios player, sponsors and AI discovery
 * remain fully dynamic.
 *
 * The legacy program_feed endpoint remains stable for external consumers.
 * Pre-launch rollback: backup/pre-redesign-live-20261010.
 */
if (($_GET['program_feed'] ?? '') === '1') {
    require __DIR__ . '/includes/home-program-feed.php';
    exit;
}

require __DIR__ . '/includes/homepage.php';
