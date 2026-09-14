<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-season.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/admin-ui.php';

dj_season_bootstrap($pdo);

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Η συνεδρία έληξε. Ανανέωσε τη σελίδα και δοκίμασε ξανά.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        try {
            if ($action === 'set_status') {
                $bookingId = (int)($_POST['booking_id'] ?? 0);
                $status = (string)($_POST['status'] ?? 'pending');
                if (!in_array($status, ['pending', 'approved', 'cancelled'], true)) {
                    throw new RuntimeException('Μη έγκυρο status.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "SELECT id, slot_id, status
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
                    "SELECT id
                     FROM dj_season_slots
                     WHERE id = ? AND season = ?
                     FOR UPDATE"
                );
                $slotStmt->execute([(int)$current['slot_id'], DESEO_DJ_SEASON]);
                if (!$slotStmt->fetchColumn()) {
                    throw new RuntimeException('Το slot της αίτησης δεν βρέθηκε.');
                }

                if ($status === 'approved') {
                    $check = $pdo->prepare(
                        "SELECT id
                         FROM dj_season_bookings
                         WHERE slot_id = ?
                           AND status = 'approved'
                           AND id <> ?
                         LIMIT 1
                         FOR UPDATE"
                    );
                    $check->execute([(int)$current['slot_id'], $bookingId]);
                    if ($check->fetchColumn()) {
                        throw new RuntimeException('Υπάρχει ήδη εγκεκριμένος DJ για αυτό το slot.');
                    }
                }

                $update = $pdo->prepare(
                    "UPDATE dj_season_bookings
                     SET status = ?
                     WHERE id = ? AND season = ?"
                );
                $update->execute([$status, $bookingId, DESEO_DJ_SEASON]);
                $previousStatus = (string)$current['status'];
                $pdo->commit();

                if ($status === 'approved' && $previousStatus !== 'approved') {
                    $mailStmt = $pdo->prepare(
                        "SELECT b.*, s.day_of_week, s.start_time, s.end_time
                         FROM dj_season_bookings b
                         INNER JOIN dj_season_slots s ON s.id = b.slot_id
                         WHERE b.id = ? AND b.season = ?
                         LIMIT 1"
                    );
                    $mailStmt->execute([$bookingId, DESEO_DJ_SEASON]);
                    $approvedBooking = $mailStmt->fetch(PDO::FETCH_ASSOC);

                    if ($approvedBooking) {
                        try {
                            $mail = deseo_dj_approval_email($approvedBooking);
                            deseo_send_smtp_mail(
                                (string)$approvedBooking['email'],
                                (string)$approvedBooking['artist_name'],
                                (string)$mail['subject'],
                                (string)$mail['html'],
                                (string)$mail['text']
                            );
                            $notice = 'Η αίτηση εγκρίθηκε, το slot έκλεισε για νέα inquiries και στάλθηκε branded confirmation email στον DJ. Δεν έγινε καμία αλλαγή στο Radio Program.';
                        } catch (Throwable $mailError) {
                            error_log('Season 6 approval email failed: ' . $mailError->getMessage());
                            $notice = 'Η αίτηση εγκρίθηκε και το slot έκλεισε για νέα inquiries. Δεν έγινε καμία αλλαγή στο Radio Program.';
                            $error = 'Το approval αποθηκεύτηκε, αλλά το email δεν στάλθηκε. Έλεγξε τα SMTP_* στοιχεία στο .env.';
                        }
                    }
                } else {
                    $notice = $status === 'approved'
                        ? 'Η αίτηση παραμένει Approved. Δεν στάλθηκε δεύτερο email.'
                        : 'Το status της αίτησης ενημερώθηκε. Το Radio Program παραμένει ανεξάρτητο.';
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
    <a class="button button-secondary" href="/djs" target="_blank" rel="noopener">Open public form ↗</a>
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
            <p>Μπορεί να υπάρχουν πολλά pending inquiries για το ίδιο slot. Μόνο ένα μπορεί να γίνει Approved. Η έγκριση δεν προσθέτει τον DJ στο Radio Program.</p>
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
                                        <?= admin_e(dj_season_day_label((int)$booking['day_of_week'])) ?> ·
                                        <?= admin_e(dj_season_format_time($booking['start_time'])) ?>–<?= admin_e(dj_season_format_time($booking['end_time'])) ?>
                                    </span>
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
                                <form method="post" style="display:flex;gap:8px;align-items:center">
                                    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                                    <select name="status" style="min-height:42px;border-radius:999px;background:#080808;color:#fff;border:1px solid rgba(255,255,255,.1);padding:0 12px">
                                        <?php foreach (['pending', 'approved', 'cancelled'] as $status): ?>
                                            <option value="<?= $status ?>" <?= $booking['status'] === $status ? 'selected' : '' ?>><?= strtoupper($status) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="button button-primary" type="submit">Save status</button>
                                </form>

                                <form method="post" onsubmit="return confirm('Να διαγραφεί οριστικά αυτό το inquiry;');">
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
