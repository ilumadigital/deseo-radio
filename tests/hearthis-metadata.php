<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/hearthis-metadata.php';

$cases = [
    ['DemiX Music', 1, '2026-10-14 22:00:00', 'DemiX Music – Deseo Radio | S06 EP001', 'Original broadcast: 14.10.2026'],
    ['Cobo B', 1, '2026-10-17 00:00:00', 'Cobo B – Deseo Radio | S06 EP001', 'Original broadcast: 16.10.2026'],
    ['ANDØR', 2, '2026-10-23 21:00:00', 'ANDØR – Deseo Radio | S06 EP002', 'Original broadcast: 23.10.2026'],
];
foreach ($cases as [$artist, $episodeNo, $showEnd, $title, $expectedBroadcast]) {
    $m = deseo_hearthis_metadata([
        'artist_name' => $artist, 'episode_no' => $episodeNo,
        'scheduled_show_end' => $showEnd,
    ]);
    if ($m['title'] !== $title
        || !str_contains($m['description'], $expectedBroadcast)
        || !str_contains($m['description'], DESEO_HEARTHIS_SEASON6_URL)
        || $m['genre'] !== 'Radioshow'
        || $m['tags'] !== 'Deseo Radio,Season 6,DJ Set'
        || $m['private'] !== '0') {
        fwrite(STDERR, "FAIL: metadata for {$artist} EP{$episodeNo}\n");
        exit(1);
    }
}
$pending = deseo_hearthis_metadata([
    'artist_name' => 'Katty Belle', 'episode_no' => 3, 'scheduled_show_end' => null,
]);
if (str_contains($pending['description'], 'Original broadcast:')) {
    fwrite(STDERR, "FAIL: pending metadata invented a broadcast date\n");
    exit(1);
}
echo 'PASS: ' . count($cases) . " exact HearThis upload metadata fixtures, date rollover, and no fabricated pending date.\n";
