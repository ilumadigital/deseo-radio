<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-season.php';
require_once __DIR__ . '/admin-ui.php';

dj_season_bootstrap($pdo);

function dj_calendar_minutes(string $time): int {
    $parts = array_map('intval', explode(':', $time));
    $hours = $parts[0] ?? 0;
    $minutes = $parts[1] ?? 0;
    $seconds = $parts[2] ?? 0;

    if ($hours === 23 && $minutes === 59 && $seconds >= 59) {
        return 24 * 60;
    }

    return ($hours * 60) + $minutes;
}

$stmt = $pdo->prepare(
    "SELECT b.id, b.artist_name, b.full_name, b.photo_path,
            b.final_day_of_week, b.final_start_time, b.final_end_time,
            s.day_of_week, s.start_time, s.end_time
     FROM dj_season_bookings b
     INNER JOIN dj_season_slots s ON s.id = b.slot_id
     WHERE b.season = ?
       AND b.status = 'approved'
     ORDER BY b.id ASC"
);
$stmt->execute([DESEO_DJ_SEASON]);
$approvedSets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dayLabels = [
    3 => 'Τετάρτη',
    4 => 'Πέμπτη',
    5 => 'Παρασκευή',
    6 => 'Σάββατο',
    7 => 'Κυριακή',
];
$dayShort = [
    3 => 'ΤΕΤ',
    4 => 'ΠΕΜ',
    5 => 'ΠΑΡ',
    6 => 'ΣΑΒ',
    7 => 'ΚΥΡ',
];

$calendarDays = [
    3 => [],
    4 => [],
    5 => [],
    6 => [],
    7 => [],
];
$totalMinutes = 0;

foreach ($approvedSets as $set) {
    $day = !empty($set['final_day_of_week'])
        ? (int)$set['final_day_of_week']
        : (int)$set['day_of_week'];
    $start = !empty($set['final_start_time'])
        ? (string)$set['final_start_time']
        : (string)$set['start_time'];
    $end = !empty($set['final_end_time'])
        ? (string)$set['final_end_time']
        : (string)$set['end_time'];

    if (!isset($calendarDays[$day])) {
        continue;
    }

    $startMinutes = dj_calendar_minutes($start);
    $endMinutes = dj_calendar_minutes($end);
    if ($endMinutes > $startMinutes) {
        $totalMinutes += ($endMinutes - $startMinutes);
    }

    $set['effective_day'] = $day;
    $set['effective_start'] = $start;
    $set['effective_end'] = $end;
    $set['start_minutes'] = $startMinutes;
    $set['end_minutes'] = $endMinutes;
    $calendarDays[$day][] = $set;
}

$overlapIds = [];
$activeDays = 0;

foreach ($calendarDays as $day => &$daySets) {
    usort($daySets, static function (array $a, array $b): int {
        $timeCompare = ((int)$a['start_minutes']) <=> ((int)$b['start_minutes']);
        if ($timeCompare !== 0) return $timeCompare;
        return strcasecmp((string)$a['artist_name'], (string)$b['artist_name']);
    });

    if ($daySets) {
        $activeDays++;
    }

    $count = count($daySets);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            if ((int)$daySets[$j]['start_minutes'] >= (int)$daySets[$i]['end_minutes']) {
                break;
            }

            if (
                (int)$daySets[$i]['start_minutes'] < (int)$daySets[$j]['end_minutes']
                && (int)$daySets[$i]['end_minutes'] > (int)$daySets[$j]['start_minutes']
            ) {
                $overlapIds[(int)$daySets[$i]['id']] = true;
                $overlapIds[(int)$daySets[$j]['id']] = true;
            }
        }
    }
}
unset($daySets);

$weeklyHours = $totalMinutes > 0 ? number_format($totalMinutes / 60, 1) : '0';

