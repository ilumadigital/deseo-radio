<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/dj-season.php';

// The Season 6 schedule begins on Wed 14 October 2026 (Europe/Athens).
$tz = dj_season_athens_timezone();
$beforeLaunch = new DateTimeImmutable('2026-10-02 12:00:00', $tz);

$normalization = [
    ['20:00:00', '20:59:59', '21:00:00'],
    ['23:00:00', '23:59:59', '00:00:00'],
    ['23:00:00', '23:59:00', '00:00:00'],
    ['18:00:00', '18:59:59', '19:00:00'],
    ['20:00:00', '21:00:00', '21:00:00'],
    ['20:00:00', '20:45:00', '20:45:00'],
];
foreach ($normalization as [$start, $strictEnd, $expected]) {
    $actual = dj_season_normalized_resident_end_time($start, $strictEnd);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: normalizing {$start} / {$strictEnd}: {$actual}\n");
        exit(1);
    }
}

// Verified calendar targets from the actual Season 6 Resident roster.
$cases = [
    ['Wednesday first Resident', 3, '20:00:00', '20:59:59', '2026-10-14 20:00', '2026-10-14 21:00'],
    ['Thursday pmelgidis', 4, '20:00:00', '20:59:59', '2026-10-15 20:00', '2026-10-15 21:00'],
    ['Friday ANDOR', 5, '20:00:00', '20:59:59', '2026-10-16 20:00', '2026-10-16 21:00'],
    ['Friday Cobo B overnight', 5, '23:00:00', '23:59:59', '2026-10-16 23:00', '2026-10-17 00:00'],
    ['Saturday late-night', 6, '23:00:00', '23:59:59', '2026-10-17 23:00', '2026-10-18 00:00'],
    ['Sunday season week', 7, '18:00:00', '18:59:59', '2026-10-18 18:00', '2026-10-18 19:00'],
];
foreach ($cases as [$name, $day, $start, $strictEnd, $expectedStart, $expectedEnd]) {
    $end = dj_season_normalized_resident_end_time($start, $strictEnd);
    $occurrence = dj_season_weekly_occurrence($day, $start, $end, $beforeLaunch);
    if (!is_array($occurrence)
        || $occurrence[0]->format('Y-m-d H:i') !== $expectedStart
        || $occurrence[1]->format('Y-m-d H:i') !== $expectedEnd) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

// Live CMS showed Cobo B ending 16/10 at 23:59:00 (legacy); actual
// one-hour on-air slot must end on Saturday 17/10 at 00:00:00.
$correction = dj_season_legacy_midnight_end_fix(
    5, '23:00:00', '23:59:00', '2026-10-16 23:59:00', $beforeLaunch
);
if ($correction !== '2026-10-17 00:00:00') {
    fwrite(STDERR, "FAIL: Cobo B legacy Friday midnight repair\n");
    exit(1);
}
if (dj_season_legacy_midnight_end_fix(
    5, '23:00:00', '23:59:00', '2026-10-16 23:59:00',
    new DateTimeImmutable('2026-10-17 00:00:00', $tz)
) !== null || dj_season_legacy_midnight_end_fix(
    4, '23:00:00', '23:59:00', '2026-10-16 23:59:00', $beforeLaunch
) !== null || dj_season_legacy_midnight_end_fix(
    5, '23:00:00', '23:45:00', '2026-10-16 23:45:00', $beforeLaunch
) !== null) {
    fwrite(STDERR, "FAIL: legacy repair must not edit past, wrong weekday or custom durations\n");
    exit(1);
}

$next = dj_season_weekly_occurrence(
    3, '20:00:00', '21:00:00', new DateTimeImmutable('2026-10-14 21:00:01', $tz)
);
if (!is_array($next)
    || $next[0]->format('Y-m-d H:i') !== '2026-10-21 20:00'
    || $next[1]->format('Y-m-d H:i') !== '2026-10-21 21:00') {
    fwrite(STDERR, "FAIL: next weekly Resident occurrence\n");
    exit(1);
}
echo 'PASS: ' . count($normalization) . ' normalized Resident slot ends, '
    . count($cases) . " Season 6 start/overnight dates and weekly recurrence.\n";
