<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/admin-ui.php';

deseo_mylive_bootstrap($pdo);

// Backfill / sync any Season 6 DJs that were already approved before the
// pending-access workflow was introduced.
try {
    $approvedStmt = $pdo->prepare(
        "SELECT id
         FROM dj_season_bookings
         WHERE season = ? AND status IN ('approved','guest')"
    );
    $approvedStmt->execute([DESEO_DJ_SEASON]);
    foreach ($approvedStmt->fetchAll(PDO::FETCH_COLUMN) as $approvedBookingId) {
        deseo_mylive_create_pending_from_booking($pdo, (int)$approvedBookingId);
    }
} catch (Throwable $syncError) {
    error_log('MyLive Approved/Guest DJ backfill failed: ' . $syncError->getMessage());
}

$notice = null;
$error = null;
$generatedCredentials = null;

function mylive_admin_temp_password(): string {
    return strtoupper(bin2hex(random_bytes(8)));
}

function mylive_admin_time(string $value, string $label): string {
    $value = trim($value);
    $time = DateTimeImmutable::createFromFormat('!H:i', $value);
    if (!$time || $time->format('H:i') !== $value) {
        throw new RuntimeException('Μη έγκυρη ώρα στο πεδίο ' . $label . '.');
    }
    return $time->format('H:i:s');
}

function mylive_admin_account(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM dj_portal_accounts WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) throw new RuntimeException('Το MyLive account δεν βρέθηκε.');
    return $account;
}

