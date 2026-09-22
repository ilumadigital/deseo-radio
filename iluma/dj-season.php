<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-season.php';
require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/admin-ui.php';

dj_season_bootstrap($pdo);
deseo_mylive_bootstrap($pdo);

$notice = null;
$error = null;

function season6_admin_time(string $value, string $fieldLabel): string {
    $value = trim($value);
    $time = DateTimeImmutable::createFromFormat('!H:i', $value);
    if (!$time || $time->format('H:i') !== $value) {
        throw new RuntimeException('Μη έγκυρη ώρα στο πεδίο ' . $fieldLabel . '.');
    }
    return $time->format('H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Η συνεδρία έληξε. Ανανέωσε τη σελίδα και δοκίμασε ξανά.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        try {
            if ($action === 'set_status') {
                $bookingId = (int)($_POST['booking_id'] ?? 0);
                $status = (string)($_POST['status'] ?? 'pending');
                if (!in_array($status, ['pending', 'approved', 'guest', 'rejected', 'cancelled'], true)) {
                    throw new RuntimeException('Μη έγκυρο status.');
                }

                $finalDay = (int)($_POST['final_day_of_week'] ?? 0);
                if (!in_array($finalDay, [4, 5, 6, 7], true)) {
                    throw new RuntimeException('Επίλεξε τελική ημέρα από Πέμπτη έως Κυριακή.');
                }

                $finalStart = season6_admin_time((string)($_POST['final_start_time'] ?? ''), 'Start');
                $finalEnd = season6_admin_time((string)($_POST['final_end_time'] ?? ''), 'End');
                if ($finalEnd <= $finalStart) {
                    throw new RuntimeException('Η τελική ώρα λήξης πρέπει να είναι μετά την ώρα έναρξης.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "SELECT id, slot_id, status, final_day_of_week, final_start_time, final_end_time
                     FROM dj_season_bookings
                     WHERE id = ? AND season = ?
                     FOR UPDATE"
                );
                $stmt->execute([$bookingId, DESEO_DJ_SEASON]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$current) {
                    throw new RuntimeException('Η αίτηση δεν βρέθηκε.');
                }

                $slotStmt = $pdo->prepare(
                    "SELECT id, day_of_week, start_time, end_time
                     FROM dj_season_slots
                     WHERE id = ? AND season = ?
                     FOR UPDATE"
                );
                $slotStmt->execute([(int)$current['slot_id'], DESEO_DJ_SEASON]);
                $requestedSlot = $slotStmt->fetch(PDO::FETCH_ASSOC);
                if (!$requestedSlot) {
                    throw new RuntimeException('Το slot της αίτησης δεν βρέθηκε.');
                }

                if ($status === 'approved') {
                    $check = $pdo->prepare(
                        "SELECT b.id
                         FROM dj_season_bookings b
                         INNER JOIN dj_season_slots requested_slot ON requested_slot.id = b.slot_id
                         WHERE b.season = ?
                           AND b.status = 'approved'
                           AND b.id <> ?
                           AND COALESCE(b.final_day_of_week, requested_slot.day_of_week) = ?
                           AND ? < COALESCE(b.final_end_time, requested_slot.end_time)
                           AND ? > COALESCE(b.final_start_time, requested_slot.start_time)
                         LIMIT 1
                         FOR UPDATE"
                    );
                    $check->execute([
                        DESEO_DJ_SEASON,
                        $bookingId,
                        $finalDay,
                        $finalStart,
                        $finalEnd,
                    ]);
                    if ($check->fetchColumn()) {
                        throw new RuntimeException('Υπάρχει ήδη εγκεκριμένος DJ σε ώρα που επικαλύπτεται με αυτό το final slot.');
                    }
                }

                $update = $pdo->prepare(
                    "UPDATE dj_season_bookings
                     SET status = ?, final_day_of_week = ?, final_start_time = ?, final_end_time = ?
                     WHERE id = ? AND season = ?"
                );
                $update->execute([$status, $finalDay, $finalStart, $finalEnd, $bookingId, DESEO_DJ_SEASON]);
                $previousStatus = (string)$current['status'];
                $pdo->commit();

                try {
                    $myliveStatuses = ['approved', 'guest'];
                    if (in_array($status, $myliveStatuses, true)) {
                        deseo_mylive_create_pending_from_booking($pdo, $bookingId);
                    } elseif (
                        in_array($previousStatus, $myliveStatuses, true)
                        && !in_array($status, $myliveStatuses, true)
                    ) {
                        $cleanup = $pdo->prepare(
                            "DELETE FROM dj_portal_accounts
                             WHERE booking_id = ? AND account_status = 'pending'"
                        );
                        $cleanup->execute([$bookingId]);
                    }
                } catch (Throwable $portalSyncError) {
                    error_log('MyLive pending sync failed: ' . $portalSyncError->getMessage());
                    $error = 'Το application status αποθηκεύτηκε, αλλά δεν ενημερώθηκε σωστά το MyLive pending record.';
                }

                $mailStatuses = ['approved', 'guest', 'rejected'];
                if (in_array($status, $mailStatuses, true) && $previousStatus !== $status) {
                    $mailStmt = $pdo->prepare(
                        "SELECT b.*, s.day_of_week, s.start_time, s.end_time
                         FROM dj_season_bookings b
                         INNER JOIN dj_season_slots s ON s.id = b.slot_id
                         WHERE b.id = ? AND b.season = ?
                         LIMIT 1"
                    );
                    $mailStmt->execute([$bookingId, DESEO_DJ_SEASON]);
                    $selectedBooking = $mailStmt->fetch(PDO::FETCH_ASSOC);

                    if ($selectedBooking) {
                        try {
                            if ($status === 'approved') {
                                $mail = deseo_dj_approval_email($selectedBooking);
                            } elseif ($status === 'guest') {
                                $mail = deseo_dj_guest_email($selectedBooking);
                            } else {
                                $mail = deseo_dj_rejected_email($selectedBooking);
                            }

                            deseo_send_smtp_mail(
                                (string)$selectedBooking['email'],
                                (string)$selectedBooking['artist_name'],
                                (string)$mail['subject'],
                                (string)$mail['html'],
                                (string)$mail['text']
                            );

                            if ($status === 'approved') {
                                $notice = 'Ο DJ εγκρίθηκε και στάλθηκε το Welcome / Approved email της Season 6. Δημιουργήθηκε επίσης Pending εγγραφή στο MyLive, χωρίς login ή password. Το MyLive onboarding email θα σταλεί μόνο όταν πατήσεις Approve & Create Access μέσα από το MyLive CMS.';
                            } elseif ($status === 'guest') {
                                $notice = 'Ο DJ επιλέχθηκε ως Guest, στάλθηκε welcome email και δημιουργήθηκε Pending εγγραφή στο MyLive. Από το MyLive CMS μπορείς τώρα να εγκρίνεις την πρόσβαση και να σταλεί το onboarding email. Το weekly slot παραμένει διαθέσιμο.';
                            } else {
                                $notice = 'Η αίτηση απορρίφθηκε και στάλθηκε ενημερωτικό email για πιθανή μελλοντική Guest εμφάνιση.';
                            }
                        } catch (Throwable $mailError) {
                            error_log('Season 6 status email failed (' . $status . '): ' . $mailError->getMessage());
                            $notice = 'Το status ενημερώθηκε σε ' . strtoupper($status) . '.';
                            $error = 'Το status αποθηκεύτηκε, αλλά το email δεν στάλθηκε. Έλεγξε τα SMTP_* στοιχεία στο .env.';
                        }
                    }
                } else {
                    if ($previousStatus === $status && in_array($status, $mailStatuses, true)) {
                        $notice = 'Η αίτηση παραμένει ' . strtoupper($status) . '. Δεν στάλθηκε δεύτερο email.';
                    } else {
                        $notice = 'Το status της αίτησης ενημερώθηκε. Το Radio Program παραμένει ανεξάρτητο.';
                    }
                }
            } elseif ($action === 'release_booking') {
                $bookingId = (int)($_POST['booking_id'] ?? 0);

                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "SELECT id, photo_path
                     FROM dj_season_bookings
                     WHERE id = ? AND season = ?
                     FOR UPDATE"
                );
                $stmt->execute([$bookingId, DESEO_DJ_SEASON]);
                $booking = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    throw new RuntimeException('Η αίτηση δεν βρέθηκε.');
                }

                $delete = $pdo->prepare("DELETE FROM dj_season_bookings WHERE id = ?");
                $delete->execute([$bookingId]);
                $pdo->commit();

                $photoPath = (string)($booking['photo_path'] ?? '');
                if (str_starts_with($photoPath, '/iluma/uploads/djs/')) {
                    $absolute = dirname(__DIR__) . $photoPath;
                    if (is_file($absolute)) @unlink($absolute);
                }

                $notice = 'Το inquiry διαγράφηκε. Δεν έγινε καμία αλλαγή στο Radio Program.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Season 6 admin action failed: ' . $e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Η ενέργεια δεν ολοκληρώθηκε.';
        }
    }
}

