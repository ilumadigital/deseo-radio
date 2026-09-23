<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/dj-rewards.php';
require_once __DIR__ . '/../includes/audience.php';
require_once __DIR__ . '/../includes/turnstile.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/mylive-email-reminders.php';
require_once __DIR__ . '/../includes/mylive-communications.php';

deseo_mylive_session_start();
deseo_mylive_bootstrap($pdo);
deseo_rewards_bootstrap($pdo);
deseo_audience_bootstrap($pdo);
deseo_mylive_communications_bootstrap($pdo);
deseo_mylive_maybe_run_email_scheduler($pdo);

try {
    deseo_mylive_cleanup_broadcasted_sets($pdo);
} catch (Throwable $retentionError) {
    error_log('MyLive DJ retention cleanup failed: ' . $retentionError->getMessage());
}

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate', true);
    header('Cache-Control: private, no-store, no-cache, must-revalidate', true);
    header('Pragma: no-cache', true);
}

$error = null;
$notice = null;
$turnstileConfigured = deseo_turnstile_configured();
$turnstileSiteKey = deseo_turnstile_site_key();

function mylive_json(bool $ok, string $message, array $extra = []): never {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && trim((string)($_GET['reset'] ?? '')) !== ''
    && deseo_mylive_logged_in()
) {
    unset($_SESSION['mylive_account_id']);
    session_regenerate_id(true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    try {
        if (!deseo_mylive_verify_csrf($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Η συνεδρία έληξε. Ανανέωσε τη σελίδα και δοκίμασε ξανά.');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: /mylive/');
            exit;
        }

        if ($action === 'request_password_reset' && !deseo_mylive_logged_in()) {
            if (!$turnstileConfigured) {
                throw new RuntimeException('Η ασφαλής ανάκτηση κωδικού δεν είναι διαθέσιμη αυτή τη στιγμή.');
            }

            $turnstileToken = trim((string)($_POST['cf-turnstile-response'] ?? ''));
            $remoteIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
            $turnstile = deseo_turnstile_validate($turnstileToken, $remoteIp, 'mylive_password_reset');
            if (empty($turnstile['success'])) {
                throw new RuntimeException('Το Cloudflare security check απέτυχε. Δοκίμασε ξανά.');
            }

            $resetEmail = strtolower(trim((string)($_POST['email'] ?? '')));
            if (!filter_var($resetEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Συμπλήρωσε ένα έγκυρο email.');
            }

            $accountStmt = $pdo->prepare(
                "SELECT id, artist_name, email
                 FROM dj_portal_accounts
                 WHERE LOWER(email) = ?
                   AND is_active = 1
                 LIMIT 1"
            );
            $accountStmt->execute([$resetEmail]);
            $resetAccount = $accountStmt->fetch(PDO::FETCH_ASSOC);

            if (!$resetAccount) {
                throw new RuntimeException('Δεν υπάρχει MyLive account με αυτό το email.');
            }

            $now = time();
            $lastResetRequest = (int)($_SESSION['mylive_reset_last_request'] ?? 0);
            if (($now - $lastResetRequest) < 30) {
                throw new RuntimeException('Περίμενε λίγα δευτερόλεπτα πριν ζητήσεις νέο reset link.');
            }

            if (deseo_mylive_password_reset_recent($pdo, (int)$resetAccount['id'], 120)) {
                throw new RuntimeException('Έχει ήδη σταλεί πρόσφατα reset link σε αυτό το email. Έλεγξε Inbox και Spam / Junk.');
            }

            $resetToken = deseo_mylive_password_reset_create($pdo, (int)$resetAccount['id'], 3600);

            try {
                $mail = deseo_mylive_password_reset_email($resetAccount, $resetToken);
                deseo_send_smtp_mail(
                    (string)$resetAccount['email'],
                    (string)$resetAccount['artist_name'],
                    (string)$mail['subject'],
                    (string)$mail['html'],
                    (string)$mail['text'],
                    true
                );
            } catch (Throwable $mailError) {
                $pdo->prepare(
                    "UPDATE dj_password_resets
                     SET used_at = NOW()
                     WHERE account_id = ? AND used_at IS NULL"
                )->execute([(int)$resetAccount['id']]);

                error_log(
                    'MyLive password reset email failed for account '
                    . (int)$resetAccount['id']
                    . ': '
                    . $mailError->getMessage()
                );

                throw new RuntimeException(
                    'Δεν ήταν δυνατή η αποστολή του reset email αυτή τη στιγμή. Δοκίμασε ξανά σε λίγο.'
                );
            }

            $_SESSION['mylive_reset_last_request'] = $now;
            $_SESSION['mylive_reset_sent_email'] = (string)$resetAccount['email'];
            header('Location: /mylive/?forgot=sent');
            exit;
        }

        if ($action === 'reset_password_link' && !deseo_mylive_logged_in()) {
            $resetToken = strtolower(trim((string)($_POST['reset_token'] ?? '')));
            $password = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            if (strlen($password) < 8) {
                throw new RuntimeException('Ο νέος κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.');
            }
            if ($password !== $confirm) {
                throw new RuntimeException('Οι δύο κωδικοί δεν είναι ίδιοι.');
            }

            $resetAccount = deseo_mylive_password_reset_consume(
                $pdo,
                $resetToken,
                password_hash($password, PASSWORD_DEFAULT)
            );

            if (!$resetAccount) {
                throw new RuntimeException('Ο σύνδεσμος αλλαγής κωδικού δεν είναι πλέον έγκυρος. Ζήτησε νέο reset link.');
            }

            session_regenerate_id(true);
            $_SESSION['mylive_reset_last_request'] = 0;
            header('Location: /mylive/?reset=done');
            exit;
        }

        if ($action === 'login' && !deseo_mylive_logged_in()) {
            if (!$turnstileConfigured) {
                throw new RuntimeException('Η ασφαλής είσοδος δεν είναι διαθέσιμη αυτή τη στιγμή.');
            }

            $turnstileToken = trim((string)($_POST['cf-turnstile-response'] ?? ''));
            $remoteIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
            $turnstile = deseo_turnstile_validate($turnstileToken, $remoteIp, 'mylive_login');
            if (empty($turnstile['success'])) {
                throw new RuntimeException('Το Cloudflare security check απέτυχε. Δοκίμασε ξανά.');
            }

            $now = time();
            $attempts = (int)($_SESSION['mylive_attempts'] ?? 0);
            $lastAttempt = (int)($_SESSION['mylive_last_attempt'] ?? 0);
            if ($attempts >= 5 && ($now - $lastAttempt) < 300) {
                throw new RuntimeException('Πολλές αποτυχημένες προσπάθειες. Δοκίμασε ξανά σε λίγα λεπτά.');
            }

            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
                throw new RuntimeException('Συμπλήρωσε σωστά email και password.');
            }

            $stmt = $pdo->prepare(
                "SELECT id, password_hash
                 FROM dj_portal_accounts
                 WHERE LOWER(email) = ? AND is_active = 1
                 LIMIT 1"
            );
            $stmt->execute([$email]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account || !password_verify($password, (string)$account['password_hash'])) {
                $_SESSION['mylive_attempts'] = $attempts + 1;
                $_SESSION['mylive_last_attempt'] = $now;
                throw new RuntimeException('Το email ή το password δεν είναι σωστό.');
            }

            session_regenerate_id(true);
            $_SESSION['mylive_account_id'] = (int)$account['id'];
            $_SESSION['mylive_attempts'] = 0;
            $_SESSION['mylive_last_attempt'] = 0;

            $pdo->prepare("UPDATE dj_portal_accounts SET last_login_at = NOW() WHERE id = ?")
                ->execute([(int)$account['id']]);

            $loginSection = strtolower(trim((string)($_GET['section'] ?? $_POST['section'] ?? '')));
            $loginTarget = $loginSection === 'rewards'
                ? '/mylive/?section=rewards#rewards'
                : '/mylive/';

            header('Location: ' . $loginTarget);
            exit;
        }

        if ($action === 'change_password') {
            if (!deseo_mylive_logged_in()) throw new RuntimeException('Η συνεδρία σου έχει λήξει.');

            $accountId = deseo_mylive_account_id();
            $account = deseo_mylive_account($pdo, $accountId);
            if (!$account) throw new RuntimeException('Το account δεν είναι ενεργό.');

            $password = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            if (strlen($password) < 8) {
                throw new RuntimeException('Ο νέος κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.');
            }
            if ($password !== $confirm) {
                throw new RuntimeException('Οι δύο κωδικοί δεν είναι ίδιοι.');
            }

            $pdo->prepare(
                "UPDATE dj_portal_accounts
                 SET password_hash = ?, must_change_password = 0
                 WHERE id = ?"
            )->execute([password_hash($password, PASSWORD_DEFAULT), $accountId]);

            session_regenerate_id(true);
            $notice = 'Ο προσωπικός σου κωδικός αποθηκεύτηκε.';
        }

        if ($action === 'save_notification_preferences') {
            if (!deseo_mylive_logged_in()) {
                throw new RuntimeException('Η συνεδρία σου έχει λήξει. Κάνε ξανά login.');
            }

            $accountId = deseo_mylive_account_id();
            $currentPreferences = deseo_mylive_communication_preferences($pdo, $accountId);

            deseo_mylive_save_communication_preferences($pdo, $accountId, [
                'email_enabled' => isset($_POST['email_enabled']),
                'push_enabled' => !empty($currentPreferences['push_enabled']),
                'set_reminder_email' => isset($_POST['set_reminder_email']),
                'set_reminder_push' => isset($_POST['set_reminder_push']),
                'on_air_email' => isset($_POST['on_air_email']),
                'on_air_push' => isset($_POST['on_air_push']),
                'announcements_email' => isset($_POST['announcements_email']),
                'announcements_push' => isset($_POST['announcements_push']),
            ]);

            $notice = 'Οι ρυθμίσεις επικοινωνίας αποθηκεύτηκαν.';
        }

        if (in_array($action, ['save_public_profile', 'publish_public_profile', 'unpublish_public_profile'], true)) {
            if (!deseo_mylive_logged_in()) {
                throw new RuntimeException('Η συνεδρία σου έχει λήξει. Κάνε ξανά login.');
            }

            $accountId = deseo_mylive_account_id();
            $profileAccount = deseo_mylive_account($pdo, $accountId);
            if (!$profileAccount || empty($profileAccount['public_profile_enabled'])) {
                throw new RuntimeException('Το Public Profile δεν είναι ενεργό για το account σου.');
            }
            if (!empty($profileAccount['must_change_password'])) {
                throw new RuntimeException('Δημιούργησε πρώτα το προσωπικό σου password.');
            }

            if ($action === 'unpublish_public_profile') {
                // Visibility-only action. Keep drafts, published data and Radio Program linkage untouched.
                deseo_mylive_unpublish_public_profile($pdo, $accountId);
                $notice = 'Το Public Profile έγινε Unpublished. Το show σου παραμένει συνδεδεμένο κανονικά με το Radio Program και μπορείς να το δημοσιεύσεις ξανά οποιαδήποτε στιγμή.';
            } else {
                deseo_mylive_save_public_profile_draft($pdo, $accountId, [
                    'bio' => (string)($_POST['bio'] ?? ''),
                    'instagram' => (string)($_POST['instagram'] ?? ''),
                    'tiktok' => (string)($_POST['tiktok'] ?? ''),
                    'soundcloud' => (string)($_POST['soundcloud'] ?? ''),
                    'spotify' => (string)($_POST['spotify'] ?? ''),
                    'website' => (string)($_POST['website'] ?? ''),
                ]);

                if ($action === 'publish_public_profile') {
                    deseo_mylive_publish_public_profile($pdo, $accountId);
                    $notice = 'Το Public Profile δημοσιεύτηκε. Η σύνδεση με το Radio Program παραμένει κανονικά ενεργή.';
                } else {
                    $notice = 'Το draft του Public Profile αποθηκεύτηκε. Οι αλλαγές δεν είναι ακόμη δημόσιες.';
                }
            }
        }

        if ($action === 'upload') {
            if (!deseo_mylive_logged_in()) {
                throw new RuntimeException('Η συνεδρία σου έχει λήξει. Κάνε ξανά login.');
            }

            $accountId = deseo_mylive_account_id();
            $account = deseo_mylive_account($pdo, $accountId);
            if (!$account) throw new RuntimeException('Το account δεν είναι πλέον ενεργό.');
            if (!empty($account['must_change_password'])) {
                throw new RuntimeException('Δημιούργησε πρώτα το προσωπικό σου password.');
            }

            if (!isset($_FILES['dj_set']) || !is_array($_FILES['dj_set'])) {
                throw new RuntimeException('Επίλεξε το DJ set που θέλεις να ανεβάσεις.');
            }

            $file = $_FILES['dj_set'];
            $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_OK) {
                $uploadMessages = [
                    UPLOAD_ERR_INI_SIZE => 'Το αρχείο ξεπερνά το όριο upload του server.',
                    UPLOAD_ERR_FORM_SIZE => 'Το αρχείο είναι πολύ μεγάλο.',
                    UPLOAD_ERR_PARTIAL => 'Το upload διακόπηκε πριν ολοκληρωθεί.',
                    UPLOAD_ERR_NO_FILE => 'Δεν επιλέχθηκε αρχείο.'
                ];
                throw new RuntimeException($uploadMessages[$uploadError] ?? 'Το upload δεν ολοκληρώθηκε.');
            }

            $size = (int)($file['size'] ?? 0);
            if ($size < 1024 || $size > DESEO_MYLive_MAX_BYTES) {
                throw new RuntimeException('Το αρχείο πρέπει να είναι μικρότερο από 1 GB.');
            }

            $originalName = basename((string)($file['name'] ?? ''));
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($extension !== 'mp3') {
                throw new RuntimeException('Το DJ Set πρέπει να είναι MP3 · 192 kbps · Stereo.');
            }

            $tmpName = (string)($file['tmp_name'] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new RuntimeException('Το αρχείο δεν αναγνωρίστηκε ως έγκυρο upload.');
            }

            $mime = '';
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$finfo->file($tmpName);
            }

            $allowedMime = ['audio/mpeg', 'audio/mp3', 'application/octet-stream'];

            if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
                throw new RuntimeException('Το αρχείο δεν φαίνεται να είναι έγκυρο ' . strtoupper($extension) . '.');
            }

            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT id FROM dj_portal_accounts WHERE id = ? AND is_active = 1 FOR UPDATE");
            $lock->execute([$accountId]);
            if (!$lock->fetchColumn()) throw new RuntimeException('Το account δεν βρέθηκε.');

            $episode = deseo_mylive_next_episode($pdo, $accountId);
            $artist = deseo_mylive_slug((string)$account['artist_name']);
            $storedName = sprintf('%s_DESEO_S%02d_EP%03d.%s', $artist, DESEO_DJ_SEASON, $episode, $extension);

            $relativeDir = 'storage/' . $accountId;
            $absoluteDir = __DIR__ . '/' . $relativeDir;
            if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) {
                throw new RuntimeException('Δεν ήταν δυνατή η δημιουργία του προσωπικού φακέλου upload.');
            }

            $absolutePath = $absoluteDir . '/' . $storedName;
            if (!move_uploaded_file($tmpName, $absolutePath)) {
                throw new RuntimeException('Δεν ήταν δυνατή η αποθήκευση του DJ set.');
            }

            try {
                $insert = $pdo->prepare(
                    "INSERT INTO dj_portal_sets
                     (account_id, episode_no, original_name, stored_name, file_path, file_size, mime_type, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'received')"
                );
                $insert->execute([
                    $accountId,
                    $episode,
                    $originalName,
                    $storedName,
                    $relativeDir . '/' . $storedName,
                    $size,
                    $mime
                ]);
                $pdo->commit();
            } catch (Throwable $dbError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                @unlink($absolutePath);
                throw $dbError;
            }

            $message = sprintf('Το EP%03d ανέβηκε επιτυχώς.', $episode);
            if ($isAjax) mylive_json(true, $message, ['episode' => $episode, 'filename' => $storedName]);
            $notice = $message;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('MyLive action failed: ' . $e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Κάτι πήγε στραβά. Δοκίμασε ξανά.';
        if ($isAjax ?? false) mylive_json(false, $error);
    }
}