function mylive_admin_asset_extension(string $name): string {
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

function mylive_admin_remove_tree(string $directory, string $storageRoot): void {
    $storageRootReal = realpath($storageRoot);
    $directoryReal = realpath($directory);
    if (!$storageRootReal || !$directoryReal || !str_starts_with($directoryReal, $storageRootReal . DIRECTORY_SEPARATOR)) {
        return;
    }

    $items = scandir($directoryReal);
    if ($items === false) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $directoryReal . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            mylive_admin_remove_tree($path, $storageRootReal);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($directoryReal);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Η συνεδρία έληξε. Ανανέωσε τη σελίδα και δοκίμασε ξανά.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        try {
            if ($action === 'create_account') {
                $artistName = trim((string)($_POST['artist_name'] ?? ''));
                $fullName = trim((string)($_POST['full_name'] ?? ''));
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $day = (int)($_POST['day_of_week'] ?? 0);
                $start = mylive_admin_time((string)($_POST['start_time'] ?? ''), 'Start');
                $end = mylive_admin_time((string)($_POST['end_time'] ?? ''), 'End');

                if ($artistName === '') throw new RuntimeException('Συμπλήρωσε Artist / DJ Name.');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Συμπλήρωσε έγκυρο email.');
                if ($day < 1 || $day > 7) throw new RuntimeException('Επίλεξε ημέρα.');
                if ($end <= $start) throw new RuntimeException('Η ώρα λήξης πρέπει να είναι μετά την ώρα έναρξης.');

                $check = $pdo->prepare("SELECT id FROM dj_portal_accounts WHERE LOWER(email) = ? LIMIT 1");
                $check->execute([$email]);
                if ($check->fetchColumn()) throw new RuntimeException('Υπάρχει ήδη MyLive account με αυτό το email.');

                $temporaryPassword = mylive_admin_temp_password();
                $insert = $pdo->prepare(
                    "INSERT INTO dj_portal_accounts
                     (booking_id, artist_name, full_name, email, day_of_week, start_time, end_time, password_hash, must_change_password, is_active, account_status)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 1, 1, 'active')"
                );
                $insert->execute([
                    $artistName,
                    $fullName,
                    $email,
                    $day,
                    $start,
                    $end,
                    password_hash($temporaryPassword, PASSWORD_DEFAULT)
                ]);
                $accountId = (int)$pdo->lastInsertId();
                $account = mylive_admin_account($pdo, $accountId);

                $generatedCredentials = [
                    'artist' => $artistName,
                    'email' => $email,
                    'password' => $temporaryPassword
                ];

                try {
                    $mail = deseo_mylive_onboarding_email($account, $temporaryPassword);
                    deseo_send_smtp_mail(
                        $email,
                        $artistName,
                        $mail['subject'],
                        $mail['html'],
                        $mail['text']
                    );
                    $pdo->prepare(
                        "UPDATE dj_portal_accounts
                         SET onboarding_email_sent_at = NOW(), access_email_sent_at = NOW()
                         WHERE id = ?"
                    )->execute([$accountId]);
                    $notice = 'Το MyLive account δημιουργήθηκε και στάλθηκε αυτόματα το ενιαίο onboarding email.';
                } catch (Throwable $mailError) {
                    error_log('MyLive onboarding email failed: ' . $mailError->getMessage());
                    $notice = 'Το MyLive account δημιουργήθηκε, αλλά το onboarding email δεν στάλθηκε.';
                    $error = 'SMTP: ' . $mailError->getMessage();
                }

            } elseif ($action === 'approve_pending') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $account = mylive_admin_account($pdo, $accountId);

                if ((string)($account['account_status'] ?? '') !== 'pending') {
                    throw new RuntimeException('Το συγκεκριμένο MyLive record δεν είναι πλέον Pending.');
                }

                $temporaryPassword = mylive_admin_temp_password();
                $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);

                $pdo->prepare(
                    "UPDATE dj_portal_accounts
                     SET password_hash = ?,
                         must_change_password = 1,
                         is_active = 1,
                         account_status = 'active'
                     WHERE id = ? AND account_status = 'pending'"
                )->execute([$passwordHash, $accountId]);

                $account = mylive_admin_account($pdo, $accountId);

                try {
                    $mail = deseo_mylive_onboarding_email($account, $temporaryPassword);
                    deseo_send_smtp_mail(
                        (string)$account['email'],
                        (string)$account['artist_name'],
                        $mail['subject'],
                        $mail['html'],
                        $mail['text']
                    );

                    $pdo->prepare(
                        "UPDATE dj_portal_accounts
                         SET onboarding_email_sent_at = NOW(), access_email_sent_at = NOW()
                         WHERE id = ?"
                    )->execute([$accountId]);

                    $generatedCredentials = [
                        'artist' => (string)$account['artist_name'],
                        'email' => (string)$account['email'],
                        'password' => $temporaryPassword
                    ];
                    $notice = 'Το Pending DJ εγκρίθηκε στο MyLive, δημιουργήθηκε temporary password και στάλθηκε το onboarding email.';
                } catch (Throwable $mailError) {
                    $pdo->prepare(
                        "UPDATE dj_portal_accounts
                         SET password_hash = '',
                             must_change_password = 1,
                             is_active = 0,
                             account_status = 'pending'
                         WHERE id = ?"
                    )->execute([$accountId]);

                    error_log('MyLive pending approval email failed: ' . $mailError->getMessage());
                    $error = 'Η πρόσβαση δεν ενεργοποιήθηκε επειδή το onboarding email δεν μπόρεσε να σταλεί: ' . $mailError->getMessage();
                }

            } elseif ($action === 'update_account') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $artistName = trim((string)($_POST['artist_name'] ?? ''));
                $fullName = trim((string)($_POST['full_name'] ?? ''));
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $day = (int)($_POST['day_of_week'] ?? 0);
                $start = mylive_admin_time((string)($_POST['start_time'] ?? ''), 'Start');
                $end = mylive_admin_time((string)($_POST['end_time'] ?? ''), 'End');

                if ($artistName === '') throw new RuntimeException('Συμπλήρωσε Artist / DJ Name.');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Μη έγκυρο email.');
                if ($day < 1 || $day > 7) throw new RuntimeException('Επίλεξε ημέρα.');
                if ($end <= $start) throw new RuntimeException('Η ώρα λήξης πρέπει να είναι μετά την ώρα έναρξης.');

                $stmt = $pdo->prepare(
                    "UPDATE dj_portal_accounts
                     SET artist_name = ?, full_name = ?, email = ?, day_of_week = ?, start_time = ?, end_time = ?
                     WHERE id = ?"
                );
                $stmt->execute([$artistName, $fullName, $email, $day, $start, $end, $accountId]);
                $notice = 'Τα στοιχεία του MyLive account ενημερώθηκαν.';

            } elseif ($action === 'reset_access') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $account = mylive_admin_account($pdo, $accountId);
                $temporaryPassword = mylive_admin_temp_password();

                $pdo->prepare(
                    "UPDATE dj_portal_accounts
                     SET password_hash = ?, must_change_password = 1, is_active = 1, account_status = 'active'
                     WHERE id = ?"
                )->execute([password_hash($temporaryPassword, PASSWORD_DEFAULT), $accountId]);

                $account = mylive_admin_account($pdo, $accountId);
                $mail = deseo_mylive_onboarding_email($account, $temporaryPassword, true);
                deseo_send_smtp_mail(
                    (string)$account['email'],
                    (string)$account['artist_name'],
                    $mail['subject'],
                    $mail['html'],
                    $mail['text']
                );
                $pdo->prepare(
                    "UPDATE dj_portal_accounts
                     SET onboarding_email_sent_at = NOW(), access_email_sent_at = NOW()
                     WHERE id = ?"
                )->execute([$accountId]);

                $generatedCredentials = [
                    'artist' => (string)$account['artist_name'],
                    'email' => (string)$account['email'],
                    'password' => $temporaryPassword
                ];
                $notice = 'Δημιουργήθηκε νέο temporary password και στάλθηκε ξανά το ενιαίο onboarding email.';

            } elseif ($action === 'toggle_account') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $active = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;
                $pdo->prepare(
                    "UPDATE dj_portal_accounts
                     SET is_active = ?, account_status = ?
                     WHERE id = ? AND account_status <> 'pending'"
                )->execute([$active, $active ? 'active' : 'disabled', $accountId]);
                $notice = $active ? 'Το MyLive account ενεργοποιήθηκε.' : 'Το MyLive account απενεργοποιήθηκε.';

            } elseif ($action === 'delete_account') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $account = mylive_admin_account($pdo, $accountId);

                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM dj_portal_accounts WHERE id = ?")->execute([$accountId]);
                    $pdo->commit();
                } catch (Throwable $deleteError) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $deleteError;
                }

                $storageRoot = dirname(__DIR__) . '/mylive/storage';
                mylive_admin_remove_tree($storageRoot . '/' . $accountId, $storageRoot);
                mylive_admin_remove_tree($storageRoot . '/assets/' . $accountId, $storageRoot);

                $notice = 'Το MyLive account του ' . (string)$account['artist_name'] . ' διαγράφηκε μαζί με τα DJ Sets και τα προσωπικά assets. Η DJ αίτηση και το Radio Program δεν επηρεάστηκαν.';

            } elseif ($action === 'upload_asset') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $account = mylive_admin_account($pdo, $accountId);
                $type = (string)($_POST['asset_type'] ?? 'other');
                $title = trim((string)($_POST['title'] ?? ''));
                if (!in_array($type, ['artwork', 'dj_spot', 'dj_spot_30', 'other'], true)) $type = 'other';
                if ($title === '') $title = deseo_mylive_asset_label($type);

                if (!isset($_FILES['asset_file']) || !is_array($_FILES['asset_file'])) {
                    throw new RuntimeException('Επίλεξε αρχείο asset.');
                }
                $file = $_FILES['asset_file'];
                if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Το asset upload δεν ολοκληρώθηκε.');
                }

                $size = (int)($file['size'] ?? 0);
                if ($size < 1 || $size > DESEO_MYLive_ASSET_MAX_BYTES) {
                    throw new RuntimeException('Το asset πρέπει να είναι έως 256 MB.');
                }

                $originalName = basename((string)($file['name'] ?? ''));
                $extension = mylive_admin_asset_extension($originalName);
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'mp3', 'wav'];
                if (!in_array($extension, $allowed, true)) {
                    throw new RuntimeException('Επιτρέπονται JPG, PNG, WEBP, PDF, MP3 και WAV.');
                }

                $tmp = (string)($file['tmp_name'] ?? '');
                if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Μη έγκυρο upload.');

                $mime = '';
                if (class_exists('finfo')) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = (string)$finfo->file($tmp);
                }

                $dir = dirname(__DIR__) . '/mylive/storage/assets/' . $accountId;
                if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                    throw new RuntimeException('Δεν ήταν δυνατή η δημιουργία asset folder.');
                }

                $storedName = deseo_mylive_slug((string)$account['artist_name'])
                    . '_' . strtoupper($type) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $extension;
                $absolute = $dir . '/' . $storedName;
                if (!move_uploaded_file($tmp, $absolute)) throw new RuntimeException('Δεν ήταν δυνατή η αποθήκευση του asset.');

                $relative = 'storage/assets/' . $accountId . '/' . $storedName;
                $stmt = $pdo->prepare(
                    "INSERT INTO dj_portal_assets
                     (account_id, asset_type, title, original_name, stored_name, file_path, file_size, mime_type)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$accountId, $type, $title, $originalName, $storedName, $relative, $size, $mime]);
                $notice = 'Το asset ανέβηκε στο MyLive του ' . (string)$account['artist_name'] . '.';

            } elseif ($action === 'delete_asset') {
                $assetId = (int)($_POST['asset_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT file_path FROM dj_portal_assets WHERE id = ? LIMIT 1");
                $stmt->execute([$assetId]);
                $asset = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$asset) throw new RuntimeException('Το asset δεν βρέθηκε.');

                $pdo->prepare("DELETE FROM dj_portal_assets WHERE id = ?")->execute([$assetId]);
                $path = dirname(__DIR__) . '/mylive/' . ltrim((string)$asset['file_path'], '/');
                if (is_file($path)) @unlink($path);
                $notice = 'Το asset διαγράφηκε.';

            } elseif ($action === 'update_set') {
                $setId = (int)($_POST['set_id'] ?? 0);
                $status = (string)($_POST['status'] ?? 'received');
                $note = trim((string)($_POST['admin_note'] ?? ''));
                if (!in_array($status, ['received', 'checked', 'scheduled', 'needs_changes'], true)) {
                    throw new RuntimeException('Μη έγκυρο status.');
                }
                $pdo->prepare("UPDATE dj_portal_sets SET status = ?, admin_note = ? WHERE id = ?")
                    ->execute([$status, $note, $setId]);
                $notice = 'Το status του DJ Set ενημερώθηκε.';
            }
        } catch (Throwable $e) {
            error_log('MyLive admin action failed: ' . $e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Η ενέργεια δεν ολοκληρώθηκε.';
        }
    }
}