$slots = dj_season_slots($pdo, true);

$stmt = $pdo->prepare(
    "SELECT b.*, s.day_of_week, s.start_time, s.end_time
     FROM dj_season_bookings b
     INNER JOIN dj_season_slots s ON s.id = b.slot_id
     WHERE b.season = ?
     ORDER BY s.day_of_week ASC, s.start_time ASC, b.created_at ASC"
);
$stmt->execute([DESEO_DJ_SEASON]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$availableCount = 0;
foreach ($slots as $slot) {
    if (!empty($slot['available'])) $availableCount++;
}

admin_page_start('Season 6 DJs', 'dj-season');
?>
<div class="page-heading">
    <div>
        <span>Deseo Season 6</span>
        <h1>DJ applications</h1>
        <p>Διαχείριση inquiries, προτιμήσεων slot και επιλογής DJs για τη Season 6. Καμία ενέργεια εδώ δεν γράφει στο Radio Program.</p>
    </div>
    <a class="button button-secondary" href="/dj" target="_blank" rel="noopener">Open public form ↗</a>
</div>

<?php if ($notice): ?><div class="notice notice-success"><?= admin_e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

<section class="stats">
    <div class="stat"><strong><?= count($bookings) ?></strong><span>Applications</span></div>
    <div class="stat"><strong><?= $availableCount ?></strong><span>Available slots</span></div>
    <div class="stat"><strong><?= count($slots) ?></strong><span>Total slots</span></div>
    <div class="stat"><strong><?= DESEO_DJ_SEASON ?></strong><span>Season</span></div>
</section>

<section class="panel">
    <div class="page-heading" style="margin-bottom:20px">
        <div>
            <span>Inquiry availability</span>
            <h1 style="font-size:26px">Requested DJ slots</h1>
            <p>Τα slots είναι μόνο επιλογές προτίμησης για inquiries. Το Radio Program είναι ξεχωριστό και ενημερώνεται μόνο χειροκίνητα από εσένα.</p>
        </div>
    </div>

    <div class="program-list">
        <?php foreach ($slots as $slot): ?>
            <div class="program-row" style="grid-template-columns:minmax(0,1fr) auto">
                <div>
                    <h3><?= admin_e(dj_season_day_label((int)$slot['day_of_week'])) ?> · <?= admin_e(dj_season_format_time($slot['start_time'])) ?>–<?= admin_e(dj_season_format_time($slot['end_time'])) ?></h3>
                    <p>
                        <?php if (!empty($slot['approved_booking_id'])): ?>
                            <span class="day">APPROVED / CLOSED</span>
                        <?php else: ?>
                            OPEN FOR INQUIRIES
                        <?php endif; ?>
                    </p>
                </div>
                <span class="button button-secondary" style="display:inline-flex;align-items:center">
                    <?= !empty($slot['approved_booking_id']) ? 'Closed' : 'Open' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel">
    <div class="page-heading" style="margin-bottom:20px">
        <div>
            <span>Inquiries</span>
            <h1 style="font-size:26px">Season 6 inquiries</h1>
            <p>Μπορεί να υπάρχουν πολλά pending inquiries για το ίδιο slot. Approved κλείνει το weekly slot. Guest και Rejected δεν κλείνουν slot. Καμία επιλογή δεν γράφει αυτόματα στο Radio Program.</p>
        </div>
    </div>

    <?php if (!$bookings): ?>
        <div class="empty-admin">Δεν υπάρχουν ακόμη Season 6 inquiries.</div>
    <?php else: ?>
        <div style="display:grid;gap:14px">
            <?php foreach ($bookings as $booking): ?>
                <article class="panel" style="margin:0;background:#0c0c0c">
                    <div style="display:grid;grid-template-columns:92px minmax(0,1fr);gap:18px;align-items:start">
                        <img src="<?= admin_e($booking['photo_path']) ?>" alt="" style="width:92px;height:92px;object-fit:cover;border-radius:18px;background:#070707">
                        <div>
                            <div style="display:flex;justify-content:space-between;gap:14px;align-items:start;flex-wrap:wrap">
                                <div>
                                    <span style="color:#ff3038;font-size:10px;font-weight:800;letter-spacing:.12em">
                                        REQUESTED · <?= admin_e(dj_season_day_label((int)$booking['day_of_week'])) ?> ·
                                        <?= admin_e(dj_season_format_time($booking['start_time'])) ?>–<?= admin_e(dj_season_format_time($booking['end_time'])) ?>
                                    </span>
                                    <?php if (!empty($booking['final_day_of_week']) && !empty($booking['final_start_time']) && !empty($booking['final_end_time'])): ?>
                                        <span style="display:block;margin-top:5px;color:#aaa;font-size:10px;font-weight:700;letter-spacing:.08em">
                                            FINAL · <?= admin_e(dj_season_day_label((int)$booking['final_day_of_week'])) ?> ·
                                            <?= admin_e(dj_season_format_time($booking['final_start_time'])) ?>–<?= admin_e(dj_season_format_time($booking['final_end_time'])) ?>
                                        </span>
                                    <?php endif; ?>
                                    <h2 style="margin:5px 0 3px;font-size:22px"><?= admin_e($booking['artist_name']) ?></h2>
                                    <p style="margin:0;color:#777;font-size:12px"><?= admin_e($booking['full_name']) ?> · <a href="mailto:<?= admin_e($booking['email']) ?>"><?= admin_e($booking['email']) ?></a></p>
                                </div>
                                <strong style="font-size:11px;text-transform:uppercase;color:#aaa"><?= admin_e($booking['status']) ?></strong>
                            </div>

                            <p style="margin:14px 0 0;color:#aaa;font-size:12px;line-height:1.65;white-space:pre-wrap"><?= admin_e($booking['bio']) ?></p>

                            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:13px">
                                <a class="button button-primary" href="dj-photo.php?id=<?= (int)$booking['id'] ?>">Download photo ↓</a>
                                <?php if (!empty($booking['instagram'])): ?><a class="button button-secondary" href="<?= admin_e($booking['instagram']) ?>" target="_blank" rel="noopener">Social ↗</a><?php endif; ?>
                                <?php if (!empty($booking['website'])): ?><a class="button button-secondary" href="<?= admin_e($booking['website']) ?>" target="_blank" rel="noopener">Website ↗</a><?php endif; ?>
                                <?php if (!empty($booking['work_sample_url'])): ?><a class="button button-primary" href="<?= admin_e($booking['work_sample_url']) ?>" target="_blank" rel="noopener noreferrer">Listen / view sample ↗</a><?php endif; ?>
                                <span class="button button-secondary" style="display:inline-flex;align-items:center"><?= admin_e($booking['set_type']) ?></span>
                            </div>

                            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,.08)">
                                <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:end;width:100%">
                                    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">

                                    <?php
                                    $effectiveDay = !empty($booking['final_day_of_week']) ? (int)$booking['final_day_of_week'] : (int)$booking['day_of_week'];
                                    $effectiveStart = !empty($booking['final_start_time']) ? dj_season_format_time($booking['final_start_time']) : dj_season_format_time($booking['start_time']);
                                    $effectiveEnd = !empty($booking['final_end_time']) ? dj_season_format_time($booking['final_end_time']) : dj_season_format_time($booking['end_time']);
                                    ?>

                                    <label style="display:grid;gap:5px;color:#777;font-size:9px;font-weight:800;letter-spacing:.08em">
                                        FINAL DAY
                                        <select name="final_day_of_week" style="min-height:42px;border-radius:12px;background:#080808;color:#fff;border:1px solid rgba(255,255,255,.1);padding:0 10px">
                                            <?php foreach ([4,5,6,7] as $day): ?>
                                                <option value="<?= $day ?>" <?= $effectiveDay === $day ? 'selected' : '' ?>><?= admin_e(dj_season_day_label($day)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <label style="display:grid;gap:5px;color:#777;font-size:9px;font-weight:800;letter-spacing:.08em">
                                        START
                                        <input type="time" name="final_start_time" value="<?= admin_e($effectiveStart) ?>" required
                                               style="min-height:42px;border-radius:12px;background:#080808;color:#fff;border:1px solid rgba(255,255,255,.1);padding:0 10px">
                                    </label>

                                    <label style="display:grid;gap:5px;color:#777;font-size:9px;font-weight:800;letter-spacing:.08em">
                                        END
                                        <input type="time" name="final_end_time" value="<?= admin_e($effectiveEnd) ?>" required
                                               style="min-height:42px;border-radius:12px;background:#080808;color:#fff;border:1px solid rgba(255,255,255,.1);padding:0 10px">
                                    </label>

                                    <label style="display:grid;gap:5px;color:#777;font-size:9px;font-weight:800;letter-spacing:.08em">
                                        STATUS
                                        <select name="status" style="min-height:42px;border-radius:12px;background:#080808;color:#fff;border:1px solid rgba(255,255,255,.1);padding:0 10px">
                                            <?php foreach (['pending', 'approved', 'guest', 'rejected'] as $status): ?>
                                                <option value="<?= $status ?>" <?= $booking['status'] === $status ? 'selected' : '' ?>><?= strtoupper($status) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;align-items:center">
                                        <span style="margin-right:auto;color:#555;font-size:10px">Το final slot χρησιμοποιείται στο Approved email και στο availability check.</span>
                                        <button class="button button-primary" type="submit">Save schedule / status</button>
                                    </div>
                                </form>

                                <form method="post" data-deseo-confirm="Να διαγραφεί οριστικά αυτό το inquiry;" data-deseo-confirm-title="Οριστική διαγραφή inquiry" data-deseo-confirm-label="Διαγραφή" data-deseo-confirm-danger>
                                    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="release_booking">
                                    <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                                    <button class="button button-danger" type="submit">Delete inquiry</button>
                                </form>
                            </div>

                            <p style="margin:12px 0 0;color:#505050;font-size:9px">
                                Terms: <?= admin_e($booking['terms_version']) ?> · <?= admin_e($booking['terms_accepted_at']) ?> ·
                                Privacy: <?= admin_e($booking['privacy_version']) ?> · <?= admin_e($booking['privacy_acknowledged_at']) ?>
                            </p>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php admin_page_end(); ?>