if (!deseo_mylive_logged_in()):
$forgotState = strtolower(trim((string)($_GET['forgot'] ?? '')));
$resetParam = strtolower(trim((string)($_GET['reset'] ?? '')));
$isForgotMode = $forgotState !== '';
$isResetDone = $resetParam === 'done';
$isResetMode = $resetParam !== '' && !$isResetDone;
$resetRecord = $isResetMode ? deseo_mylive_password_reset_lookup($pdo, $resetParam) : null;

if ($forgotState === 'sent') {
    $sentResetEmail = trim((string)($_SESSION['mylive_reset_sent_email'] ?? ''));
    $notice = $sentResetEmail !== ''
        ? 'Στάλθηκε reset link στο ' . $sentResetEmail . '. Έλεγξε και τον φάκελο Spam / Junk.'
        : 'Το reset link στάλθηκε. Έλεγξε και τον φάκελο Spam / Junk.';
}
?>
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#070708">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex,notranslate">
    <meta name="googlebot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <meta name="bingbot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <title><?= $isForgotMode || $isResetMode || $isResetDone ? 'Reset password' : 'MyLive' ?> · Deseo Radio</title>
    <link rel="icon" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/favicon.png">
    <link rel="manifest" href="/mylive/manifest.json">
    <meta name="application-name" content="MyLive App">
    <meta name="apple-mobile-web-app-title" content="MyLive App">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="stylesheet" href="/mylive/style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
    <script src="/assets/js/deseo-lockdown.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-lockdown.js') ?: 1 ?>"></script>
    <script src="/assets/js/deseo-dialogs.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-dialogs.js') ?: 1 ?>"></script>
    <script src="/mylive/app.js?v=<?= @filemtime(__DIR__ . '/app.js') ?: 1 ?>" defer></script>
    <?php if ($turnstileConfigured && ($isForgotMode || (!$isResetMode && !$isResetDone))): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body class="mylive-login-page">
