<?php
declare(strict_types=1);

require_once __DIR__ . '/hearthis-podcast.php';

/**
 * A DJ can open the accepted public episode as soon as its ID and owned
 * permalink are saved, without waiting for Season 6 playlist or podcast RSS.
 * Only expose THEIR OWN broadcasted row through the authenticated MyLive query.
 */
function deseo_mylive_hearthis_episode_link(array $set, int $signedInAccountId): string {
    if ($signedInAccountId < 1 || (int)($set['account_id'] ?? 0) !== $signedInAccountId
        || (string)($set['status'] ?? '') !== 'broadcasted'
        || !in_array((string)($set['hearthis_status'] ?? ''), ['verifying', 'synced'], true)
        || empty($set['hearthis_upload_accepted_at'])) return '';
    $id = trim((string)($set['hearthis_track_id'] ?? ''));
    if (!ctype_digit($id) || (int)$id < 1) return '';
    return deseo_hearthis_podcast_canonical_track((string)($set['hearthis_url'] ?? ''));
}

