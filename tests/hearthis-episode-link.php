<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/hearthis-episode-link.php';

$validUrl = 'https://hearthis.at/deseoradio/cobo-b-deseo-radio-s06-ep001/';
$valid = [
    'account_id' => 51, 'status' => 'broadcasted', 'hearthis_status' => 'verifying',
    'hearthis_upload_accepted_at' => '2026-10-17 00:00:14',
    'hearthis_track_id' => '14703495', 'hearthis_url' => $validUrl,
];
if (deseo_mylive_hearthis_episode_link($valid, 51) !== $validUrl) {
    fwrite(STDERR, "FAIL: accepted BROADCASTED episode should reveal link before RSS sync\n");
    exit(1);
}
$final = $valid; $final['hearthis_status'] = 'synced';
if (deseo_mylive_hearthis_episode_link($final, 51) !== $validUrl) {
    fwrite(STDERR, "FAIL: SYNCED episode must keep its link\n");
    exit(1);
}
$invalid = [
    ['foreign DJ account', $valid, 52],
    ['invalid signed-in account', $valid, 0],
    ['scheduled (never broadcasted)', array_replace($valid, ['status' => 'scheduled']), 51],
    ['received (never broadcasted)', array_replace($valid, ['status' => 'received']), 51],
    ['upload still pending', array_replace($valid, ['hearthis_status' => 'pending']), 51],
    ['ambiguous upload', array_replace($valid, ['hearthis_status' => 'review_required']), 51],
    ['no accepted timestamp', array_replace($valid, ['hearthis_upload_accepted_at' => null]), 51],
    ['no positive ID', array_replace($valid, ['hearthis_track_id' => '0']), 51],
    ['wrong author URL', array_replace($valid, ['hearthis_url' => 'https://hearthis.at/other/track/']), 51],
    ['untrusted host', array_replace($valid, ['hearthis_url' => 'https://hearthis.at.evil.example/deseoradio/track/']), 51],
    ['injected URL query', array_replace($valid, ['hearthis_url' => $validUrl . '?redirect=evil']), 51],
];
foreach ($invalid as [$name, $episode, $viewer]) {
    if (deseo_mylive_hearthis_episode_link($episode, $viewer) !== '') {
        fwrite(STDERR, "FAIL: should hide HearThis link for {$name}\n");
        exit(1);
    }
}
echo 'PASS: accepted owned DJ HearThis URL, final SYNCED link and '
    . count($invalid) . " fail-closed cases.\n";