<main class="login-shell">
    <section class="login-brand">
        <div class="mylive-brand-lockup">
            <img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio">
            <span class="mylive-brand-label">MY LIVE</span>
        </div>
        <span>SEASON 6 · DJ ACCESS</span>
        <h1>Your sets.<br>Your space.</h1>
        <p>DJ Set delivery, personal artwork και branded imaging. Όλα σε ένα απλό, ιδιωτικό workspace.</p>
    </section>

    <section class="login-card <?= ($isForgotMode || $isResetMode || $isResetDone) ? 'login-card-recovery' : '' ?>">
        <?php if ($isResetDone): ?>
            <div class="login-card-head">
                <span>MYLIVE · SECURITY</span>
                <h2>Password changed.</h2>
                <p>Ο νέος κωδικός σου αποθηκεύτηκε. Μπορείς τώρα να μπεις κανονικά στο MyLive.</p>
            </div>

            <div class="mylive-reset-success-mark">✓</div>
            <a class="primary-button mylive-login-action-link" href="/mylive/">BACK TO LOGIN</a>

        <?php elseif ($isResetMode): ?>
            <?php if ($resetRecord): ?>
                <div class="login-card-head">
                    <span>MYLIVE · PASSWORD RESET</span>
                    <h2>Νέος κωδικός.</h2>
                    <p>Δημιούργησε νέο password για το MyLive account σου. Το reset link χρησιμοποιείται μόνο μία φορά.</p>
                </div>

                <?php if ($error): ?><div class="alert error"><?= deseo_mylive_e($error) ?></div><?php endif; ?>

                <form method="post" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= deseo_mylive_e(deseo_mylive_csrf()) ?>">
                    <input type="hidden" name="action" value="reset_password_link">
                    <input type="hidden" name="reset_token" value="<?= deseo_mylive_e($resetParam) ?>">

                    <label>
                        <span>Email</span>
                        <input type="email"
                               value="<?= deseo_mylive_e((string)$resetRecord['email']) ?>"
                               autocomplete="username"
                               readonly>
                    </label>

                    <label>
                        <span>New password</span>
                        <div class="password-field">
                            <input id="resetNewPassword" type="password" name="new_password" minlength="8" autocomplete="new-password" required autofocus>
                            <button type="button" class="password-toggle" data-password-toggle="resetNewPassword">Show</button>
                        </div>
                    </label>

                    <label>
                        <span>Confirm password</span>
                        <div class="password-field">
                            <input id="resetConfirmPassword" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                            <button type="button" class="password-toggle" data-password-toggle="resetConfirmPassword">Show</button>
                        </div>
                    </label>

                    <small class="mylive-reset-help">Τουλάχιστον 8 χαρακτήρες. Με την αλλαγή, ο προηγούμενος κωδικός παύει να ισχύει.</small>
                    <button class="primary-button" type="submit">CHANGE PASSWORD</button>
                </form>
            <?php else: ?>
                <div class="login-card-head">
                    <span>MYLIVE · PASSWORD RESET</span>
                    <h2>Το link έληξε.</h2>
                    <p>Ο σύνδεσμος αλλαγής κωδικού δεν είναι πλέον έγκυρος ή έχει ήδη χρησιμοποιηθεί. Ζήτησε νέο link για να συνεχίσεις.</p>
                </div>

                <div class="mylive-reset-expired">RESET LINK EXPIRED</div>
                <a class="primary-button mylive-login-action-link" href="/mylive/?forgot=1">REQUEST NEW LINK</a>
                <a class="mylive-recovery-back" href="/mylive/">← Back to login</a>
            <?php endif; ?>

        <?php elseif ($isForgotMode): ?>
            <div class="login-card-head">
                <span>MYLIVE · PASSWORD RESET</span>
                <h2>Reset password.</h2>
                <p>Γράψε το email του MyLive account σου και θα σου στείλουμε ασφαλές link για να ορίσεις νέο κωδικό.</p>
            </div>

            <?php if ($notice): ?><div class="alert success"><?= deseo_mylive_e($notice) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert error"><?= deseo_mylive_e($error) ?></div><?php endif; ?>

            <form method="post" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= deseo_mylive_e(deseo_mylive_csrf()) ?>">
                <input type="hidden" name="action" value="request_password_reset">

                <label>
                    <span>Email</span>
                    <input type="email" name="email" autocomplete="username" inputmode="email" required autofocus placeholder="you@example.com">
                </label>

                <?php if ($turnstileConfigured): ?>
                    <div class="turnstile-box">
                        <div class="cf-turnstile"
                             data-sitekey="<?= deseo_mylive_e($turnstileSiteKey) ?>"
                             data-theme="dark"
                             data-size="flexible"
                             data-action="mylive_password_reset"></div>
                    </div>
                <?php else: ?>
                    <div class="alert error">Το Cloudflare security δεν είναι ακόμη ρυθμισμένο για το MyLive.</div>
                <?php endif; ?>

                <button class="primary-button" type="submit" <?= $turnstileConfigured ? '' : 'disabled' ?>>SEND RESET LINK</button>
            </form>

            <a class="mylive-recovery-back" href="/mylive/">← Back to login</a>

        <?php else: ?>
            <div class="login-card-head">
                <span>MYLIVE</span>
                <h2>Καλώς ήρθες.</h2>
                <p>Μπες με τα στοιχεία πρόσβασης που έλαβες από το Deseo Radio.</p>
            </div>

            <?php if ($error): ?><div class="alert error"><?= deseo_mylive_e($error) ?></div><?php endif; ?>

            <form method="post" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= deseo_mylive_e(deseo_mylive_csrf()) ?>">
                <input type="hidden" name="action" value="login">
                <?php if (strtolower((string)($_GET['section'] ?? '')) === 'rewards'): ?>
                    <input type="hidden" name="section" value="rewards">
                <?php endif; ?>

                <label>
                    <span>Email</span>
                    <input type="email" name="email" autocomplete="username" inputmode="email" required autofocus>
                </label>

                <label>
                    <span class="login-field-head">
                        <b>Password</b>
                        <a href="/mylive/?forgot=1">Reset password</a>
                    </span>
                    <div class="password-field">
                        <input id="loginPassword" type="password" name="password" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" data-password-toggle="loginPassword">Show</button>
                    </div>
                </label>

                <?php if ($turnstileConfigured): ?>
                    <div class="turnstile-box">
                        <div class="cf-turnstile"
                             data-sitekey="<?= deseo_mylive_e($turnstileSiteKey) ?>"
                             data-theme="dark"
                             data-size="flexible"
                             data-action="mylive_login"></div>
                    </div>
                <?php else: ?>
                    <div class="alert error">Το Cloudflare security δεν είναι ακόμη ρυθμισμένο για το MyLive.</div>
                <?php endif; ?>

                <button class="primary-button" type="submit" <?= $turnstileConfigured ? '' : 'disabled' ?>>Enter MyLive</button>
            </form>

            <small>Private DJ workspace · Deseo Radio / ILUMA Digital Agency</small>
        <?php endif; ?>
    </section>

    <section class="mylive-settings-section mylive-anchor-section" id="settings" aria-labelledby="mylive-settings-title">
        <div class="mylive-settings-head">
            <div>
                <span class="eyebrow">MYSETTINGS · COMMUNICATIONS</span>
                <h2 id="mylive-settings-title">Choose how Deseo reaches you.</h2>
                <p>Ρύθμισε ποια operational reminders και ανακοινώσεις θέλεις να λαμβάνεις μέσω Email και Push Notifications.</p>
            </div>
            <span class="mylive-settings-state">PERSONAL PREFERENCES</span>
        </div>

        <div data-mylive-push-mount></div>

        <form method="post" action="/mylive/#settings" class="mylive-settings-form">
            <input type="hidden" name="csrf_token" value="<?= deseo_mylive_e(deseo_mylive_csrf()) ?>">
            <input type="hidden" name="action" value="save_notification_preferences">

            <div class="mylive-settings-master">
                <div>
                    <span>EMAIL CHANNEL</span>
                    <strong>Email notifications</strong>
                    <small><?= deseo_mylive_e((string)$account['email']) ?></small>
                </div>
                <label class="mylive-switch">
                    <input type="checkbox" name="email_enabled" value="1" <?= !empty($communicationPrefs['email_enabled']) ? 'checked' : '' ?>>
                    <span aria-hidden="true"></span>
                    <b><?= !empty($communicationPrefs['email_enabled']) ? 'ON' : 'OFF' ?></b>
                </label>
            </div>

            <div class="mylive-settings-matrix">
                <div class="mylive-settings-row is-head">
                    <strong>NOTIFICATION TYPE</strong>
                    <span>EMAIL</span>
                    <span>PUSH</span>
                </div>

                <div class="mylive-settings-row">
                    <div>
                        <strong>DJ Set Reminder</strong>
                        <small>3 ημέρες πριν, μόνο όταν λείπει το επόμενο DJ Set.</small>
                    </div>
                    <label class="mylive-switch is-compact">
                        <input type="checkbox" name="set_reminder_email" value="1" <?= !empty($communicationPrefs['set_reminder_email']) ? 'checked' : '' ?>>
                        <span aria-hidden="true"></span>
                    </label>
                    <label class="mylive-switch is-compact">
                        <input type="checkbox" name="set_reminder_push" value="1" <?= !empty($communicationPrefs['set_reminder_push']) ? 'checked' : '' ?>>
                        <span aria-hidden="true"></span>
                    </label>
                </div>

                <div class="mylive-settings-row">
                    <div>
                        <strong>On Air Now</strong>
                        <small>Τη στιγμή που το weekly slot σου γίνεται live.</small>
                    </div>
                    <label class="mylive-switch is-compact">
                        <input type="checkbox" name="on_air_email" value="1" <?= !empty($communicationPrefs['on_air_email']) ? 'checked' : '' ?>>
                        <span aria-hidden="true"></span>
                    </label>
                    <label class="mylive-switch is-compact">
                        <input type="checkbox" name="on_air_push" value="1" <?= !empty($communicationPrefs['on_air_push']) ? 'checked' : '' ?>>
                        <span aria-hidden="true"></span>
                    </label>
                </div>

                <div class="mylive-settings-row">
                    <div>
                        <strong>Deseo Announcements</strong>
                        <small>Γενικές ενημερώσεις της ομάδας του Deseo Radio προς τους DJs.</small>
                    </div>
                    <label class="mylive-switch is-compact">
                        <input type="checkbox" name="announcements_email" value="1" <?= !empty($communicationPrefs['announcements_email']) ? 'checked' : '' ?>>
                        <span aria-hidden="true"></span>
                    </label>
                    <label class="mylive-switch is-compact">
                        <input type="checkbox" name="announcements_push" value="1" <?= !empty($communicationPrefs['announcements_push']) ? 'checked' : '' ?>>
                        <span aria-hidden="true"></span>
                    </label>
                </div>
            </div>

            <div class="mylive-settings-actions">
                <p>Τα Push χρειάζονται μία ενεργή συσκευή. Η άδεια του browser εμφανίζεται μόνο όταν πατήσεις Enable Push Alerts.</p>
                <button type="submit">SAVE MY SETTINGS</button>
            </div>
        </form>
    </section>