admin_page_start('DJ Calendar', 'dj-calendar');
?>
<div class="page-heading dj-calendar-page-heading">
    <div>
        <span>Deseo Radio · Season <?= DESEO_DJ_SEASON ?></span>
        <h1>Weekly DJ Calendar</h1>
        <p>Το εβδομαδιαίο πρόγραμμα των approved DJ sets. Τα overlaps επιτρέπονται και επισημαίνονται καθαρά για να τα διαχειρίζεσαι χωρίς να αλλάζει αυτόματα κανένα slot.</p>
    </div>
    <a class="button button-secondary" href="dj-season.php">DJ Applications</a>
</div>

<section class="dj-calendar-stats" aria-label="DJ calendar summary">
    <article>
        <span>APPROVED SETS</span>
        <strong><?= count($approvedSets) ?></strong>
        <small>Season <?= DESEO_DJ_SEASON ?></small>
    </article>
    <article>
        <span>ACTIVE DAYS</span>
        <strong><?= $activeDays ?></strong>
        <small>Wednesday to Sunday</small>
    </article>
    <article>
        <span>WEEKLY HOURS</span>
        <strong><?= admin_e($weeklyHours) ?></strong>
        <small>approved airtime</small>
    </article>
    <article>
        <span>OVERLAPPING SETS</span>
        <strong><?= count($overlapIds) ?></strong>
        <small>allowed · review if needed</small>
    </article>
</section>

<section class="panel dj-calendar-panel">
    <div class="dj-calendar-panel-head">
        <div>
            <span>APPROVED ONLY</span>
            <h2>Season <?= DESEO_DJ_SEASON ?> weekly view</h2>
            <p>Recurring εβδομαδιαία προβολή με το τελικό day & time κάθε εγκεκριμένου DJ.</p>
        </div>
        <div class="dj-calendar-legend">
            <span><i></i> Approved</span>
            <span class="has-overlap"><i></i> Overlap</span>
        </div>
    </div>

    <?php if (!$approvedSets): ?>
        <div class="dj-calendar-empty">
            <strong>Δεν υπάρχουν ακόμη approved DJ sets.</strong>
            <p>Μόλις εγκρίνεις ένα inquiry, θα εμφανιστεί αυτόματα εδώ.</p>
            <a class="button button-primary" href="dj-season.php">Open DJ Applications</a>
        </div>
    <?php else: ?>
        <div class="dj-calendar-scroll">
            <div class="dj-calendar-grid">
                <?php foreach ($calendarDays as $day => $sets): ?>
                    <section class="dj-calendar-day <?= $sets ? 'has-sets' : '' ?>">
                        <header class="dj-calendar-day-head">
                            <span><?= admin_e($dayShort[$day]) ?></span>
                            <strong><?= admin_e($dayLabels[$day]) ?></strong>
                            <small><?= count($sets) ?> <?= count($sets) === 1 ? 'set' : 'sets' ?></small>
                        </header>

                        <div class="dj-calendar-day-body">
                            <?php if (!$sets): ?>
                                <div class="dj-calendar-day-empty">
                                    <span>—</span>
                                    <p>No approved sets</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($sets as $set): ?>
                                    <?php $hasOverlap = isset($overlapIds[(int)$set['id']]); ?>
                                    <article class="dj-calendar-set <?= $hasOverlap ? 'has-overlap' : '' ?>">
                                        <div class="dj-calendar-set-time">
                                            <strong><?= admin_e(dj_season_format_time((string)$set['effective_start'])) ?></strong>
                                            <span>→ <?= admin_e(dj_season_format_time((string)$set['effective_end'])) ?></span>
                                        </div>

                                        <div class="dj-calendar-set-person">
                                            <img src="<?= admin_e((string)$set['photo_path']) ?>" alt="" loading="lazy">
                                            <div>
                                                <strong><?= admin_e((string)$set['artist_name']) ?></strong>
                                                <small><?= admin_e((string)$set['full_name']) ?></small>
                                            </div>
                                        </div>

                                        <div class="dj-calendar-set-footer">
                                            <span>APPROVED</span>
                                            <?php if ($hasOverlap): ?>
                                                <b>OVERLAP</b>
                                            <?php endif; ?>
                                            <a href="dj-season.php#application-<?= (int)$set['id'] ?>">Open ↗</a>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php admin_page_end(); ?>
