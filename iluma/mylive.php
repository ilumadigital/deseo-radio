<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/admin-ui.php';

deseo_mylive_bootstrap($pdo);

try {
    deseo_mylive_cleanup_broadcasted_sets($pdo);
} catch (Throwable $retentionError) {
    error_log('MyLive admin retention cleanup failed: ' . $retentionError->getMessage());
}

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
                     (booking_id, artist_name, full_name, email, day_of_week, start_time, end_time, password_hash, must_change_password, is_active, account_status, show_audience_stats)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 1, 1, 'active', 0)"
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
                         account_status = 'active',
                         show_audience_stats = 0
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

            } elseif ($action === 'toggle_public_profile') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
                $account = mylive_admin_account($pdo, $accountId);
                $wasEnabled = !empty($account['public_profile_enabled']);

                if ((string)($account['account_status'] ?? '') === 'pending') {
                    throw new RuntimeException('Ενεργοποίησε πρώτα το MyLive access του DJ.');
                }

                if ($enabled) {
                    if ($wasEnabled) {
                        $notice = 'Το Public Profile είναι ήδη ενεργό για τον ' . (string)$account['artist_name'] . '.';
                    } else {
                        deseo_mylive_public_profile_ensure($pdo, $accountId);

                        $pdo->prepare(
                            "UPDATE dj_portal_accounts
                             SET public_profile_enabled = 1
                             WHERE id = ?"
                        )->execute([$accountId]);

                        try {
                            $mail = deseo_mylive_public_profile_enabled_email($account);
                            deseo_send_smtp_mail(
                                (string)$account['email'],
                                (string)$account['artist_name'],
                                $mail['subject'],
                                $mail['html'],
                                $mail['text']
                            );

                            $notice = 'Το Public Profile ενεργοποιήθηκε για τον '
                                . (string)$account['artist_name']
                                . ' και στάλθηκε ενημερωτικό email στο '
                                . (string)$account['email']
                                . '. Τα αρχικά στοιχεία εισήχθησαν από την αίτηση, όπου υπήρχαν.';
                        } catch (Throwable $mailError) {
                            $pdo->prepare(
                                "UPDATE dj_portal_accounts
                                 SET public_profile_enabled = 0
                                 WHERE id = ?"
                            )->execute([$accountId]);

                            error_log('MyLive Public Profile activation email failed: ' . $mailError->getMessage());

                            throw new RuntimeException(
                                'Το Public Profile δεν ενεργοποιήθηκε επειδή το ενημερωτικό email δεν μπόρεσε να σταλεί. SMTP: '
                                . $mailError->getMessage()
                            );
                        }
                    }
                } else {
                    $pdo->prepare(
                        "UPDATE dj_portal_accounts
                         SET public_profile_enabled = 0
                         WHERE id = ?"
                    )->execute([$accountId]);

                    $notice = 'Το Public Profile απενεργοποιήθηκε για τον '
                        . (string)$account['artist_name']
                        . '. Δεν εμφανίζεται πλέον δημόσιο modal.';
                }

            } elseif ($action === 'delete_account') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $account = mylive_admin_account($pdo, $accountId);

                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE program SET mylive_account_id = NULL WHERE mylive_account_id = ?")
                        ->execute([$accountId]);
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

                $updatedSet = deseo_mylive_update_set_status($pdo, $setId, $status, $note);

                if ($status === 'broadcasted' && !empty($updatedSet['delete_after']) && empty($updatedSet['file_deleted_at'])) {
                    $notice = 'Το DJ Set σημειώθηκε ως BROADCASTED. Το audio file θα διαγραφεί αυτόματα στις '
                        . date('d.m.Y · H:i', strtotime((string)$updatedSet['delete_after']))
                        . ', ενώ το episode θα παραμείνει στη βάση.';
                } elseif (!empty($updatedSet['file_deleted_at'])) {
                    $notice = 'Το status ενημερώθηκε. Το audio file έχει ήδη αφαιρεθεί από τον server και το episode παραμένει στο ιστορικό.';
                } else {
                    $notice = 'Το status του DJ Set ενημερώθηκε.';
                }
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
            (SELECT COUNT(*) FROM dj_portal_assets x WHERE x.account_id = a.id) AS asset_count,
            (SELECT p.is_published FROM dj_public_profiles p WHERE p.account_id = a.id LIMIT 1) AS public_profile_published,
            (SELECT p.published_at FROM dj_public_profiles p WHERE p.account_id = a.id LIMIT 1) AS public_profile_published_at
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
        "SELECT id, episode_no, stored_name, file_size, status, admin_note,
                broadcasted_at, delete_after, file_deleted_at, uploaded_at
         FROM dj_portal_sets WHERE account_id = ? ORDER BY episode_no DESC LIMIT 8"
    );
    $stmt->execute([$accountId]);
    $setsByAccount[$accountId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$activeAccountCount = count(array_filter(
    $managedAccounts,
    static fn(array $account): bool => (string)($account['account_status'] ?? '') === 'active' && !empty($account['is_active'])
));
$disabledAccountCount = count($managedAccounts) - $activeAccountCount;
$totalSetCount = array_sum(array_map(static fn(array $account): int => (int)$account['set_count'], $managedAccounts));
$totalAssetCount = array_sum(array_map(static fn(array $account): int => (int)$account['asset_count'], $managedAccounts));

admin_page_start('MyLive', 'mylive');
?>
<div class="mylive-hub">
    <div class="page-heading mylive-admin-heading mylive-hub-heading">
        <div>
            <span>Deseo Radio · DJ Workspace</span>
            <h1>MyLive management</h1>
            <p>Ένα καθαρό σημείο για access, DJ Sets και προσωπικά assets. Τα βασικά φαίνονται άμεσα και οι λεπτομέρειες ανοίγουν μόνο όταν τις χρειάζεσαι.</p>
        </div>
        <a class="button button-secondary" href="/mylive/" target="_blank" rel="noopener">Open MyLive ↗</a>
    </div>

    <?php if ($notice): ?><div class="notice notice-success"><?= admin_e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

    <?php if ($generatedCredentials): ?>
        <section class="mylive-credentials-admin mylive-v3-credentials">
            <span>TEMPORARY ACCESS · COPY NOW</span>
            <strong><?= admin_e($generatedCredentials['artist']) ?></strong>
            <div><b>Email</b><code><?= admin_e($generatedCredentials['email']) ?></code></div>
            <div><b>Temporary password</b><code><?= admin_e($generatedCredentials['password']) ?></code></div>
            <small>Το onboarding email έχει σταλεί. Στην πρώτη είσοδο ο DJ θα δημιουργήσει προσωπικό password.</small>
        </section>
    <?php endif; ?>

    <section class="mylive-hub-stats" aria-label="MyLive overview">
        <div class="mylive-hub-stat <?= count($pendingAccounts) > 0 ? 'is-attention' : '' ?>">
            <span>PENDING ACCESS</span>
            <strong><?= count($pendingAccounts) ?></strong>
            <small><?= count($pendingAccounts) > 0 ? 'χρειάζονται ενέργεια' : 'κανένα pending' ?></small>
        </div>
        <div class="mylive-hub-stat">
            <span>ACTIVE DJS</span>
            <strong><?= $activeAccountCount ?></strong>
            <small>ενεργά MyLive accounts</small>
        </div>
        <div class="mylive-hub-stat">
            <span>DJ SETS</span>
            <strong><?= $totalSetCount ?></strong>
            <small>συνολικά episodes</small>
        </div>
        <div class="mylive-hub-stat">
            <span>ASSETS</span>
            <strong><?= $totalAssetCount ?></strong>
            <small>artwork & imaging</small>
        </div>
        <div class="mylive-hub-stat">
            <span>DISABLED</span>
            <strong><?= $disabledAccountCount ?></strong>
            <small>ανενεργά accounts</small>
        </div>
    </section>

    <?php if ($pendingAccounts): ?>
        <section class="panel mylive-pending-panel mylive-v3-pending">
            <div class="mylive-panel-head">
                <div>
                    <span>NEEDS YOUR ATTENTION</span>
                    <h2>Pending MyLive access</h2>
                    <p>Έχουν ήδη εγκριθεί στη Season 6 αλλά δεν έχουν ακόμη MyLive credentials. Ένα click δημιουργεί temporary password και στέλνει το onboarding email.</p>
                </div>
                <strong><?= count($pendingAccounts) ?></strong>
            </div>

            <div class="mylive-pending-list">
                <?php foreach ($pendingAccounts as $pending): ?>
                    <article class="mylive-pending-card mylive-v3-pending-card">
                        <div class="mylive-pending-main">
                            <?php if (!empty($pending['application_photo'])): ?>
                                <img src="<?= admin_e((string)$pending['application_photo']) ?>" alt="">
                            <?php else: ?>
                                <div class="mylive-avatar"><?= admin_e(strtoupper(substr((string)$pending['artist_name'], 0, 1))) ?></div>
                            <?php endif; ?>

                            <div class="mylive-pending-copy">
                                <span><?= admin_e(strtoupper((string)($pending['application_status'] ?? 'approved'))) ?> · <?= admin_e(deseo_mylive_slot($pending)) ?></span>
                                <h3><?= admin_e($pending['artist_name']) ?></h3>
                                <p><?= admin_e($pending['full_name']) ?> · <?= admin_e($pending['email']) ?></p>

                                <div class="mylive-pending-tags">
                                    <?php if (!empty($pending['application_set_type'])): ?><span><?= admin_e($pending['application_set_type']) ?></span><?php endif; ?>
                                    <?php if (!empty($pending['application_instagram'])): ?><a href="<?= admin_e($pending['application_instagram']) ?>" target="_blank" rel="noopener">Social ↗</a><?php endif; ?>
                                    <?php if (!empty($pending['application_website'])): ?><a href="<?= admin_e($pending['application_website']) ?>" target="_blank" rel="noopener">Website ↗</a><?php endif; ?>
                                    <?php if (!empty($pending['application_work_sample'])): ?><a href="<?= admin_e($pending['application_work_sample']) ?>" target="_blank" rel="noopener noreferrer">Work sample ↗</a><?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <form method="post" class="mylive-pending-approve" onsubmit="return confirm('Να ενεργοποιηθεί το MyLive για <?= admin_e($pending['artist_name']) ?> και να σταλεί το onboarding email;');">
                            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                            <input type="hidden" name="action" value="approve_pending">
                            <input type="hidden" name="account_id" value="<?= (int)$pending['id'] ?>">
                            <button class="button button-primary" type="submit">Create MyLive Access</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <details class="panel mylive-create-panel mylive-create-drawer">
        <summary>
            <div>
                <span>MANUAL ACCOUNT</span>
                <strong>Create standalone MyLive access</strong>
                <small>Μόνο για DJ που δεν προέρχεται από Season 6 application.</small>
            </div>
            <b>+</b>
        </summary>
        <div class="mylive-create-drawer-body">
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
                    <div><span>WHAT HAPPENS NEXT</span><strong>Creates access · generates temporary password · sends onboarding email</strong></div>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit">Create & Send Onboarding</button>
                </div>
            </form>
        </div>
    </details>

    <section class="mylive-directory">
        <div class="mylive-directory-head">
            <div>
                <span>DJ DIRECTORY</span>
                <h2>Manage accounts</h2>
                <p>Βρες τον DJ και άνοιξε μόνο το κομμάτι που θέλεις να διαχειριστείς.</p>
            </div>
            <strong><?= count($managedAccounts) ?></strong>
        </div>

        <div class="mylive-toolbar">
            <label class="mylive-search">
                <span>SEARCH</span>
                <input id="myliveAccountSearch" type="search" placeholder="Artist, email ή slot…" autocomplete="off">
            </label>
            <div class="mylive-filters" role="group" aria-label="Filter MyLive accounts">
                <button type="button" class="is-active" data-mylive-filter="all">All <b><?= count($managedAccounts) ?></b></button>
                <button type="button" data-mylive-filter="active">Active <b><?= $activeAccountCount ?></b></button>
                <button type="button" data-mylive-filter="disabled">Disabled <b><?= $disabledAccountCount ?></b></button>
            </div>
        </div>

        <div class="mylive-admin-list" id="myliveAccountList">
        <?php if (!$managedAccounts): ?>
            <div class="empty-admin">Δεν υπάρχουν ακόμη MyLive accounts.</div>
        <?php else: ?>
            <?php foreach ($managedAccounts as $account): ?>
                <?php
                $accountId = (int)$account['id'];
                $slot = deseo_mylive_slot($account);
                $accountStatus = (string)($account['account_status'] ?? (!empty($account['is_active']) ? 'active' : 'disabled'));
                $accountSearch = strtolower(trim(
                    (string)$account['artist_name'] . ' ' .
                    (string)$account['full_name'] . ' ' .
                    (string)$account['email'] . ' ' .
                    $slot
                ));
                ?>
                <article
                    class="panel mylive-account-card mylive-v3-account"
                    data-account-status="<?= admin_e($accountStatus) ?>"
                    data-account-search="<?= admin_e($accountSearch) ?>"
                >
                    <div class="mylive-account-top">
                        <div class="mylive-account-identity">
                            <?php if (!empty($account['application_photo'])): ?>
                                <img class="mylive-account-photo" src="<?= admin_e((string)$account['application_photo']) ?>" alt="">
                            <?php else: ?>
                                <div class="mylive-avatar"><?= admin_e(strtoupper(substr((string)$account['artist_name'], 0, 1))) ?></div>
                            <?php endif; ?>
                            <div>
                                <div class="mylive-account-statusline">
                                    <span class="mylive-status-badge <?= $accountStatus === 'active' ? 'is-active' : 'is-disabled' ?>"><?= admin_e(strtoupper($accountStatus)) ?></span>
                                    <span><?= admin_e($slot) ?></span>
                                </div>
                                <h2><?= admin_e($account['artist_name']) ?></h2>
                                <p><?= admin_e($account['email']) ?></p>
                            </div>
                        </div>

                        <div class="mylive-account-stats">
                            <div><strong><?= (int)$account['set_count'] ?></strong><span>Episodes</span></div>
                            <div><strong><?= (int)$account['asset_count'] ?></strong><span>Assets</span></div>
                        </div>
                    </div>

                    <div class="mylive-v3-health">
                        <span class="<?= !empty($account['must_change_password']) ? 'is-warning' : 'is-good' ?>">
                            <?= !empty($account['must_change_password']) ? 'Temporary password' : 'Password set' ?>
                        </span>
                        <span class="<?= $account['onboarding_email_sent_at'] ? 'is-good' : 'is-warning' ?>">
                            <?= $account['onboarding_email_sent_at'] ? 'Onboarding sent' : 'Onboarding not sent' ?>
                        </span>
                        <span>Last login: <?= $account['last_login_at'] ? admin_e(date('d.m.Y H:i', strtotime((string)$account['last_login_at']))) : 'Never' ?></span>
                        <span class="<?= !empty($account['public_profile_enabled']) ? 'is-good' : '' ?>">
                            Public profile:
                            <?php if (empty($account['public_profile_enabled'])): ?>
                                Off
                            <?php elseif (!empty($account['public_profile_published'])): ?>
                                Published
                            <?php else: ?>
                                Draft
                            <?php endif; ?>
                        </span>
                        <?php if (admin_is_administrator()): ?>
                            <span>Stats: <?= !empty($account['show_audience_stats']) ? 'Visible' : 'Hidden' ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="mylive-v3-sections">
                        <details class="mylive-v3-detail">
                            <summary>
                                <div><span>ACCOUNT</span><strong>Profile & access</strong></div>
                                <small>Edit details, password, status</small>
                                <b>+</b>
                            </summary>
                            <div class="mylive-v3-detail-body">
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
                                    <div class="form-actions"><button class="button button-primary" type="submit">Save changes</button></div>
                                </form>

                                <div class="mylive-admin-actions">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle_public_profile">
                                        <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                        <input type="hidden" name="enabled" value="<?= !empty($account['public_profile_enabled']) ? '0' : '1' ?>">
                                        <button class="button <?= !empty($account['public_profile_enabled']) ? 'button-secondary' : 'button-primary' ?>" type="submit">
                                            <?= !empty($account['public_profile_enabled']) ? 'Disable Public Profile' : 'Enable Public Profile' ?>
                                        </button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Να εκδοθεί νέο temporary password και να σταλεί ξανά το onboarding email;');">
                                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="reset_access">
                                        <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                        <button class="button button-secondary" type="submit">Reset Access & Resend</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle_account">
                                        <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                        <input type="hidden" name="active" value="<?= !empty($account['is_active']) ? '0' : '1' ?>">
                                        <button class="button <?= !empty($account['is_active']) ? 'button-danger' : 'button-primary' ?>" type="submit">
                                            <?= !empty($account['is_active']) ? 'Disable Account' : 'Enable Account' ?>
                                        </button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('ΟΡΙΣΤΙΚΗ ΔΙΑΓΡΑΦΗ: Θα διαγραφούν το MyLive account, όλα τα DJ Sets και όλα τα προσωπικά assets. Συνέχεια;');">
                                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_account">
                                        <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                        <button class="button button-danger" type="submit">Delete User</button>
                                    </form>
                                </div>
                            </div>
                        </details>

                        <details class="mylive-v3-detail">
                            <summary>
                                <div><span>DJ DELIVERY</span><strong>Episodes</strong></div>
                                <small><?= count($setsByAccount[$accountId]) ?> uploaded</small>
                                <b>+</b>
                            </summary>
                            <div class="mylive-v3-detail-body">
                                <?php if (empty($setsByAccount[$accountId])): ?>
                                    <div class="mylive-mini-empty">Δεν έχει ανέβει ακόμη DJ Set.</div>
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
                                                <?php if (!empty($set['file_deleted_at'])): ?>
                                                    <em>Episode retained · audio file deleted from server</em>
                                                <?php elseif (!empty($set['delete_after'])): ?>
                                                    <em>15-day retention · file removal <?= admin_e(date('d.m.Y · H:i', strtotime((string)$set['delete_after']))) ?></em>
                                                <?php endif; ?>
                                            </div>
                                            <select name="status">
                                                <?php foreach (deseo_mylive_set_statuses() as $status): ?>
                                                    <option value="<?= $status ?>" <?= $set['status'] === $status ? 'selected' : '' ?>><?= strtoupper(str_replace('_',' ', $status)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="admin_note" value="<?= admin_e($set['admin_note']) ?>" placeholder="Optional note">

                                            <?php if (!empty($set['file_deleted_at'])): ?>
                                                <span class="mylive-retention-state is-deleted">
                                                    FILE REMOVED · <?= admin_e(date('d.m.Y', strtotime((string)$set['file_deleted_at']))) ?>
                                                </span>
                                            <?php elseif (!empty($set['delete_after'])): ?>
                                                <span class="mylive-retention-state">
                                                    REMOVE <?= admin_e(date('d.m.Y · H:i', strtotime((string)$set['delete_after']))) ?>
                                                </span>
                                            <?php else: ?>
                                                <a class="button button-secondary" href="mylive-download.php?type=set&id=<?= (int)$set['id'] ?>">Download</a>
                                            <?php endif; ?>

                                            <?php if (empty($set['file_deleted_at']) && !empty($set['delete_after'])): ?>
                                                <a class="button button-secondary" href="mylive-download.php?type=set&id=<?= (int)$set['id'] ?>">Download</a>
                                            <?php endif; ?>

                                            <button class="button button-secondary" type="submit">Save</button>
                                        </form>
                                    <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </details>

                        <details class="mylive-v3-detail">
                            <summary>
                                <div><span>DESEO / ILUMA</span><strong>Assets</strong></div>
                                <small><?= count($assetsByAccount[$accountId]) ?> available</small>
                                <b>+</b>
                            </summary>
                            <div class="mylive-v3-detail-body">
                                <form method="post" enctype="multipart/form-data" class="mylive-asset-upload mylive-v3-asset-upload">
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
                                    <button class="button button-primary" type="submit">Upload Asset</button>
                                </form>

                                <?php if (empty($assetsByAccount[$accountId])): ?>
                                    <div class="mylive-mini-empty">Δεν υπάρχουν ακόμη assets.</div>
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
                            </div>
                        </details>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <div class="mylive-no-results" id="myliveNoResults" hidden>Δεν βρέθηκε account με αυτά τα φίλτρα.</div>
    </section>
</div>

<script>
(function(){
    var search=document.getElementById('myliveAccountSearch');
    var list=document.getElementById('myliveAccountList');
    var empty=document.getElementById('myliveNoResults');
    var buttons=document.querySelectorAll('[data-mylive-filter]');
    if(!list)return;

    var filter='all';
    var cards=Array.prototype.slice.call(list.querySelectorAll('[data-account-status]'));

    function apply(){
        var q=search ? search.value.trim().toLowerCase() : '';
        var visible=0;

        cards.forEach(function(card){
            var status=(card.getAttribute('data-account-status')||'').toLowerCase();
            var haystack=(card.getAttribute('data-account-search')||'').toLowerCase();
            var statusMatch=filter==='all'||status===filter;
            var searchMatch=!q||haystack.indexOf(q)!==-1;
            var show=statusMatch&&searchMatch;
            card.hidden=!show;
            if(show)visible++;
        });

        if(empty)empty.hidden=visible!==0;
    }

    buttons.forEach(function(button){
        button.addEventListener('click',function(){
            filter=button.getAttribute('data-mylive-filter')||'all';
            buttons.forEach(function(item){item.classList.toggle('is-active',item===button);});
            apply();
        });
    });

    if(search)search.addEventListener('input',apply);
}());
</script>
<?php admin_page_end(); ?>