</main>
<script>
document.querySelectorAll('[data-password-toggle]').forEach(button => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        button.textContent = visible ? 'Show' : 'Hide';
    });
});
</script>
</body>
</html>
<?php
exit;
endif;

$account = deseo_mylive_account($pdo, deseo_mylive_account_id());
if (!$account) {
    $_SESSION = [];
    header('Location: /mylive/');
    exit;
}

if (!empty($account['must_change_password'])):
?>
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#070708">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex,notranslate">
    <meta name="googlebot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <meta name="bingbot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <title>Create your password · MyLive · Deseo Radio</title>
    <link rel="icon" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/favicon.png">
    <link rel="manifest" href="/mylive/manifest.json">
    <meta name="application-name" content="MyLive App">
    <meta name="apple-mobile-web-app-title" content="MyLive App">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="stylesheet" href="/mylive/style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
    <script src="/assets/js/deseo-lockdown.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-lockdown.js') ?: 1 ?>"></script>
    <script src="/assets/js/deseo-dialogs.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-dialogs.js') ?: 1 ?>"></script>
    <script src="/mylive/app.js?v=<?= @filemtime(__DIR__ . '/app.js') ?: 1 ?>" defer></script>
</head>
<body class="mylive-password-page">
<main class="password-shell">
    <section class="password-card">
        <div class="mylive-brand-lockup mylive-brand-lockup-password">
            <img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio">
            <span class="mylive-brand-label">MY LIVE</span>
        </div>
        <span class="eyebrow">FIRST ACCESS · <?= deseo_mylive_e($account['artist_name']) ?></span>
        <h1>Κάν’ το δικό σου.</h1>
        <p>Το password που έλαβες ήταν προσωρινό. Δημιούργησε τώρα τον προσωπικό σου κωδικό για το MyLive.</p>

        <?php if ($error): ?><div class="alert error"><?= deseo_mylive_e($error) ?></div><?php endif; ?>

        <form method="post" class="password-form" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= deseo_mylive_e(deseo_mylive_csrf()) ?>">
            <input type="hidden" name="action" value="change_password">

            <label>
                <span>Email</span>
                <input type="email"
                       name="email"
                       value="<?= deseo_mylive_e($account['email']) ?>"
                       autocomplete="username"
                       inputmode="email"
                       readonly>
            </label>

            <label>
                <span>New password</span>
                <div class="password-field">
                    <input id="newPassword" type="password" name="new_password" minlength="8" autocomplete="new-password" required autofocus>
                    <button type="button" class="password-toggle" data-password-toggle="newPassword">Show</button>
                </div>
            </label>

            <label>
                <span>Confirm password</span>
                <div class="password-field">
                    <input id="confirmPassword" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                    <button type="button" class="password-toggle" data-password-toggle="confirmPassword">Show</button>
                </div>
            </label>

            <small>Τουλάχιστον 8 χαρακτήρες. Αποθήκευσε το email και τον νέο κωδικό στον browser / password manager σου για την επόμενη σύνδεση.</small>
            <button class="primary-button" type="submit">Save & open MyLive</button>
        </form>
    </section>
</main>
<script>
document.querySelectorAll('[data-password-toggle]').forEach(button => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        button.textContent = visible ? 'Show' : 'Hide';
    });
});
</script>
</body>
</html>
<?php
exit;
endif;

$communicationPrefs = deseo_mylive_communication_preferences($pdo, (int)$account['id']);

$publicProfile = !empty($account['public_profile_enabled'])
    ? deseo_mylive_public_profile($pdo, (int)$account['id'])
    : null;
$publicProfileHasChanges = $publicProfile
    ? deseo_mylive_public_profile_has_unpublished_changes($publicProfile)
    : false;

$sets = deseo_mylive_sets($pdo, (int)$account['id']);
$assets = deseo_mylive_assets($pdo, (int)$account['id']);
$rewards = deseo_rewards_for_account($pdo, (int)$account['id']);
$rewardsSummary = deseo_rewards_summary($rewards);
$nextEpisode = deseo_mylive_next_episode($pdo, (int)$account['id']);
$latestAudience = deseo_audience_latest_record($pdo);
$audienceMonthKey = $latestAudience ? (string)$latestAudience['month_key'] : '';
$currentAudienceMonthLabel = $audienceMonthKey !== ''
    ? deseo_audience_month_label($audienceMonthKey)
    : deseo_audience_month_label();
$statsVisible = !empty($account['show_audience_stats']);
$monthlyAudience = $latestAudience ? (int)$latestAudience['monthly_listeners'] : 0;
$estimatedReach = ($statsVisible && $latestAudience && $audienceMonthKey !== '')
    ? deseo_audience_estimated_reach_for_month($pdo, $account, $audienceMonthKey, $monthlyAudience)
    : 0;
$dayLabel = deseo_mylive_day_label(isset($account['day_of_week']) ? (int)$account['day_of_week'] : null);
$startTime = deseo_mylive_format_time((string)$account['start_time']);

// NEXT SHOW · calculate the next occurrence from the DJ's recurring weekly slot.
$nextShowStart = null;
$nextShowEnd = null;
$nextShowIsLive = false;
$nextShowWhen = 'Schedule pending';
$nextShowDate = '—';
$nextShowTime = $startTime;
$nextShowDay = $dayLabel;

$slotDay = (int)($account['day_of_week'] ?? 0);
$slotStartRaw = trim((string)($account['start_time'] ?? ''));
$slotEndRaw = trim((string)($account['end_time'] ?? ''));

if ($slotDay >= 1 && $slotDay <= 7 && $slotStartRaw !== '') {
    $athensTz = new DateTimeZone('Europe/Athens');
    $nowAthens = new DateTimeImmutable('now', $athensTz);
    $today = $nowAthens->setTime(0, 0, 0);
    $dayDelta = $slotDay - (int)$nowAthens->format('N');

    $candidateDate = $today->modify(($dayDelta >= 0 ? '+' : '') . $dayDelta . ' days');
    [$slotHour, $slotMinute] = array_map('intval', array_pad(explode(':', $slotStartRaw), 2, '0'));
    $candidateStart = $candidateDate->setTime($slotHour, $slotMinute, 0);

    $candidateEnd = $candidateStart->modify('+1 hour');
    if ($slotEndRaw !== '') {
        [$endHour, $endMinute] = array_map('intval', array_pad(explode(':', $slotEndRaw), 2, '0'));
        $candidateEnd = $candidateDate->setTime($endHour, $endMinute, 0);
        if ($candidateEnd <= $candidateStart) {
            $candidateEnd = $candidateEnd->modify('+1 day');
        }
    }

    if ($nowAthens >= $candidateEnd) {
        $candidateStart = $candidateStart->modify('+7 days');
        $candidateEnd = $candidateEnd->modify('+7 days');
    }

    $nextShowStart = $candidateStart;
    $nextShowEnd = $candidateEnd;
    $nextShowIsLive = $nowAthens >= $candidateStart && $nowAthens < $candidateEnd;

    $todayKey = $nowAthens->format('Y-m-d');
    $tomorrowKey = $nowAthens->modify('+1 day')->format('Y-m-d');
    $showKey = $candidateStart->format('Y-m-d');

    if ($nextShowIsLive) {
        $nextShowWhen = 'LIVE NOW';
    } elseif ($showKey === $todayKey) {
        $nextShowWhen = 'Today';
    } elseif ($showKey === $tomorrowKey) {
        $nextShowWhen = 'Tomorrow';
    } else {
        $daysToShow = (int)$today->diff($candidateStart->setTime(0, 0))->format('%a');
        $nextShowWhen = 'In ' . $daysToShow . ' days';
    }

    $nextShowDate = $candidateStart->format('d.m.Y');
    $nextShowTime = $candidateStart->format('H:i');
}

$nextShowSet = null;
foreach ($sets as $setCandidate) {
    if ((string)($setCandidate['status'] ?? '') !== 'broadcasted') {
        $nextShowSet = $setCandidate;
        break;
    }
}

