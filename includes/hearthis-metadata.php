<?php
declare(strict_types=1);

// One source of truth for the preview, persisted upload snapshot and actual
// HearThis Premium multipart metadata. No credentials or database access.
require_once __DIR__ . '/dj-season.php';
require_once __DIR__ . '/hearthis-season6.php';

function deseo_hearthis_metadata(array $set): array {
    $artist = trim((string)$set['artist_name']);
    $episodeArtist = trim((string)($set['episode_dj_name'] ?? ''));
    $episode = 'EP' . str_pad((string)(int)$set['episode_no'], 3, '0', STR_PAD_LEFT);
    $dateLine = '';
    if (!empty($set['scheduled_show_end'])) {
        try {
            $end = new DateTimeImmutable((string)$set['scheduled_show_end'], dj_season_athens_timezone());
            // Midnight belongs to the preceding broadcast day.
            $dateLine = 'Original broadcast: ' . $end->modify('-1 second')->format('d.m.Y')
                . ' (Athens local time)' . "\n";
        } catch (Throwable $ignored) {
            // Never fabricate a broadcast date from an invalid value.
        }
    }
    $titleArtist = $episodeArtist !== ''
        ? $episodeArtist . ' – ' . $artist
        : $artist;
    $descriptionLead = $episodeArtist !== ''
        ? 'Exclusive DJ Set by ' . $episodeArtist . ' for ' . $artist . ' on Deseo Radio · Season 6.'
        : 'Exclusive DJ Set by ' . $artist . ' for Deseo Radio · Season 6.';

    return [
        'title' => $titleArtist . ' | Deseo Radio · S06 ' . $episode,
        'description' => $descriptionLead . "\n\n"
            . $dateLine
            . 'Listen Live: https://deseoradio.com' . "\n"
            . 'Season 6 DJ Sets: ' . DESEO_HEARTHIS_SEASON6_URL . "\n\n"
            . 'Stay Tuned, στο Soundtrack της ζωής σου!',
        'genre' => 'Radioshow',
        'tags' => 'Deseo Radio,Season 6,DJ Set',
        'private' => '0',
    ];
}

