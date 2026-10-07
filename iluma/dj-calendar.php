<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-season.php';
require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/admin-ui.php';

dj_season_bootstrap($pdo);
deseo_mylive_bootstrap($pdo);

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

/*
 * The weekly calendar must reflect the actual MyLive resident schedule, not
 * only rows that originated from the public Season 6 application form.
 *
 * Primary source:
 * - every weekly slot of an approved Season booking that has a MyLive account;
 * - every active manual MyLive account (booking_id IS NULL).
 *
 * This also means a second/third weekly slot added from MyLive appears here.
 */
$slotStmt = $pdo->prepare(
    "SELECT w.id,
            a.id AS account_id,
            a.booking_id,
            a.artist_name,
            a.full_name,
            COALESCE(NULLIF(a.profile_photo_path, ''), NULLIF(b.photo_path, ''), NULLIF(p.photo_path, ''), '') AS photo_path,
            w.day_of_week AS final_day_of_week,
            w.start_time AS final_start_time,
            w.end_time AS final_end_time,
            w.day_of_week,
            w.start_time,
            w.end_time
     FROM dj_portal_weekly_slots w
     INNER JOIN dj_portal_accounts a ON a.id = w.account_id
     LEFT JOIN dj_season_bookings b
       ON b.id = a.booking_id
      AND b.season = ?
     LEFT JOIN program p ON p.id = w.source_program_id
     WHERE (
            (b.id IS NOT NULL AND b.status = 'approved')
            OR
            (a.booking_id IS NULL AND a.is_active = 1 AND a.account_status = 'active')
           )
       AND (b.id IS NULL OR b.status <> 'guest')
     ORDER BY w.day_of_week ASC, w.start_time ASC, a.artist_name ASC, w.id ASC"
);
$slotStmt->execute([DESEO_DJ_SEASON]);
$approvedSets = $slotStmt->fetchAll(PDO::FETCH_ASSOC);

$representedBookingIds = [];
foreach ($approvedSets as &$set) {
    $bookingId = (int)($set['booking_id'] ?? 0);
    if ($bookingId > 0) {
        $representedBookingIds[$bookingId] = true;
    }

    $set['calendar_key'] = 'mylive:' . (int)$set['id'];
    $set['open_url'] = $bookingId > 0
        ? 'dj-season.php#application-' . $bookingId
        : 'mylive.php';
    $set['open_label'] = $bookingId > 0 ? 'Open' : 'MyLive';
}
unset($set);

/*
 * Legacy safety net: keep an approved application visible even if its MyLive
 * account/weekly-slot record has not been created yet.
 */
$legacyStmt = $pdo->prepare(
    "SELECT b.id, b.artist_name, b.full_name, b.photo_path,
            b.final_day_of_week, b.final_start_time, b.final_end_time,
            s.day_of_week, s.start_time, s.end_time
     FROM dj_season_bookings b
     INNER JOIN dj_season_slots s ON s.id = b.slot_id
     WHERE b.season = ?
       AND b.status = 'approved'
     ORDER BY b.id ASC"
);
$legacyStmt->execute([DESEO_DJ_SEASON]);

foreach ($legacyStmt->fetchAll(PDO::FETCH_ASSOC) as $set) {
    $bookingId = (int)$set['id'];
    if (isset($representedBookingIds[$bookingId])) {
        continue;
    }

    $set['account_id'] = null;
    $set['booking_id'] = $bookingId;
    $set['calendar_key'] = 'booking:' . $bookingId;
    $set['open_url'] = 'dj-season.php#application-' . $bookingId;
    $set['open_label'] = 'Open';
    $approvedSets[] = $set;
}

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
                $overlapIds[(string)$daySets[$i]['calendar_key']] = true;
                $overlapIds[(string)$daySets[$j]['calendar_key']] = true;
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
        <p>Το εβδομαδιαίο πρόγραμμα των approved DJ sets και των active manual MyLive DJs. Τα overlaps επιτρέπονται και επισημαίνονται καθαρά για να τα διαχειρίζεσαι χωρίς να αλλάζει αυτόματα κανένα slot.</p>
    </div>
    <a class="button button-secondary" href="dj-season.php">DJ Applications</a>
</div>

<section class="dj-calendar-stats" aria-label="DJ calendar summary">
    <article>
        <span>WEEKLY SETS</span>
        <strong><?= count($approvedSets) ?></strong>
        <small>approved + manual MyLive</small>
    </article>
    <article>
        <span>ACTIVE DAYS</span>
        <strong><?= $activeDays ?></strong>
        <small>Wednesday to Sunday</small>
    </article>
    <article>
        <span>WEEKLY HOURS</span>
        <strong><?= admin_e($weeklyHours) ?></strong>
        <small>scheduled airtime</small>
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
            <span>APPROVED + ACTIVE MYLIVE</span>
            <h2>Season <?= DESEO_DJ_SEASON ?> weekly view</h2>
            <p>Recurring εβδομαδιαία προβολή με όλα τα τελικά MyLive weekly slots, είτε ο DJ ήρθε από submission είτε δημιουργήθηκε χειροκίνητα.</p>
        </div>
        <div class="dj-calendar-legend">
            <span><i></i> Approved</span>
            <span class="has-overlap"><i></i> Overlap</span>
        </div>
    </div>

    <?php if (!$approvedSets): ?>
        <div class="dj-calendar-empty">
            <strong>Δεν υπάρχουν ακόμη weekly DJ sets.</strong>
            <p>Approved applications και active manual MyLive DJs με weekly slot θα εμφανίζονται αυτόματα εδώ.</p>
            <a class="button button-primary" href="mylive.php">Open MyLive</a>
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
                                    <p>No weekly sets</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($sets as $set): ?>
                                    <?php
                                    $hasOverlap = isset($overlapIds[(string)$set['calendar_key']]);
                                    $photoPath = trim((string)($set['photo_path'] ?? ''));
                                    ?>
                                    <article class="dj-calendar-set <?= $hasOverlap ? 'has-overlap' : '' ?>">
                                        <div class="dj-calendar-set-time">
                                            <strong><?= admin_e(dj_season_format_time((string)$set['effective_start'])) ?></strong>
                                            <span>– <?= admin_e(dj_season_format_time((string)$set['effective_end'])) ?></span>
                                        </div>

                                        <div class="dj-calendar-set-person">
                                            <?php if ($photoPath !== ''): ?>
                                                <img src="<?= admin_e($photoPath) ?>" alt="" loading="lazy">
                                            <?php else: ?>
                                                <span
                                                    aria-hidden="true"
                                                    style="width:42px;height:42px;display:grid;place-items:center;flex:0 0 42px;border-radius:12px;background:rgba(255,255,255,.06);color:#8b8b86;font-size:10px;font-weight:800;letter-spacing:.08em;"
                                                >DJ</span>
                                            <?php endif; ?>
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
                                            <a href="<?= admin_e((string)$set['open_url']) ?>"><?= admin_e((string)$set['open_label']) ?></a>
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