$nextShowEpisode = $nextShowSet
    ? (int)$nextShowSet['episode_no']
    : $nextEpisode;
$nextShowSetStatus = $nextShowSet ? (string)$nextShowSet['status'] : 'not_uploaded';

$nextShowStatusLabel = match ($nextShowSetStatus) {
    'received' => 'SET UPLOADED',
    'checked' => 'CHECKED',
    'scheduled' => 'READY FOR AIR',
    'needs_changes' => 'NEEDS CHANGES',
    default => 'WAITING FOR SET',
};

$nextShowStatusClass = match ($nextShowSetStatus) {
    'scheduled' => 'is-ready',
    'checked' => 'is-checked',
    'received' => 'is-uploaded',
    'needs_changes' => 'is-warning',
    default => 'is-waiting',
};

$nextShowUploaded = $nextShowSet !== null;
$nextShowChecked = in_array($nextShowSetStatus, ['checked', 'scheduled'], true);
$nextShowScheduled = $nextShowSetStatus === 'scheduled';

$nextShowMessage = match ($nextShowSetStatus) {
    'received' => 'Το set παραλήφθηκε από το Deseo και περιμένει έλεγχο.',
    'checked' => 'Το set έχει ελεγχθεί και περιμένει να προγραμματιστεί.',
    'scheduled' => 'Όλα έτοιμα. Το επόμενο episode είναι προγραμματισμένο για broadcast.',
    'needs_changes' => trim((string)($nextShowSet['admin_note'] ?? '')) !== ''
        ? (string)$nextShowSet['admin_note']
        : 'Το set χρειάζεται αλλαγές πριν μπορέσει να προγραμματιστεί.',
    default => 'Δεν έχει ανέβει ακόμη set για το επόμενο episode.',
};
?>
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#070708">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex,notranslate">
    <meta name="googlebot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <meta name="bingbot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <title>MyLive · <?= deseo_mylive_e($account['artist_name']) ?> · Deseo Radio</title>
    <link rel="icon" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/favicon.png">
    <link rel="manifest" href="/mylive/manifest.json">
    <meta name="application-name" content="MyLive App">
    <meta name="apple-mobile-web-app-title" content="MyLive App">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="stylesheet" href="/mylive/style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
    <script src="/assets/js/deseo-lockdown.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-lockdown.js') ?: 1 ?>"></script>
    <script src="/assets/js/deseo-dialogs.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-dialogs.js') ?: 1 ?>"></script>
    <script src="/mylive/app.js?v=<?= @filemtime(__DIR__ . '/app.js') ?: 1 ?>" defer></script>
</head>
<body class="mylive-dashboard-page">
<header class="portal-header">
    <a href="/mylive/" class="portal-logo mylive-brand-lockup">
        <img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio">
        <span class="mylive-brand-label">MY LIVE</span>
    </a>
    <div class="portal-user">
        <div>
            <strong><?= deseo_mylive_e($account['artist_name']) ?></strong>
            <span><?= deseo_mylive_e($dayLabel) ?> · <?= deseo_mylive_e($startTime) ?></span>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= deseo_mylive_e(deseo_mylive_csrf()) ?>">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="logout-button">Logout</button>
        </form>
    </div>
</header>

<div class="mylive-dashboard-layout">
    <aside class="mylive-section-nav" aria-label="MyLive sections">
        <div class="mylive-section-nav-inner">
            <span class="mylive-nav-label">MYLIVE</span>
            <nav>
                <a href="#overview" class="is-active" data-mylive-nav>
                    <i>01</i><span>Overview</span>
                </a>
                <?php if ($publicProfile): ?>
                    <a href="#profile" data-mylive-nav>
                        <i>02</i><span>My Profile</span>
                    </a>
                <?php endif; ?>
                <a href="#assets" data-mylive-nav>
                    <i><?= $publicProfile ? '03' : '02' ?></i><span>My Assets</span>
                </a>
                <a href="#sets" data-mylive-nav>
                    <i><?= $publicProfile ? '04' : '03' ?></i><span>My DJ Sets</span>
                </a>
                <a href="#live" data-mylive-nav>
                    <i><?= $publicProfile ? '05' : '04' ?></i><span>Listen Live</span>
                </a>
                <a href="#rewards" data-mylive-nav>
                    <i><?= $publicProfile ? '06' : '05' ?></i><span>My Rewards</span>
                </a>
                <a href="#settings" data-mylive-nav>
                    <i><?= $publicProfile ? '07' : '06' ?></i><span>MySettings</span>
                </a>
            </nav>
            <small>Deseo Radio · Season 6</small>
        </div>
    </aside>

