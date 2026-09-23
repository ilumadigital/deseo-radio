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
                if (!in_array($finalDay, [1, 2, 3, 4, 5, 6, 7], true)) {
                    throw new RuntimeException('Επίλεξε έγκυρη τελική ημέρα.');
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
     ORDER BY b.created_at DESC, b.id DESC"
);
$stmt->execute([DESEO_DJ_SEASON]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$availableCount = 0;
foreach ($slots as $slot) {
    if (!empty($slot['available'])) $availableCount++;
}

$statusCounts = [
    'pending' => 0,
    'approved' => 0,
    'guest' => 0,
    'rejected' => 0,
];
foreach ($bookings as $booking) {
    $bookingStatus = (string)($booking['status'] ?? 'pending');
    if (isset($statusCounts[$bookingStatus])) {
        $statusCounts[$bookingStatus]++;
    }
}

admin_page_start('Season 6 DJs', 'dj-season');
?>
<div class="page-heading season6-page-heading">
    <div>
        <span>Deseo Radio · Season 6</span>
        <h1>DJ Applications</h1>
        <p>Οι νεότερες αιτήσεις εμφανίζονται πρώτες. Άνοιξε μόνο όποια θέλεις να αξιολογήσεις και διαχειρίσου slot, status και MyLive flow από ένα σημείο.</p>
    </div>
    <a class="button button-secondary" href="/dj" target="_blank" rel="noopener">Open public form ↗</a>
</div>

<?php if ($notice): ?><div class="notice notice-success"><?= admin_e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

<section class="season6-stats">
    <article>
        <span>APPLICATIONS</span>
        <strong><?= count($bookings) ?></strong>
        <small>Season <?= DESEO_DJ_SEASON ?></small>
    </article>
    <article>
        <span>PENDING</span>
        <strong><?= (int)$statusCounts['pending'] ?></strong>
        <small>waiting for review</small>
    </article>
    <article>
        <span>APPROVED</span>
        <strong><?= (int)$statusCounts['approved'] ?></strong>
        <small>confirmed DJs</small>
    </article>
    <article>
        <span>AVAILABLE SLOTS</span>
        <strong><?= $availableCount ?></strong>
        <small>of <?= count($slots) ?> total</small>
    </article>
</section>

<section class="season6-applications">
    <div class="season6-list-head">
        <div>
            <span>APPLICATION PIPELINE</span>
            <h2>Latest applications</h2>
            <p>Newest first · η τελευταία αίτηση που μπήκε εμφανίζεται πάντα επάνω.</p>
        </div>

        <div class="season6-filter-bar" role="group" aria-label="Filter applications">
            <button type="button" class="is-active" data-season-filter="all">All <b><?= count($bookings) ?></b></button>
            <button type="button" data-season-filter="pending">Pending <b><?= (int)$statusCounts['pending'] ?></b></button>
            <button type="button" data-season-filter="approved">Approved <b><?= (int)$statusCounts['approved'] ?></b></button>
            <button type="button" data-season-filter="guest">Guest <b><?= (int)$statusCounts['guest'] ?></b></button>
            <button type="button" data-season-filter="rejected">Rejected <b><?= (int)$statusCounts['rejected'] ?></b></button>
        </div>
    </div>

    <?php if (!$bookings): ?>
        <div class="empty-admin">Δεν υπάρχουν ακόμη Season 6 applications.</div>
    <?php else: ?>
        <div class="season6-application-list">
            <?php foreach ($bookings as $index => $booking): ?>
                <?php
                $bookingStatus = (string)($booking['status'] ?? 'pending');
                $statusClass = 'is-pending';
                if ($bookingStatus === 'approved') {
                    $statusClass = 'is-approved';
                } elseif ($bookingStatus === 'guest') {
                    $statusClass = 'is-guest';
                } elseif ($bookingStatus === 'rejected') {
                    $statusClass = 'is-rejected';
                }
                $effectiveDay = !empty($booking['final_day_of_week']) ? (int)$booking['final_day_of_week'] : (int)$booking['day_of_week'];
                $effectiveStart = !empty($booking['final_start_time']) ? dj_season_format_time($booking['final_start_time']) : dj_season_format_time($booking['start_time']);
                $effectiveEnd = !empty($booking['final_end_time']) ? dj_season_format_time($booking['final_end_time']) : dj_season_format_time($booking['end_time']);
                $createdAt = !empty($booking['created_at']) ? strtotime((string)$booking['created_at']) : false;
                $submittedLabel = $createdAt ? date('d.m.Y · H:i', $createdAt) : '—';
                ?>
                <details class="season6-application-card" data-season-application data-status="<?= admin_e($bookingStatus) ?>" <?= $index === 0 ? 'open' : '' ?>>
                    <summary>
                        <div class="season6-summary-main">
                            <img src="<?= admin_e((string)$booking['photo_path']) ?>" alt="" loading="lazy">
                            <div class="season6-summary-copy">
                                <div class="season6-summary-topline">
                                    <span class="season6-status <?= admin_e($statusClass) ?>"><?= admin_e(strtoupper($bookingStatus)) ?></span>
                                    <small><?= admin_e($submittedLabel) ?></small>
                                </div>
                                <h3><?= admin_e((string)$booking['artist_name']) ?></h3>
                                <p><?= admin_e((string)$booking['full_name']) ?> · <?= admin_e((string)$booking['email']) ?></p>
                            </div>
                        </div>

                        <div class="season6-summary-slot">
                            <span>REQUESTED</span>
                            <strong>
                                <?= admin_e(dj_season_day_label((int)$booking['day_of_week'])) ?>
                                · <?= admin_e(dj_season_format_time((string)$booking['start_time'])) ?>
                            </strong>
                            <small><?= admin_e(dj_season_format_time((string)$booking['start_time'])) ?>–<?= admin_e(dj_season_format_time((string)$booking['end_time'])) ?></small>
                        </div>

                        <div class="season6-summary-toggle" aria-hidden="true">+</div>
                    </summary>

                    <div class="season6-application-body">
                        <div class="season6-application-info">
                            <section class="season6-about">
                                <span>ABOUT / BIO</span>
                                <p><?= nl2br(admin_e((string)$booking['bio'])) ?></p>
                            </section>

                            <section class="season6-contact-grid">
                                <div>
                                    <span>EMAIL</span>
                                    <a href="mailto:<?= admin_e((string)$booking['email']) ?>"><?= admin_e((string)$booking['email']) ?></a>
                                </div>
                                <div>
                                    <span>SET TYPE</span>
                                    <strong><?= admin_e((string)$booking['set_type']) ?></strong>
                                </div>
                                <div>
                                    <span>REQUESTED SLOT</span>
                                    <strong>
                                        <?= admin_e(dj_season_day_label((int)$booking['day_of_week'])) ?> ·
                                        <?= admin_e(dj_season_format_time((string)$booking['start_time'])) ?>–<?= admin_e(dj_season_format_time((string)$booking['end_time'])) ?>
                                    </strong>
                                </div>
                                <div>
                                    <span>CURRENT FINAL SLOT</span>
                                    <strong><?= admin_e(dj_season_day_label($effectiveDay)) ?> · <?= admin_e($effectiveStart) ?>–<?= admin_e($effectiveEnd) ?></strong>
                                </div>
                            </section>

                            <div class="season6-link-actions">
                                <a class="button button-primary button-compact" href="dj-photo.php?id=<?= (int)$booking['id'] ?>">Photo ↓</a>
                                <?php if (!empty($booking['work_sample_url'])): ?>
                                    <a class="button button-secondary button-compact" href="<?= admin_e((string)$booking['work_sample_url']) ?>" target="_blank" rel="noopener noreferrer">Listen / view sample ↗</a>
                                <?php endif; ?>
                                <?php if (!empty($booking['instagram'])): ?>
                                    <a class="button button-secondary button-compact" href="<?= admin_e((string)$booking['instagram']) ?>" target="_blank" rel="noopener">Social ↗</a>
                                <?php endif; ?>
                                <?php if (!empty($booking['website'])): ?>
                                    <a class="button button-secondary button-compact" href="<?= admin_e((string)$booking['website']) ?>" target="_blank" rel="noopener">Website ↗</a>
                                <?php endif; ?>
                            </div>

                            <div class="season6-legal-meta">
                                <span>Terms <?= admin_e((string)$booking['terms_version']) ?> · <?= admin_e((string)$booking['terms_accepted_at']) ?></span>
                                <span>Privacy <?= admin_e((string)$booking['privacy_version']) ?> · <?= admin_e((string)$booking['privacy_acknowledged_at']) ?></span>
                            </div>
                        </div>

                        <aside class="season6-decision-panel">
                            <div class="season6-decision-head">
                                <span>APPLICATION DECISION</span>
                                <h4>Schedule & status</h4>
                                <p>Το final slot χρησιμοποιείται στο email έγκρισης και στο availability check.</p>
                            </div>

                            <form method="post" class="season6-decision-form">
                                <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">

                                <label>
                                    <span>FINAL DAY</span>
                                    <select name="final_day_of_week">
                                        <?php foreach ([1,2,3,4,5,6,7] as $day): ?>
                                            <option value="<?= $day ?>" <?= $effectiveDay === $day ? 'selected' : '' ?>><?= admin_e(dj_season_day_label($day)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <div class="season6-time-grid">
                                    <label>
                                        <span>START</span>
                                        <input type="time" name="final_start_time" value="<?= admin_e($effectiveStart) ?>" required>
                                    </label>
                                    <label>
                                        <span>END</span>
                                        <input type="time" name="final_end_time" value="<?= admin_e($effectiveEnd) ?>" required>
                                    </label>
                                </div>

                                <label>
                                    <span>STATUS</span>
                                    <select name="status">
                                        <?php foreach (['pending', 'approved', 'guest', 'rejected'] as $status): ?>
                                            <option value="<?= $status ?>" <?= $bookingStatus === $status ? 'selected' : '' ?>><?= strtoupper($status) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <button class="button button-primary season6-save-button" type="submit">Save application</button>
                            </form>

                            <form method="post"
                                  class="season6-delete-form"
                                  data-deseo-confirm="Να διαγραφεί οριστικά αυτό το inquiry;"
                                  data-deseo-confirm-title="Οριστική διαγραφή inquiry"
                                  data-deseo-confirm-label="Διαγραφή"
                                  data-deseo-confirm-danger>
                                <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                <input type="hidden" name="action" value="release_booking">
                                <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                                <button class="button button-danger" type="submit">Delete inquiry</button>
                            </form>
                        </aside>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel season6-slots-panel">
    <div class="season6-panel-head">
        <div>
            <span>INQUIRY AVAILABILITY</span>
            <h2>Requested DJ slots</h2>
            <p>Τα slots αφορούν μόνο τις αιτήσεις. Το Radio Program παραμένει ανεξάρτητο.</p>
        </div>
        <strong><?= $availableCount ?> / <?= count($slots) ?></strong>
    </div>

    <div class="season6-slot-grid">
        <?php foreach ($slots as $slot): ?>
            <?php $isClosed = !empty($slot['approved_booking_id']); ?>
            <article class="season6-slot-card <?= $isClosed ? 'is-closed' : 'is-open' ?>">
                <span><?= $isClosed ? 'CLOSED' : 'OPEN' ?></span>
                <strong><?= admin_e(dj_season_day_label((int)$slot['day_of_week'])) ?></strong>
                <p><?= admin_e(dj_season_format_time((string)$slot['start_time'])) ?>–<?= admin_e(dj_season_format_time((string)$slot['end_time'])) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<script>
(function () {
    const filters = Array.from(document.querySelectorAll('[data-season-filter]'));
    const cards = Array.from(document.querySelectorAll('[data-season-application]'));

    filters.forEach(button => {
        button.addEventListener('click', () => {
            const status = button.dataset.seasonFilter || 'all';

            filters.forEach(item => item.classList.toggle('is-active', item === button));
            cards.forEach(card => {
                const visible = status === 'all' || card.dataset.status === status;
                card.hidden = !visible;
            });

            const firstVisible = cards.find(card => !card.hidden);
            if (firstVisible && !cards.some(card => !card.hidden && card.open)) {
                firstVisible.open = true;
            }
        });
    });
}());
</script>

<?php admin_page_end(); ?>