$accountsStmt = $pdo->query(
    "SELECT a.*,
            b.instagram AS application_instagram,
            b.website AS application_website,
            b.work_sample_url AS application_work_sample,
            b.bio AS application_bio,
            b.set_type AS application_set_type,
            b.photo_path AS application_photo,
            b.status AS application_status,
            (SELECT COUNT(*) FROM dj_portal_sets s WHERE s.account_id = a.id) AS set_count,
            (SELECT COUNT(*) FROM dj_portal_assets x WHERE x.account_id = a.id) AS asset_count
     FROM dj_portal_accounts a
     LEFT JOIN dj_season_bookings b ON b.id = a.booking_id
     ORDER BY
        CASE a.account_status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
        a.artist_name ASC,
        a.id DESC"
);
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);
$pendingAccounts = array_values(array_filter(
    $accounts,
    static fn(array $account): bool => (string)($account['account_status'] ?? '') === 'pending'
));
$managedAccounts = array_values(array_filter(
    $accounts,
    static fn(array $account): bool => (string)($account['account_status'] ?? '') !== 'pending'
));

$assetsByAccount = [];
$setsByAccount = [];
foreach ($accounts as $account) {
    $accountId = (int)$account['id'];
    $assetsByAccount[$accountId] = deseo_mylive_assets($pdo, $accountId);

    $stmt = $pdo->prepare(
        "SELECT id, episode_no, stored_name, file_size, status, admin_note, uploaded_at
         FROM dj_portal_sets WHERE account_id = ? ORDER BY episode_no DESC LIMIT 8"
    );
    $stmt->execute([$accountId]);
    $setsByAccount[$accountId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

admin_page_start('MyLive', 'mylive');
?>
<div class="page-heading mylive-admin-heading">
    <div>
        <span>Deseo Radio · DJ Delivery</span>
        <h1>MyLive accounts</h1>
        <p>Δημιούργησε DJ access, στείλε αυτόματα ένα πλήρες branded onboarding email και διαχειρίσου sets, artwork και imaging από ένα σημείο.</p>
    </div>
    <a class="button button-secondary" href="/mylive/" target="_blank" rel="noopener">Open MyLive ↗</a>
</div>

<?php if ($notice): ?><div class="notice notice-success"><?= admin_e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

<?php if ($generatedCredentials): ?>
    <section class="mylive-credentials-admin">
        <span>TEMPORARY ACCESS · COPY NOW</span>
        <strong><?= admin_e($generatedCredentials['artist']) ?></strong>
        <div><b>Email</b><code><?= admin_e($generatedCredentials['email']) ?></code></div>
        <div><b>Temporary password</b><code><?= admin_e($generatedCredentials['password']) ?></code></div>
        <small>Ο DJ θα υποχρεωθεί να δημιουργήσει προσωπικό password στην πρώτη είσοδο.</small>
    </section>
<?php endif; ?>

<?php if ($pendingAccounts): ?>
<section class="panel mylive-pending-panel">
    <div class="mylive-panel-head">
        <div>
            <span>APPROVED / GUEST APPLICATIONS · PENDING ACCESS</span>
            <h2>Ready for MyLive</h2>
            <p>Οι DJs αυτοί έχουν ήδη εγκριθεί ως Approved ή Guest από τις Season 6 αιτήσεις. Τα στοιχεία τους έχουν μεταφερθεί αυτόματα εδώ, αλλά δεν έχουν ακόμη login ή password.</p>
        </div>
        <strong><?= count($pendingAccounts) ?></strong>
    </div>

    <div class="mylive-pending-list">
        <?php foreach ($pendingAccounts as $pending): ?>
            <article class="mylive-pending-card">
                <div class="mylive-pending-main">
                    <?php if (!empty($pending['application_photo'])): ?>
                        <img src="<?= admin_e((string)$pending['application_photo']) ?>" alt="">
                    <?php else: ?>
                        <div class="mylive-avatar"><?= admin_e(strtoupper(substr((string)$pending['artist_name'], 0, 1))) ?></div>
                    <?php endif; ?>

                    <div class="mylive-pending-copy">
                        <span><?= admin_e(strtoupper((string)($pending['application_status'] ?? 'approved'))) ?> · PENDING ACCESS · <?= admin_e(deseo_mylive_slot($pending)) ?></span>
                        <h3><?= admin_e($pending['artist_name']) ?></h3>
                        <p><?= admin_e($pending['full_name']) ?> · <?= admin_e($pending['email']) ?></p>

                        <div class="mylive-pending-tags">
                            <?php if (!empty($pending['application_set_type'])): ?><span><?= admin_e($pending['application_set_type']) ?></span><?php endif; ?>
                            <?php if (!empty($pending['application_instagram'])): ?><a href="<?= admin_e($pending['application_instagram']) ?>" target="_blank" rel="noopener">Social ↗</a><?php endif; ?>
                            <?php if (!empty($pending['application_website'])): ?><a href="<?= admin_e($pending['application_website']) ?>" target="_blank" rel="noopener">Website ↗</a><?php endif; ?>
                            <?php if (!empty($pending['application_work_sample'])): ?><a href="<?= admin_e($pending['application_work_sample']) ?>" target="_blank" rel="noopener noreferrer">Work sample ↗</a><?php endif; ?>
                            <?php if (!empty($pending['booking_id'])): ?><a href="dj-photo.php?id=<?= (int)$pending['booking_id'] ?>">Photo ↓</a><?php endif; ?>
                        </div>

                        <?php if (!empty($pending['application_bio'])): ?>
                            <p class="mylive-pending-bio"><?= admin_e($pending['application_bio']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mylive-pending-action">
                    <div>
                        <span>NO LOGIN YET</span>
                        <p>Με το approve δημιουργείται temporary password, ενεργοποιείται το MyLive και στέλνεται αυτόματα το ενιαίο onboarding email.</p>
                    </div>
                    <form method="post" onsubmit="return confirm('Να ενεργοποιηθεί το MyLive για <?= admin_e($pending['artist_name']) ?> και να σταλεί το onboarding email με temporary credentials;');">
                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                        <input type="hidden" name="action" value="approve_pending">
                        <input type="hidden" name="account_id" value="<?= (int)$pending['id'] ?>">
                        <button class="button button-primary" type="submit">Approve & Create Access</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="panel mylive-create-panel">
    <div class="mylive-panel-head">
        <div>
            <span>MANUAL / STANDALONE ACCOUNT</span>
            <h2>Create access manually</h2>
            <p>Χρησιμοποίησέ το μόνο για DJ που δεν προέρχεται από τις Season 6 αιτήσεις. Για Approved ή Guest applications χρησιμοποίησε το Pending Access queue παραπάνω.</p>
        </div>
        <strong>01</strong>
    </div>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="create_account">

        <div class="form-grid">
            <div class="field">
                <label>Artist / DJ Name</label>
                <input type="text" name="artist_name" required placeholder="Non Grata">
            </div>
            <div class="field">
                <label>Full name</label>
                <input type="text" name="full_name" placeholder="Optional">
            </div>
            <div class="field full">
                <label>Email</label>
                <input type="email" name="email" required placeholder="dj@example.com">
            </div>
        </div>

        <div class="mylive-slot-editor">
            <div class="field">
                <label>Day</label>
                <select name="day_of_week" required>
                    <option value="">Select day</option>
                    <?php foreach ([1,2,3,4,5,6,7] as $day): ?>
                        <option value="<?= $day ?>"><?= admin_e(dj_season_day_label($day)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Start</label>
                <input type="time" name="start_time" required>
            </div>
            <div class="field">
                <label>End</label>
                <input type="time" name="end_time" required>
            </div>
        </div>

        <div class="mylive-email-preview-strip">
            <div><span>ONBOARDING EMAIL</span><strong>Season 6 Instructions · MyLive · Credentials</strong></div>
        </div>

        <div class="form-actions">
            <button class="button button-primary" type="submit">Create account & send onboarding</button>
        </div>
    </form>
</section>

<section class="mylive-admin-list">
<?php if (!$managedAccounts): ?>
    <div class="empty-admin">Δεν υπάρχουν ακόμη ενεργά ή απενεργοποιημένα MyLive accounts.</div>
<?php else: ?>
    <?php foreach ($managedAccounts as $account): ?>
        <?php
        $accountId = (int)$account['id'];
        $slot = deseo_mylive_slot($account);
        ?>
        <article class="panel mylive-account-card">
            <div class="mylive-account-top">
                <div class="mylive-account-identity">
                    <div class="mylive-avatar"><?= admin_e(strtoupper(substr((string)$account['artist_name'], 0, 1))) ?></div>
                    <div>
                        <span><?= admin_e(strtoupper((string)($account['account_status'] ?? (!empty($account['is_active']) ? 'active' : 'disabled')))) ?> · <?= admin_e($slot) ?></span>
                        <h2><?= admin_e($account['artist_name']) ?></h2>
                        <p><?= admin_e($account['email']) ?></p>
                    </div>
                </div>
                <div class="mylive-account-stats">
                    <div><strong><?= (int)$account['set_count'] ?></strong><span>Sets</span></div>
                    <div><strong><?= (int)$account['asset_count'] ?></strong><span>Assets</span></div>
                </div>
            </div>

            <div class="mylive-account-meta">
                <span>Onboarding: <?= $account['onboarding_email_sent_at'] ? admin_e((string)$account['onboarding_email_sent_at']) : 'Not sent' ?></span>
                <span>Password: <?= !empty($account['must_change_password']) ? 'Temporary / change required' : 'Personal password set' ?></span>
                <span>Last login: <?= $account['last_login_at'] ? admin_e((string)$account['last_login_at']) : 'Never' ?></span>
            </div>

            <details class="mylive-admin-details">
                <summary>Edit account & access</summary>
                <div class="mylive-details-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_account">
                        <input type="hidden" name="account_id" value="<?= $accountId ?>">

                        <div class="form-grid">
                            <div class="field">
                                <label>Artist / DJ Name</label>
                                <input type="text" name="artist_name" value="<?= admin_e($account['artist_name']) ?>" required>
                            </div>
                            <div class="field">
                                <label>Full name</label>
                                <input type="text" name="full_name" value="<?= admin_e($account['full_name']) ?>">
                            </div>
                            <div class="field full">
                                <label>Email</label>
                                <input type="email" name="email" value="<?= admin_e($account['email']) ?>" required>
                            </div>
                        </div>

                        <div class="mylive-slot-editor">
                            <div class="field">
                                <label>Day</label>
                                <select name="day_of_week" required>
                                    <?php foreach ([1,2,3,4,5,6,7] as $day): ?>
                                        <option value="<?= $day ?>" <?= (int)$account['day_of_week'] === $day ? 'selected' : '' ?>><?= admin_e(dj_season_day_label($day)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Start</label>
                                <input type="time" name="start_time" value="<?= admin_e(deseo_mylive_format_time((string)$account['start_time'])) ?>" required>
                            </div>
                            <div class="field">
                                <label>End</label>
                                <input type="time" name="end_time" value="<?= admin_e(deseo_mylive_format_time((string)$account['end_time'])) ?>" required>
                            </div>
                        </div>
                        <div class="form-actions"><button class="button button-secondary" type="submit">Save account</button></div>
                    </form>

                    <div class="mylive-admin-actions">
                        <form method="post" onsubmit="return confirm('Να εκδοθεί νέο temporary password και να σταλεί ξανά το πλήρες onboarding email;');">
                            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                            <input type="hidden" name="action" value="reset_access">
                            <input type="hidden" name="account_id" value="<?= $accountId ?>">
                            <button class="button button-secondary" type="submit">Reset password & resend onboarding</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_account">
                            <input type="hidden" name="account_id" value="<?= $accountId ?>">
                            <input type="hidden" name="active" value="<?= !empty($account['is_active']) ? '0' : '1' ?>">
                            <button class="button <?= !empty($account['is_active']) ? 'button-danger' : 'button-primary' ?>" type="submit">
                                <?= !empty($account['is_active']) ? 'Disable account' : 'Enable account' ?>
                            </button>
                        </form>
                        <form method="post" onsubmit="return confirm('ΟΡΙΣΤΙΚΗ ΔΙΑΓΡΑΦΗ: Θα διαγραφούν το MyLive account, όλα τα DJ Sets και όλα τα προσωπικά assets αυτού του DJ. Η αίτηση DJ και το Radio Program δεν θα επηρεαστούν. Συνέχεια;');">
                            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_account">
                            <input type="hidden" name="account_id" value="<?= $accountId ?>">
                            <button class="button button-danger" type="submit">Delete MyLive user</button>
                        </form>
                    </div>
                </div>
            </details>

            <div class="mylive-admin-columns">
                <section class="mylive-subpanel">
                    <div class="mylive-subpanel-head">
                        <div><span>DJ DELIVERY</span><h3>Uploaded Sets</h3></div>
                        <strong><?= count($setsByAccount[$accountId]) ?></strong>
                    </div>

                    <?php if (empty($setsByAccount[$accountId])): ?>
                        <div class="mylive-mini-empty">No DJ Sets yet.</div>
                    <?php else: ?>
                        <div class="mylive-set-admin-list">
                        <?php foreach ($setsByAccount[$accountId] as $set): ?>
                            <form method="post" class="mylive-set-admin-row">
                                <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_set">
                                <input type="hidden" name="set_id" value="<?= (int)$set['id'] ?>">
                                <div class="mylive-set-copy">
                                    <span>EP<?= str_pad((string)(int)$set['episode_no'], 3, '0', STR_PAD_LEFT) ?></span>
                                    <strong><?= admin_e($set['stored_name']) ?></strong>
                                    <small><?= admin_e(deseo_mylive_format_bytes((int)$set['file_size'])) ?> · <?= admin_e((string)$set['uploaded_at']) ?></small>
                                </div>
                                <select name="status">
                                    <?php foreach (['received','checked','scheduled','needs_changes'] as $status): ?>
                                        <option value="<?= $status ?>" <?= $set['status'] === $status ? 'selected' : '' ?>><?= strtoupper(str_replace('_',' ', $status)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="admin_note" value="<?= admin_e($set['admin_note']) ?>" placeholder="Optional note">
                                <a class="button button-secondary" href="mylive-download.php?type=set&id=<?= (int)$set['id'] ?>">Download</a>
                                <button class="button button-secondary" type="submit">Save</button>
                            </form>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="mylive-subpanel">
                    <div class="mylive-subpanel-head">
                        <div><span>FROM DESEO / ILUMA Digital Agency</span><h3>DJ Assets</h3></div>
                        <strong><?= count($assetsByAccount[$accountId]) ?></strong>
                    </div>

                    <form method="post" enctype="multipart/form-data" class="mylive-asset-upload">
                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                        <input type="hidden" name="action" value="upload_asset">
                        <input type="hidden" name="account_id" value="<?= $accountId ?>">

                        <select name="asset_type">
                            <option value="artwork">Promotional Artwork</option>
                            <option value="dj_spot">Personal DJ Imaging</option>
                            <option value="dj_spot_30">30' Imaging</option>
                            <option value="other">Additional Asset</option>
                        </select>
                        <input type="text" name="title" placeholder="Optional custom title">
                        <input class="file-input" type="file" name="asset_file" accept=".jpg,.jpeg,.png,.webp,.pdf,.mp3,.wav" required>
                        <button class="button button-primary" type="submit">Add to MyLive</button>
                    </form>

                    <?php if (empty($assetsByAccount[$accountId])): ?>
                        <div class="mylive-mini-empty">No assets uploaded yet.</div>
                    <?php else: ?>
                        <div class="mylive-assets-admin-list">
                        <?php foreach ($assetsByAccount[$accountId] as $asset): ?>
                            <div class="mylive-asset-admin-row">
                                <div>
                                    <span><?= admin_e(deseo_mylive_asset_label((string)$asset['asset_type'])) ?></span>
                                    <strong><?= admin_e($asset['title']) ?></strong>
                                    <small><?= admin_e($asset['original_name']) ?> · <?= admin_e(deseo_mylive_format_bytes((int)$asset['file_size'])) ?></small>
                                </div>
                                <div class="mylive-asset-actions">
                                    <a class="button button-secondary" href="mylive-download.php?type=asset&id=<?= (int)$asset['id'] ?>">Download</a>
                                <form method="post" onsubmit="return confirm('Να διαγραφεί αυτό το asset από το MyLive;');">
                                    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_asset">
                                    <input type="hidden" name="asset_id" value="<?= (int)$asset['id'] ?>">
                                    <button class="danger-link" type="submit">Delete</button>
                                </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </article>
    <?php endforeach; ?>
<?php endif; ?>
</section>

<?php admin_page_end(); ?>