<main class="portal-shell">
    <section class="portal-intro mylive-anchor-section" id="overview">
        <div>
            <span class="eyebrow">DESEO RADIO · MYLIVE</span>
            <h1>Welcome, <?= deseo_mylive_e($account['artist_name']) ?>.</h1>
        </div>
        <div class="slot-pill">
            <span>YOUR WEEKLY SLOT</span>
            <strong><?= deseo_mylive_e(deseo_mylive_slot($account)) ?></strong>
        </div>
    </section>

    <section class="mylive-app-card" aria-label="MyLive App">
        <div class="mylive-app-card-copy">
            <span>MYLIVE APP · PWA</span>
            <strong>Το MyLive στο κινητό σου.</strong>
            <p>Εγκατάστησέ το σαν app για γρήγορη πρόσβαση στα DJ Sets, τα assets, το πρόγραμμα και το προσωπικό σου dashboard.</p>
        </div>
        <div class="mylive-app-card-actions">
            <button type="button" class="mylive-app-install-button" data-mylive-install hidden>INSTALL MYLIVE APP</button>
        </div>
    </section>

    <section class="mylive-next-show <?= $nextShowIsLive ? 'is-live' : '' ?>" aria-label="Next show">
        <div class="mylive-next-show-main">
            <div class="mylive-next-show-kicker">
                <span><i></i> NEXT SHOW</span>
                <b class="<?= deseo_mylive_e($nextShowStatusClass) ?>"><?= deseo_mylive_e($nextShowStatusLabel) ?></b>
            </div>

            <div class="mylive-next-show-title">
                <div>
                    <strong><?= deseo_mylive_e($nextShowDay) ?> · <?= deseo_mylive_e($nextShowTime) ?></strong>
                    <span><?= deseo_mylive_e($nextShowDate) ?> · <?= deseo_mylive_e($nextShowWhen) ?></span>
                </div>
                <div class="mylive-next-episode">
                    <span>NEXT EPISODE</span>
                    <strong>EP<?= str_pad((string)$nextShowEpisode, 3, '0', STR_PAD_LEFT) ?></strong>
                </div>
            </div>

            <p class="mylive-next-show-message"><?= deseo_mylive_e($nextShowMessage) ?></p>

            <div class="mylive-next-show-progress" aria-label="Episode delivery progress">
                <div class="<?= $nextShowUploaded ? 'is-complete' : 'is-pending' ?>">
                    <i><?= $nextShowUploaded ? '✓' : '1' ?></i>
                    <span>Uploaded</span>
                </div>
                <em></em>
                <div class="<?= $nextShowSetStatus === 'needs_changes' ? 'is-warning' : ($nextShowChecked ? 'is-complete' : 'is-pending') ?>">
                    <i><?= $nextShowSetStatus === 'needs_changes' ? '!' : ($nextShowChecked ? '✓' : '2') ?></i>
                    <span>Checked</span>
                </div>
                <em></em>
                <div class="<?= $nextShowScheduled ? 'is-complete' : 'is-pending' ?>">
                    <i><?= $nextShowScheduled ? '✓' : '3' ?></i>
                    <span>Scheduled</span>
                </div>
            </div>
        </div>

        <div class="mylive-next-show-action">
            <span><?= $nextShowIsLive ? 'ON AIR NOW' : 'DESEO RADIO · SEASON 6' ?></span>
            <?php if ($nextShowSetStatus === 'not_uploaded'): ?>
                <strong>Your set is next.</strong>
                <a href="#sets">UPLOAD DJ SET ↓</a>
            <?php elseif ($nextShowSetStatus === 'needs_changes'): ?>
                <strong>Action required.</strong>
                <a href="#sets">VIEW DJ SET ↓</a>
            <?php elseif ($nextShowSetStatus === 'scheduled'): ?>
                <strong>Ready for broadcast.</strong>
                <a href="#sets">VIEW EP<?= str_pad((string)$nextShowEpisode, 3, '0', STR_PAD_LEFT) ?> ↓</a>
            <?php else: ?>
                <strong>Delivery in progress.</strong>
                <a href="#sets">VIEW DJ SET ↓</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="dj-metrics" aria-label="DJ metrics">
        <article class="dj-metric-card">
            <span>EPISODES</span>
            <strong><?= count($sets) ?></strong>
            <small>uploaded στο MyLive</small>
        </article>

        <article class="dj-metric-card">
            <span>YOUR ASSETS</span>
            <strong><?= count($assets) ?></strong>
            <small>διαθέσιμα για download</small>
        </article>

        <article class="dj-metric-card dj-metric-reach">
            <span>ΣΕ ΑΚΟΥΣΑΝ</span>
            <?php if (!$statsVisible): ?>
                <strong>Not Available</strong>
            <?php else: ?>
                <strong><?= $monthlyAudience > 0 ? '~' . deseo_mylive_e(deseo_audience_format($estimatedReach)) : '—' ?></strong>
                <small><?= $monthlyAudience > 0 ? 'εκτιμώμενη απήχηση · ' . deseo_mylive_e($currentAudienceMonthLabel) : 'Δεν έχει καταχωρηθεί ακόμη audience για ' . deseo_mylive_e($currentAudienceMonthLabel) ?></small>
            <?php endif; ?>
        </article>
    </section>

    <?php if ($notice): ?><div class="alert success"><?= deseo_mylive_e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= deseo_mylive_e($error) ?></div><?php endif; ?>

    <?php if ($publicProfile): ?>
        <section class="public-profile-editor mylive-anchor-section" id="profile">
            <div class="public-profile-head">
                <div>
                    <span class="eyebrow">YOUR PUBLIC DJ PROFILE</span>
                    <h2>What listeners see.</h2>
                    <p>Γράψε το About σου και πρόσθεσε τα socials σου. Η φωτογραφία και το show title έρχονται πάντα από το επίσημο Radio Program του Deseo.</p>
                </div>

                <?php $publicProfileWasPublished = !empty($publicProfile['published_at']); ?>
                <div class="public-profile-status <?= !empty($publicProfile['is_published']) ? 'is-published' : ($publicProfileWasPublished ? 'is-unpublished' : 'is-draft') ?>">
                    <span><?= !empty($publicProfile['is_published']) ? 'PUBLISHED' : ($publicProfileWasPublished ? 'UNPUBLISHED' : 'DRAFT ONLY') ?></span>
                    <?php if (!empty($publicProfile['is_published'])): ?>
                        <small><?= !empty($publicProfile['published_at']) ? deseo_mylive_e(date('d.m.Y · H:i', strtotime((string)$publicProfile['published_at']))) : 'Live' ?></small>
                    <?php elseif ($publicProfileWasPublished): ?>
                        <small>Hidden from listeners</small>
                    <?php else: ?>
                        <small>Not public yet</small>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($publicProfile['is_published']) && $publicProfileHasChanges): ?>
                <div class="public-profile-unpublished">
                    <strong>You have unpublished changes.</strong>
                    <span>Το site συνεχίζει να δείχνει την προηγούμενη published έκδοση μέχρι να πατήσεις Publish Profile.</span>
                </div>
            <?php endif; ?>

            <form method="post" class="public-profile-form">
                <input type="hidden" name="csrf_token" value="<?= deseo_mylive_e(deseo_mylive_csrf()) ?>">

                <label class="public-profile-about">
                    <span>ABOUT YOU</span>
                    <textarea name="bio" maxlength="1600" rows="7" placeholder="Tell listeners a little about your sound, your story and your show…"><?= deseo_mylive_e((string)($publicProfile['draft_bio'] ?? '')) ?></textarea>
                    <small>Έως 1.600 χαρακτήρες. Το αρχικό κείμενο έχει εισαχθεί από την Season 6 αίτησή σου, όπου υπήρχε.</small>
                </label>

                <div class="public-profile-links">
                    <label>
                        <span>Instagram</span>
                        <input type="url" name="instagram" value="<?= deseo_mylive_e((string)($publicProfile['draft_instagram'] ?? '')) ?>" placeholder="https://instagram.com/...">
                    </label>
                    <label>
                        <span>TikTok</span>
                        <input type="url" name="tiktok" value="<?= deseo_mylive_e((string)($publicProfile['draft_tiktok'] ?? '')) ?>" placeholder="https://tiktok.com/@...">
                    </label>
                    <label>
                        <span>SoundCloud</span>
                        <input type="url" name="soundcloud" value="<?= deseo_mylive_e((string)($publicProfile['draft_soundcloud'] ?? '')) ?>" placeholder="https://soundcloud.com/...">
                    </label>
                    <label>
                        <span>Spotify</span>
                        <input type="url" name="spotify" value="<?= deseo_mylive_e((string)($publicProfile['draft_spotify'] ?? '')) ?>" placeholder="https://open.spotify.com/...">
                    </label>
                    <label class="public-profile-wide">
                        <span>Website</span>
                        <input type="url" name="website" value="<?= deseo_mylive_e((string)($publicProfile['draft_website'] ?? '')) ?>" placeholder="https://...">
                    </label>
                </div>

                <div class="public-profile-actions">
                    <div>
                        <strong>No photo upload here.</strong>
                        <span>Το public modal χρησιμοποιεί πάντα τη φωτογραφία που έχει ορίσει το Deseo Radio στο πρόγραμμα.</span>
                    </div>
                    <div>
                        <button class="profile-draft-button" type="submit" name="action" value="save_public_profile">Save Draft</button>
                        <?php if (!empty($publicProfile['is_published'])): ?>
                            <button class="profile-unpublish-button"
                                    type="submit"
                                    name="action"
                                    value="unpublish_public_profile">
                                Unpublish
                            </button>
                        <?php endif; ?>
                        <button class="primary-button" type="submit" name="action" value="publish_public_profile">
                            <?= !empty($publicProfile['is_published']) ? 'Update Published Profile' : 'Publish Profile' ?>
                        </button>
                    </div>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="assets-section mylive-anchor-section" id="assets">
        <div class="section-head">
            <div>
                <span class="eyebrow">FROM DESEO RADIO · ILUMA Digital Agency</span>
                <h2>Your Assets</h2>
                <p>Το επίσημο artwork, το personal imaging και ό,τι δημιουργούμε για το show σου.</p>
            </div>
            <strong><?= count($assets) ?></strong>
        </div>

        <?php if (!$assets): ?>
            <div class="assets-empty">
                <span>COMING HERE</span>
                <strong>Τα προσωπικά σου assets θα εμφανιστούν εδώ.</strong>
                <p>Εδώ θα εμφανίζονται οι εικόνες για τα social media και τα προσωπικά σου audio spots από την ομάδα Creative της ILUMA Digital Agency. Από εδώ μπορείς να κατεβάζεις και να χρησιμοποιείς όλα τα διαθέσιμα assets για την προώθηση και την παρουσίαση του show σου.</p>
            </div>
        <?php else: ?>
            <div class="assets-grid">
                <?php foreach ($assets as $asset): ?>
                    <?php
                    $isImage = str_starts_with((string)$asset['mime_type'], 'image/');
                    $isAudio = str_starts_with((string)$asset['mime_type'], 'audio/');
                    ?>
                    <article class="asset-card">
                        <div class="asset-visual <?= $isImage ? 'has-preview' : '' ?>">
                            <?php if ($isImage): ?>
                                <img src="/mylive/asset.php?id=<?= (int)$asset['id'] ?>&view=1" alt="<?= deseo_mylive_e($asset['title']) ?>">
                            <?php else: ?>
                                <span><?= $isAudio ? 'AUDIO' : strtoupper(pathinfo((string)$asset['original_name'], PATHINFO_EXTENSION)) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="asset-body">
                            <span><?= deseo_mylive_e(deseo_mylive_asset_label((string)$asset['asset_type'])) ?></span>
                            <h3><?= deseo_mylive_e($asset['title']) ?></h3>
                            <p><?= deseo_mylive_e(deseo_mylive_format_bytes((int)$asset['file_size'])) ?> · <?= deseo_mylive_e(date('d.m.Y', strtotime((string)$asset['created_at']))) ?></p>
                            <a href="/mylive/asset.php?id=<?= (int)$asset['id'] ?>" class="asset-download">Download ↓</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="delivery-section mylive-anchor-section" id="sets">
        <div class="section-head delivery-head">
            <div>
                <span class="eyebrow">DJ SET DELIVERY</span>
                <h2>Your DJ Sets</h2>
                <p>Upload το επόμενο episode. Το filename και το EP number δημιουργούνται αυτόματα.</p>
            </div>
            <strong><?= count($sets) ?></strong>
        </div>

        <section class="upload-card">
            <div class="upload-copy">
                <span>NEXT DELIVERY</span>
                <h2>EP<?= str_pad((string)$nextEpisode, 3, '0', STR_PAD_LEFT) ?></h2>
                <p>MP3 · 192 kbps · Stereo · έως 1 GB</p>
            </div>

            <form id="uploadForm" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= deseo_mylive_e(deseo_mylive_csrf()) ?>">
                <input type="hidden" name="action" value="upload">
                <input id="setFile" type="file" name="dj_set" accept=".mp3,audio/mpeg" hidden required>

                <label class="drop-zone" for="setFile" id="dropZone">
                    <span class="plus">+</span>
                    <strong id="dropTitle">Upload DJ Set</strong>
                    <small id="dropText">Πάτησε εδώ ή σύρε το αρχείο σου</small>
                </label>

                <div class="upload-actions" id="uploadActions" hidden>
                    <div class="progress-track"><span id="progressBar"></span></div>
                    <button class="primary-button" type="submit" id="uploadButton">Upload EP<?= str_pad((string)$nextEpisode, 3, '0', STR_PAD_LEFT) ?></button>
                </div>
            </form>
        </section>

        <div class="repository">
            <div class="repository-head">
                <div>
                    <span class="eyebrow">YOUR REPOSITORY</span>
                    <h3>Episodes</h3>
                    <p>Τα BROADCASTED episodes παραμένουν στο ιστορικό σου, αλλά το audio file αφαιρείται αμέσως από τον server μόλις ολοκληρωθεί η μετάδοση.</p>
                </div>
                <strong><?= count($sets) ?> upload<?= count($sets) === 1 ? '' : 's' ?></strong>
            </div>

            <?php if (!$sets): ?>
                <div class="empty-repository">
                    <strong>Δεν έχεις ανεβάσει ακόμη κάποιο set.</strong>
                    <p>Το πρώτο σου upload θα εμφανιστεί εδώ ως EP001.</p>
                </div>
            <?php else: ?>
                <div class="set-list">
                    <?php foreach ($sets as $set): ?>
                        <article class="set-row">
                            <div class="episode-number">EP<?= str_pad((string)(int)$set['episode_no'], 3, '0', STR_PAD_LEFT) ?></div>
                            <div class="set-details">
                                <strong><?= deseo_mylive_e($set['stored_name']) ?></strong>
                                <span><?= deseo_mylive_e(date('d.m.Y · H:i', strtotime((string)$set['uploaded_at']))) ?> · <?= deseo_mylive_e(deseo_mylive_format_bytes((int)$set['file_size'])) ?></span>
                                <?php if (!empty($set['admin_note'])): ?><small><?= deseo_mylive_e($set['admin_note']) ?></small><?php endif; ?>
                            </div>
                            <div class="set-status">
                                <span class="status-<?= deseo_mylive_e((string)$set['status']) ?>"><?= deseo_mylive_e(strtoupper(str_replace('_', ' ', (string)$set['status']))) ?></span>

                                <?php if (!empty($set['file_deleted_at'])): ?>
                                    <small class="set-retention is-deleted">
                                        FILE REMOVED · episode retained
                                    </small>
                                <?php else: ?>
                                    <a href="/mylive/download.php?id=<?= (int)$set['id'] ?>">Download</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="mylive-station-section mylive-anchor-section" id="live" aria-labelledby="mylive-station-title">
        <div class="section-head mylive-station-head">
            <div>
                <span class="eyebrow">DESEO RADIO · LIVE</span>
                <h2 id="mylive-station-title">Listen to the station.</h2>
                <p>Άκου live τον Deseo Radio και δες ποιος βρίσκεται αυτή τη στιγμή στον αέρα.</p>
            </div>
            <span class="mylive-live-state"><i></i> LIVE 24/7</span>
        </div>

        <div class="mylive-station-grid">
            <article class="mylive-player-deck">
                <div class="mylive-station-label">
                    <i class="mylive-live-dot" aria-hidden="true"></i>
                    <span>NOW PLAYING</span>
                </div>
                <div class="mylive-player-frame">
                    <iframe src="https://play.iradios.gr/widget/deseo-radio"
                            width="100%"
                            frameborder="0"
                            allow="autoplay; encrypted-media; clipboard-write;"
                            style="border:none; width:100%; max-width:600px; aspect-ratio:1 / 1; margin:0 auto; display:block; box-shadow:0 20px 40px rgba(0,0,0,0.5); border-radius:32px; overflow:hidden;"></iframe>
                </div>
            </article>

            <article class="mylive-onair-deck">
                <div class="mylive-station-label">
                    <i class="mylive-live-dot is-muted" data-mylive-onair-dot aria-hidden="true"></i>
                    <span>NOW ON AIR</span>
                </div>

                <div class="mylive-onair-card is-loading" id="mylive-onair-card" aria-live="polite">
                    <img src="/assets/img/bg.png" alt="Deseo Radio" data-mylive-onair-image>
                    <div class="mylive-onair-overlay">
                        <span data-mylive-onair-label>LIVE BROADCAST</span>
                        <h3 data-mylive-onair-name>Loading…</h3>
                        <p data-mylive-onair-time>—</p>
                    </div>
                </div>
            </article>
        </div>
    </section>

    <section class="mylive-referral-section mylive-anchor-section" id="rewards">
        <div class="mylive-rewards-ledger">
            <div class="mylive-rewards-head">
                <div>
                    <span class="eyebrow">MY REWARDS · LIVE STATUS</span>
                    <h2>Your referrals.</h2>
                    <p>Παρακολούθησε τις επιχειρήσεις που έχεις συστήσει, την πορεία κάθε συνεργασίας και τα Rewards σου.</p>
                </div>
                <span class="mylive-rewards-count"><?= (int)$rewardsSummary['total'] ?> REFERRAL<?= (int)$rewardsSummary['total'] === 1 ? '' : 'S' ?></span>
            </div>

            <div class="mylive-rewards-stats">
                <article>
                    <span>REFERRALS</span>
                    <strong><?= (int)$rewardsSummary['total'] ?></strong>
                    <small>συνολικά</small>
                </article>
                <article>
                    <span>CONFIRMED</span>
                    <strong><?= (int)$rewardsSummary['confirmed'] ?></strong>
                    <small>campaigns</small>
                </article>
                <article>
                    <span>PENDING</span>
                    <strong><?= deseo_mylive_e(deseo_rewards_money((float)$rewardsSummary['pending'])) ?></strong>
                    <small>reward to be paid</small>
                </article>
                <article class="is-paid">
                    <span>TOTAL PAID</span>
                    <strong><?= deseo_mylive_e(deseo_rewards_money((float)$rewardsSummary['paid'])) ?></strong>
                    <small>completed rewards</small>
                </article>
            </div>

            <?php if (!$rewards): ?>
                <div class="mylive-rewards-empty">
                    <span>NO REFERRALS YET</span>
                    <strong>Το πρώτο σου Reward ξεκινά από μια σύσταση.</strong>
                    <p>Χρησιμοποίησε το προσωπικό σου link παρακάτω. Μόλις η ILUMA καταχωρήσει το referral, θα εμφανιστεί εδώ με live status.</p>
                </div>
            <?php else: ?>
                <div class="mylive-rewards-list">
                    <?php foreach ($rewards as $reward): ?>
                        <details class="mylive-reward-item">
                            <summary>
                                <div class="mylive-reward-business">
                                    <span class="mylive-reward-status <?= deseo_mylive_e(deseo_rewards_status_class((string)$reward['status'])) ?>">
                                        <?= deseo_mylive_e(deseo_rewards_status_label((string)$reward['status'])) ?>
                                    </span>
                                    <strong><?= deseo_mylive_e((string)$reward['business_name']) ?></strong>
                                    <small>
                                        <?= !empty($reward['referred_at'])
                                            ? 'Referral · ' . deseo_mylive_e(date('d.m.Y', strtotime((string)$reward['referred_at'])))
                                            : 'Referral recorded' ?>
                                    </small>
                                </div>
                                <div class="mylive-reward-summary">
                                    <small>YOUR REWARD</small>
                                    <strong><?= deseo_mylive_e(deseo_rewards_money((float)$reward['reward_amount'])) ?></strong>
                                    <i>+</i>
                                </div>
                            </summary>

                            <div class="mylive-reward-details">
                                <div class="mylive-reward-detail-grid">
                                    <div>
                                        <span>CAMPAIGN VALUE</span>
                                        <strong><?= deseo_mylive_e(deseo_rewards_money((float)$reward['campaign_value'])) ?></strong>
                                    </div>
                                    <div>
                                        <span>YOUR SHARE</span>
                                        <strong><?= deseo_mylive_e(number_format((float)$reward['reward_percent'], 2, ',', '.')) ?>%</strong>
                                    </div>
                                    <div>
                                        <span>REWARD</span>
                                        <strong><?= deseo_mylive_e(deseo_rewards_money((float)$reward['reward_amount'])) ?></strong>
                                    </div>
                                    <div>
                                        <span>STATUS</span>
                                        <strong><?= deseo_mylive_e(deseo_rewards_status_label((string)$reward['status'])) ?></strong>
                                    </div>
                                </div>

                                <?php if (!empty($reward['contact_name']) || !empty($reward['contact_email']) || !empty($reward['contact_phone'])): ?>
                                    <div class="mylive-reward-contact">
                                        <span>REFERRAL CONTACT</span>
                                        <?php if (!empty($reward['contact_name'])): ?><strong><?= deseo_mylive_e((string)$reward['contact_name']) ?></strong><?php endif; ?>
                                        <?php if (!empty($reward['contact_email'])): ?><a href="mailto:<?= deseo_mylive_e((string)$reward['contact_email']) ?>"><?= deseo_mylive_e((string)$reward['contact_email']) ?></a><?php endif; ?>
                                        <?php if (!empty($reward['contact_phone'])): ?><small><?= deseo_mylive_e((string)$reward['contact_phone']) ?></small><?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($reward['dj_note'])): ?>
                                    <div class="mylive-reward-note">
                                        <span>UPDATE FROM ILUMA</span>
                                        <p><?= nl2br(deseo_mylive_e((string)$reward['dj_note'])) ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if ((string)$reward['status'] === 'paid' && !empty($reward['paid_at'])): ?>
                                    <div class="mylive-reward-paid">
                                        PAID · <?= deseo_mylive_e(date('d.m.Y', strtotime((string)$reward['paid_at']))) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="mylive-referral-copy">
            <span class="mylive-referral-kicker"><i></i> DJ PARTNER REWARD</span>
            <h2>Φέρε το brand.<br>Κράτα το 15%.</h2>
        </div>

        <div class="mylive-referral-action">
            <p>Ξέρεις μια επιχείρηση που θέλει να ακουστεί στο Deseo Radio; Σύστησέ τη στην ILUMA και κέρδισε <strong>15%</strong> από κάθε νέα διαφημιστική καμπάνια που κλείνει μέσω της δικής σου σύστασης.</p>

            <?php
            $djReferralValue = 'Deseo Radio DJ - ' . trim((string)$account['artist_name']);
            $djReferralUrl = 'https://iluma.gr/contact/?myrewards=' . rawurlencode($djReferralValue);
            ?>
            <div class="mylive-referral-buttons">
                <a href="<?= deseo_mylive_e($djReferralUrl) ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="mylive-referral-button">
                    ΠΡΟΤΕΙΝΕ ΜΙΑ ΕΠΙΧΕΙΡΗΣΗ ↗
                </a>

                <button type="button"
                        class="mylive-referral-copy-button"
                        data-referral-copy
                        data-referral-url="<?= deseo_mylive_e($djReferralUrl) ?>">
                    COPY LINK
                </button>
            </div>
        </div>
    </section>
</main>
</div>

<footer class="mylive-footer">
    <span>Deseo Radio · Season 6</span>
    <strong>Powered by ILUMA Digital Agency</strong>
</footer>

<div class="toast" id="toast" hidden></div>


<script>
(() => {
    const form = document.getElementById('uploadForm');
    const input = document.getElementById('setFile');
    const zone = document.getElementById('dropZone');
    const title = document.getElementById('dropTitle');
    const text = document.getElementById('dropText');
    const actions = document.getElementById('uploadActions');
    const button = document.getElementById('uploadButton');
    const bar = document.getElementById('progressBar');
    const toast = document.getElementById('toast');
    const defaultTabTitle = document.title;

    const setUploadTabProgress = percent => {
        const safePercent = Math.max(0, Math.min(100, Number(percent) || 0));
        document.title = 'Uploading ' + safePercent + '% · MyLive · Deseo Radio';
    };

    const restoreTabTitle = () => {
        document.title = defaultTabTitle;
    };

    const choose = file => {
        if (!file) return;
        title.textContent = file.name;
        text.textContent = (file.size / 1048576).toFixed(1) + ' MB · έτοιμο για upload';
        actions.hidden = false;
        zone.classList.add('has-file');
    };

    input.addEventListener('change', () => choose(input.files[0]));

    ['dragenter','dragover'].forEach(eventName => zone.addEventListener(eventName, event => {
        event.preventDefault();
        zone.classList.add('is-dragging');
    }));
    ['dragleave','drop'].forEach(eventName => zone.addEventListener(eventName, event => {
        event.preventDefault();
        zone.classList.remove('is-dragging');
    }));
    zone.addEventListener('drop', event => {
        const file = event.dataTransfer.files[0];
        if (!file) return;
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        choose(file);
    });

    const showToast = (message, error = false) => {
        toast.textContent = message;
        toast.className = 'toast ' + (error ? 'toast-error' : 'toast-success');
        toast.hidden = false;
    };

    form.addEventListener('submit', event => {
        event.preventDefault();
        if (!input.files.length) return;

        button.disabled = true;
        button.textContent = 'Uploading…';
        bar.style.width = '0%';
        setUploadTabProgress(0);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/mylive/', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.onprogress = event => {
            if (!event.lengthComputable) return;
            const percent = Math.round((event.loaded / event.total) * 100);
            bar.style.width = percent + '%';
            setUploadTabProgress(percent);
        };

        xhr.onload = () => {
            let result = null;
            try { result = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status >= 200 && xhr.status < 300 && result && result.ok) {
                bar.style.width = '100%';
                setUploadTabProgress(100);
                showToast(result.message || 'Το set ανέβηκε.');
                setTimeout(() => {
                    restoreTabTitle();
                    window.location.reload();
                }, 700);
                return;
            }
            restoreTabTitle();
            button.disabled = false;
            button.textContent = 'Try again';
            showToast((result && result.message) || 'Το upload δεν ολοκληρώθηκε.', true);
        };

        xhr.onerror = () => {
            restoreTabTitle();
            button.disabled = false;
            button.textContent = 'Try again';
            showToast('Η σύνδεση διακόπηκε κατά το upload.', true);
        };

        xhr.onabort = () => {
            restoreTabTitle();
            button.disabled = false;
            button.textContent = 'Try again';
        };

        xhr.send(new FormData(form));
    });
})();
</script>

<script>
(function () {
    'use strict';

    var links = Array.prototype.slice.call(document.querySelectorAll('[data-mylive-nav]'));
    if (!links.length) return;

    var sections = links.map(function (link) {
        var target = document.querySelector(link.getAttribute('href'));
        return { link: link, target: target };
    }).filter(function (item) {
        return !!item.target;
    });

    links.forEach(function (link) {
        link.addEventListener('click', function () {
            links.forEach(function (item) { item.classList.remove('is-active'); });
            link.classList.add('is-active');
        });
    });

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            var visible = entries
                .filter(function (entry) { return entry.isIntersecting; })
                .sort(function (a, b) { return b.intersectionRatio - a.intersectionRatio; });

            if (!visible.length) return;

            var id = visible[0].target.id;
            links.forEach(function (link) {
                link.classList.toggle('is-active', link.getAttribute('href') === '#' + id);
            });

            var active = document.querySelector('[data-mylive-nav].is-active');
            if (active && window.innerWidth <= 1100) {
                active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            }
        }, {
            rootMargin: '-18% 0px -58% 0px',
            threshold: [0.05, 0.2, 0.45]
        });

        sections.forEach(function (item) { observer.observe(item.target); });
    }
}());
</script>

<script>
(function () {
    'use strict';

    var card = document.getElementById('mylive-onair-card');
    if (!card) return;

    var image = card.querySelector('[data-mylive-onair-image]');
    var label = card.querySelector('[data-mylive-onair-label]');
    var name = card.querySelector('[data-mylive-onair-name]');
    var time = card.querySelector('[data-mylive-onair-time]');
    var dot = document.querySelector('[data-mylive-onair-dot]');
    var refreshTimer = null;
    var refreshHour = Math.floor(Date.now() / 3600000);

    function programTime(value) {
        return value && typeof value === 'string' ? value.slice(0, 5) : '--:--';
    }

    function renderOnAir(show) {
        card.classList.remove('is-loading');

        if (show) {
            card.classList.remove('is-nonstop');
            image.src = show.photo_path || '/assets/img/bg.png';
            image.alt = show.dj_name || 'Deseo Radio';
            label.textContent = 'LIVE BROADCAST';
            name.textContent = show.dj_name || 'Deseo Radio';
            time.textContent = programTime(show.start_time) + ' — ' + programTime(show.end_time);
            if (dot) dot.classList.remove('is-muted');
            return;
        }

        card.classList.add('is-nonstop');
        image.src = '/assets/img/bg.png';
        image.alt = 'Deseo Radio Non-Stop Mix';
        label.textContent = 'NON-STOP MIX';
        name.textContent = 'DESEO RADIO';
        time.textContent = '24/7';
        if (dot) dot.classList.add('is-muted');
    }

    image.addEventListener('error', function () {
        if (this.getAttribute('src') !== '/assets/img/bg.png') {
            this.setAttribute('src', '/assets/img/bg.png');
        }
    });

    function refreshOnAir() {
        return fetch('/?program_feed=1&_=' + Date.now(), {
            method: 'GET',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (response) {
            if (!response.ok) throw new Error('Program feed unavailable');
            return response.json();
        })
        .then(function (payload) {
            if (!payload) return;
            renderOnAir(payload.live || null);
            refreshHour = Math.floor(Date.now() / 3600000);
        })
        .catch(function () {
            card.classList.remove('is-loading');
            label.textContent = 'LIVE BROADCAST';
            name.textContent = 'DESEO RADIO';
            time.textContent = 'Live status temporarily unavailable';
        });
    }

    function scheduleRefresh() {
        if (refreshTimer) window.clearTimeout(refreshTimer);
        var hour = 60 * 60 * 1000;
        var delay = hour - (Date.now() % hour) + 1200;

        refreshTimer = window.setTimeout(function () {
            refreshOnAir().finally(scheduleRefresh);
        }, delay);
    }

    refreshOnAir().finally(scheduleRefresh);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'visible') return;
        var currentHour = Math.floor(Date.now() / 3600000);
        if (currentHour !== refreshHour) {
            refreshOnAir().finally(scheduleRefresh);
        }
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) refreshOnAir().finally(scheduleRefresh);
    });
}());
</script>

<script>
(function () {
    'use strict';

    document.querySelectorAll('[data-referral-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var url = button.getAttribute('data-referral-url') || '';
            if (!url) return;

            function done() {
                var original = 'COPY LINK';
                button.textContent = 'COPIED ✓';
                button.classList.add('is-copied');
                window.setTimeout(function () {
                    button.textContent = original;
                    button.classList.remove('is-copied');
                }, 1800);
            }

            function showCopyFallback() {
                if (!window.DeseoDialog) return;

                window.DeseoDialog.prompt(
                    'Αντέγραψε το προσωπικό referral link σου από το πεδίο παρακάτω.',
                    url,
                    {
                        title: 'Copy referral link',
                        confirmLabel: 'Close',
                        cancelLabel: 'Cancel',
                        inputLabel: 'Referral link'
                    }
                );
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(done).catch(showCopyFallback);
            } else {
                showCopyFallback();
            }
        });
    });
}());
</script>
</body>
</html>
