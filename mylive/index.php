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

function mylive_password_is_ascii(string $password): bool {
    return $password !== '' && preg_match('/^[\x20-\x7E]+$/D', $password) === 1;
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
            if (!mylive_password_is_ascii($password)) {
                throw new RuntimeException('Ο κωδικός πρέπει να χρησιμοποιεί μόνο αγγλικούς χαρακτήρες, αριθμούς και σύμβολα. Δεν επιτρέπονται ελληνικοί χαρακτήρες.');
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
            if (!mylive_password_is_ascii($password)) {
                throw new RuntimeException('Ο κωδικός πρέπει να χρησιμοποιεί μόνο αγγλικούς χαρακτήρες, αριθμούς και σύμβολα. Δεν επιτρέπονται ελληνικοί χαρακτήρες.');
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
            $preferenceAccount = deseo_mylive_account($pdo, $accountId);
            $guestPreferences = $preferenceAccount
                ? deseo_mylive_is_guest_account($preferenceAccount)
                : false;

            deseo_mylive_save_communication_preferences($pdo, $accountId, [
                'email_enabled' => isset($_POST['email_enabled']),
                'push_enabled' => !empty($currentPreferences['push_enabled']),
                // Guest accounts do not expose recurring controls. Preserve their
                // existing values instead of silently turning them off on save.
                'set_reminder_email' => $guestPreferences
                    ? !empty($currentPreferences['set_reminder_email'])
                    : isset($_POST['set_reminder_email']),
                'set_reminder_push' => $guestPreferences
                    ? !empty($currentPreferences['set_reminder_push'])
                    : isset($_POST['set_reminder_push']),
                'on_air_email' => $guestPreferences
                    ? !empty($currentPreferences['on_air_email'])
                    : isset($_POST['on_air_email']),
                'on_air_push' => $guestPreferences
                    ? !empty($currentPreferences['on_air_push'])
                    : isset($_POST['on_air_push']),
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

            $uploadIsGuest = deseo_mylive_is_guest_account($account);
            $targetProgramId = null;
            $targetShowStart = null;
            $targetShowEnd = null;
            $episodeDjName = trim((string)($_POST['episode_dj_name'] ?? ''));

            if (mb_strlen($episodeDjName) > 180) {
                throw new RuntimeException('Το όνομα του DJ είναι πολύ μεγάλο.');
            }
            if (!empty($account['requires_episode_artist']) && $episodeDjName === '') {
                throw new RuntimeException('Συμπλήρωσε ποιος DJ παίζει σε αυτό το slot πριν ανεβάσεις το αρχείο.');
            }

            if (!$uploadIsGuest) {
                $uploadSlots = deseo_mylive_program_slots($pdo, $accountId);
                $deliveryShows = deseo_mylive_delivery_shows(
                    $pdo,
                    $accountId,
                    new DateTimeImmutable('now', dj_season_athens_timezone())
                );
                if ($uploadSlots && !$deliveryShows) {
                    throw new RuntimeException('Δεν υπάρχει διαθέσιμη επόμενη μετάδοση για νέο DJ Set μέσα στη Season 6.');
                }
                if ($deliveryShows) {
                    $requestedProgramId = isset($_POST['program_id']) ? (int)$_POST['program_id'] : -1;
                    if ($requestedProgramId === -1 && count($deliveryShows) === 1) {
                        $requestedProgramId = (int)($deliveryShows[0]['program_id'] ?? 0);
                    }

                    $selectedDelivery = null;
                    foreach ($deliveryShows as $deliveryShow) {
                        if ((int)($deliveryShow['program_id'] ?? 0) === $requestedProgramId) {
                            $selectedDelivery = $deliveryShow;
                            break;
                        }
                    }
                    if (!$selectedDelivery) {
                        throw new RuntimeException('Επίλεξε σε ποιο weekly slot ανήκει αυτό το DJ Set.');
                    }

                    $targetProgramId = (int)($selectedDelivery['program_id'] ?? 0);
                    $targetShowStart = $selectedDelivery['show_start']->format('Y-m-d H:i:s');
                    $targetShowEnd = $selectedDelivery['show_end']->format('Y-m-d H:i:s');
                }
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

            if ($targetShowStart !== null && $targetProgramId !== null) {
                $duplicateTarget = $pdo->prepare(
                    "SELECT id FROM dj_portal_sets
                     WHERE account_id = ? AND target_program_id = ? AND target_show_start = ?
                     LIMIT 1"
                );
                $duplicateTarget->execute([$accountId, $targetProgramId, $targetShowStart]);
                if ($duplicateTarget->fetchColumn()) {
                    throw new RuntimeException('Έχει ήδη ανέβει DJ Set για αυτή τη συγκεκριμένη μετάδοση.');
                }
            }

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
                     (account_id, episode_no, original_name, stored_name, file_path, file_size, mime_type, status,
                      target_program_id, target_show_start, target_show_end, episode_dj_name)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'received', ?, ?, ?, ?)"
                );
                $insert->execute([
                    $accountId,
                    $episode,
                    $originalName,
                    $storedName,
                    $relativeDir . '/' . $storedName,
                    $size,
                    $mime,
                    $targetProgramId,
                    $targetShowStart,
                    $targetShowEnd,
                    $episodeDjName
                ]);
                $pdo->commit();
            } catch (Throwable $dbError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                @unlink($absolutePath);
                throw $dbError;
            }

            try {
                $uploadNotification = deseo_mylive_set_uploaded_internal_email(
                    $account,
                    $episode,
                    $storedName,
                    $originalName,
                    $episodeDjName,
                    (string)($targetShowStart ?? '')
                );

                foreach (deseo_mylive_internal_notification_recipients() as $recipient) {
                    try {
                        deseo_send_smtp_mail(
                            (string)$recipient['email'],
                            (string)$recipient['name'],
                            (string)$uploadNotification['subject'],
                            (string)$uploadNotification['html'],
                            (string)$uploadNotification['text'],
                            true
                        );
                    } catch (Throwable $notificationRecipientError) {
                        error_log(
                            'MyLive DJ set upload notification failed for '
                            . (string)$recipient['email']
                            . ' · account '
                            . $accountId
                            . ' · EP'
                            . str_pad((string)$episode, 3, '0', STR_PAD_LEFT)
                            . ': '
                            . $notificationRecipientError->getMessage()
                        );
                    }
                }
            } catch (Throwable $notificationError) {
                error_log(
                    'MyLive DJ set upload notification build failed for account '
                    . $accountId
                    . ' · EP'
                    . str_pad((string)$episode, 3, '0', STR_PAD_LEFT)
                    . ': '
                    . $notificationError->getMessage()
                );
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
                            <input id="resetNewPassword" type="password" name="new_password" minlength="8" pattern="[\x20-\x7E]{8,}" title="Use only English characters, numbers and symbols." autocomplete="new-password" data-latin-password required autofocus>
                            <button type="button" class="password-toggle" data-password-toggle="resetNewPassword">Show</button>
                        </div>
                    </label>

                    <label>
                        <span>Confirm password</span>
                        <div class="password-field">
                            <input id="resetConfirmPassword" type="password" name="confirm_password" minlength="8" pattern="[\x20-\x7E]{8,}" title="Use only English characters, numbers and symbols." autocomplete="new-password" data-latin-password required>
                            <button type="button" class="password-toggle" data-password-toggle="resetConfirmPassword">Show</button>
                        </div>
                    </label>

                    <small class="mylive-reset-help">Τουλάχιστον 8 χαρακτήρες · μόνο αγγλικοί χαρακτήρες, αριθμοί και σύμβολα. Δεν επιτρέπονται ελληνικά.</small>
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
                <a class="mylive-recovery-back" href="/mylive/">Back to login</a>
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

            <a class="mylive-recovery-back" href="/mylive/">Back to login</a>

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

    
</main>
<script>
document.querySelectorAll('[data-latin-password]').forEach(input => {
    const validatePasswordAlphabet = () => {
        const valid = /^[\x20-\x7E]*$/.test(input.value);
        input.setCustomValidity(valid ? '' : 'Χρησιμοποίησε μόνο αγγλικούς χαρακτήρες, αριθμούς και σύμβολα.');
    };
    input.addEventListener('input', validatePasswordAlphabet);
    validatePasswordAlphabet();
});

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
                    <input id="newPassword" type="password" name="new_password" minlength="8" pattern="[\x20-\x7E]{8,}" title="Use only English characters, numbers and symbols." autocomplete="new-password" data-latin-password required autofocus>
                    <button type="button" class="password-toggle" data-password-toggle="newPassword">Show</button>
                </div>
            </label>

            <label>
                <span>Confirm password</span>
                <div class="password-field">
                    <input id="confirmPassword" type="password" name="confirm_password" minlength="8" pattern="[\x20-\x7E]{8,}" title="Use only English characters, numbers and symbols." autocomplete="new-password" data-latin-password required>
                    <button type="button" class="password-toggle" data-password-toggle="confirmPassword">Show</button>
                </div>
            </label>

            <small>Τουλάχιστον 8 χαρακτήρες · μόνο αγγλικοί χαρακτήρες, αριθμοί και σύμβολα. Δεν επιτρέπονται ελληνικά. Αποθήκευσε το email και τον νέο κωδικό στον browser / password manager σου.</small>
            <button class="primary-button" type="submit">Save & open MyLive</button>
        </form>
    </section>
</main>
<script>
document.querySelectorAll('[data-latin-password]').forEach(input => {
    const validatePasswordAlphabet = () => {
        const valid = /^[\x20-\x7E]*$/.test(input.value);
        input.setCustomValidity(valid ? '' : 'Χρησιμοποίησε μόνο αγγλικούς χαρακτήρες, αριθμούς και σύμβολα.');
    };
    input.addEventListener('input', validatePasswordAlphabet);
    validatePasswordAlphabet();
});

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

$isGuestAccount = deseo_mylive_is_guest_account($account);

$communicationPrefs = deseo_mylive_communication_preferences($pdo, (int)$account['id']);

$publicProfile = !empty($account['public_profile_enabled'])
    ? deseo_mylive_public_profile($pdo, (int)$account['id'])
    : null;
$publicProfileHasChanges = $publicProfile
    ? deseo_mylive_public_profile_has_unpublished_changes($publicProfile)
    : false;

$sets = deseo_mylive_sets($pdo, (int)$account['id']);
$weeklySlots = $isGuestAccount ? [] : deseo_mylive_program_slots($pdo, (int)$account['id']);
$upcomingShows = $isGuestAccount ? [] : deseo_mylive_upcoming_shows(
    $pdo,
    (int)$account['id'],
    new DateTimeImmutable('now', dj_season_athens_timezone())
);
$deliveryShows = $isGuestAccount ? [] : deseo_mylive_delivery_shows(
    $pdo,
    (int)$account['id'],
    new DateTimeImmutable('now', dj_season_athens_timezone())
);
$requiresEpisodeArtist = !empty($account['requires_episode_artist']);
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
$accountDay = isset($account['day_of_week']) ? (int)$account['day_of_week'] : 0;
$dayLabel = deseo_mylive_day_label($accountDay > 0 ? $accountDay : null);
$startTime = deseo_mylive_format_time((string)$account['start_time']);
$myliveSlotLabel = deseo_mylive_slot($account);

if (!$isGuestAccount && $weeklySlots) {
    $slotLabels = [];
    foreach ($weeklySlots as $weeklySlot) {
        $slotLabels[] = dj_season_day_label((int)$weeklySlot['day_of_week'])
            . ' · ' . deseo_mylive_format_time((string)$weeklySlot['start_time']);
    }
    $myliveSlotLabel = implode('  /  ', $slotLabels);
    $firstSlot = $weeklySlots[0];
    $accountDay = (int)$firstSlot['day_of_week'];
    $dayLabel = dj_season_day_label($accountDay);
    $startTime = deseo_mylive_format_time((string)$firstSlot['start_time']);
}

if ($isGuestAccount && dj_season_is_guest_zone_slot($accountDay, (string)$account['start_time'])) {
    $displayDay = dj_season_slot_display_day($accountDay, (string)$account['start_time']);
    $dayLabel = dj_season_day_label($displayDay);
    $myliveSlotLabel = 'Guest DJ Zone · ' . $dayLabel . ' · ' . $startTime;
} elseif ($isGuestAccount) {
    $myliveSlotLabel = 'Guest DJ · ' . $myliveSlotLabel;
}

$athensTz = dj_season_athens_timezone();
$nowAthens = new DateTimeImmutable('now', $athensTz);
$nextShowSeasonComplete = false;
$nextShowStart = null;
$nextShowEnd = null;
$nextShowProgramId = null;
$nextShowIsLive = false;
$nextShowWhen = 'Schedule pending';
$nextShowDate = '—';
$nextShowTime = $startTime;
$nextShowDay = $dayLabel;

if ($isGuestAccount) {
    $nextShowWhen = 'One-time appearance';
    $nextShowDate = 'Date to be confirmed';
    $nextShowDay = 'Guest DJ Zone';
} elseif ($upcomingShows) {
    $nearest = $upcomingShows[0];
    $nextShowStart = $nearest['show_start'];
    $nextShowEnd = $nearest['show_end'];
    $nextShowProgramId = (int)($nearest['program_id'] ?? 0);
    $nextShowIsLive = $nowAthens >= $nextShowStart && $nowAthens < $nextShowEnd;
    $nextShowDay = dj_season_day_label((int)$nearest['day_of_week']);
    $nextShowDate = $nextShowStart->format('d.m.Y');
    $nextShowTime = $nextShowStart->format('H:i');

    $today = $nowAthens->setTime(0, 0, 0);
    $showKey = $nextShowStart->format('Y-m-d');
    if ($nextShowIsLive) {
        $nextShowWhen = 'LIVE NOW';
    } elseif ($showKey === $nowAthens->format('Y-m-d')) {
        $nextShowWhen = 'Today';
    } elseif ($showKey === $nowAthens->modify('+1 day')->format('Y-m-d')) {
        $nextShowWhen = 'Tomorrow';
    } else {
        $daysToShow = (int)$today->diff($nextShowStart->setTime(0, 0))->format('%a');
        $nextShowWhen = 'In ' . $daysToShow . ' days';
    }
} elseif ($nowAthens >= dj_season_start_at()) {
    $nextShowSeasonComplete = true;
    $nextShowDay = 'SEASON 6';
    $nextShowTime = '';
    $nextShowDate = '30.05.2027';
    $nextShowWhen = 'Completed';
}

$nextShowSet = null;
if (!$isGuestAccount && $nextShowStart instanceof DateTimeImmutable && $nextShowProgramId !== null) {
    foreach ($sets as $setCandidate) {
        if ((int)($setCandidate['target_program_id'] ?? -1) !== $nextShowProgramId) continue;
        if ((string)($setCandidate['target_show_start'] ?? '') !== $nextShowStart->format('Y-m-d H:i:s')) continue;
        $nextShowSet = $setCandidate;
        break;
    }
}
if ($nextShowSet === null && (count($weeklySlots) <= 1 || $isGuestAccount)) {
    foreach ($sets as $setCandidate) {
        if ((string)($setCandidate['status'] ?? '') !== 'broadcasted'
            && empty($setCandidate['target_show_start'])) {
            $nextShowSet = $setCandidate;
            break;
        }
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

if ($nextShowSeasonComplete) {
    $nextShowStatusLabel = 'SEASON COMPLETE';
    $nextShowStatusClass = 'is-ready';
}

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
    default => $isGuestAccount
        ? 'Η Guest εμφάνισή σου είναι one-off. Η ομάδα του Deseo θα επιβεβαιώσει ξεχωριστά την ημερομηνία μετάδοσης.'
        : 'Δεν έχει ανέβει ακόμη set για το επόμενο episode.',
};

if ($nextShowSeasonComplete) {
    $nextShowMessage = 'Δεν υπάρχει άλλη εβδομαδιαία μετάδοση για αυτό το slot μέσα στη Season 6, η οποία ολοκληρώνεται στις 30.05.2027.';
}

$nextShowStartIso = $nextShowStart instanceof DateTimeImmutable
    ? $nextShowStart->format(DateTimeInterface::ATOM)
    : '';
$nextShowEndIso = $nextShowEnd instanceof DateTimeImmutable
    ? $nextShowEnd->format(DateTimeInterface::ATOM)
    : '';
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

    <!-- Critical dashboard styles: platform icons must remain bounded even with a stale external CSS cache. -->
    <style>
      .mylive-dashboard-page .mylive-episode-platforms{
        display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;
        align-items:center;gap:24px;min-width:0;margin-top:24px;padding:24px;
        border:1px solid rgba(255,255,255,.1);border-radius:24px;
        background:linear-gradient(118deg,rgba(255,43,54,.065),rgba(255,255,255,.018) 40%,#101012);
      }
      .mylive-dashboard-page .mylive-episode-platforms-copy{min-width:0}
      .mylive-dashboard-page .mylive-episode-platforms-copy .eyebrow{
        display:block;color:var(--red,#ff2b36);font-size:10px;font-weight:800;letter-spacing:.13em;
      }
      .mylive-dashboard-page .mylive-episode-platforms-copy h4{
        margin:8px 0!important;color:#f4f4f5;font-size:clamp(21px,2.3vw,27px)!important;
        line-height:1.15;letter-spacing:-.035em;
      }
      .mylive-dashboard-page .mylive-episode-platforms-copy p{
        max-width:380px;margin:0;color:#a6a6ae;font-size:13px!important;line-height:1.55;
      }
      .mylive-dashboard-page .mylive-episode-platform-links{
        display:grid!important;grid-template-columns:repeat(3,minmax(0,112px))!important;
        gap:10px;min-width:0;
      }
      .mylive-dashboard-page .mylive-episode-platform-links>a{
        display:flex!important;flex-direction:column!important;align-items:center;justify-content:center;
        gap:10px;box-sizing:border-box;min-width:0;width:100%;min-height:110px;padding:12px 8px;
        border:1px solid rgba(255,255,255,.13);border-radius:17px;
        background:rgba(255,255,255,.025);color:#e4e4e9;text-align:center;text-decoration:none;
        font-size:12px!important;font-weight:700;line-height:1.3;overflow-wrap:anywhere;
      }
      .mylive-dashboard-page .mylive-episode-platform-links>a:hover{
        border-color:rgba(255,43,54,.48);background:rgba(255,43,54,.075);color:#fff;
      }
      .mylive-dashboard-page .mylive-episode-platform-links>a:focus-visible{
        outline:2px solid var(--red,#ff2b36);outline-offset:3px;
      }
      .mylive-dashboard-page .mylive-episode-platform-links .mylive-episode-platform-icon{
        display:flex!important;flex:0 0 38px!important;align-items:center;justify-content:center;
        width:38px!important;height:38px!important;max-width:38px!important;max-height:38px!important;
        padding:0!important;color:#eeeef0;
      }
      .mylive-dashboard-page .mylive-episode-platform-links .mylive-episode-platform-icon>svg{
        display:block!important;flex:none!important;box-sizing:content-box;
        width:31px!important;height:31px!important;min-width:31px!important;min-height:31px!important;
        max-width:31px!important;max-height:31px!important;overflow:visible;
      }
      @media(max-width:800px){
        .mylive-dashboard-page .mylive-episode-platforms{
          grid-template-columns:minmax(0,1fr)!important;gap:20px;
        }
        .mylive-dashboard-page .mylive-episode-platforms-copy p{max-width:560px}
        .mylive-dashboard-page .mylive-episode-platform-links{
          grid-template-columns:repeat(3,minmax(0,1fr))!important;
        }
      }
      @media(max-width:420px){
        .mylive-dashboard-page .mylive-episode-platforms{padding:19px 15px;border-radius:21px}
        .mylive-dashboard-page .mylive-episode-platform-links{gap:7px}
        .mylive-dashboard-page .mylive-episode-platform-links>a{
          min-height:102px;padding:10px 4px;font-size:11px!important;
        }
      }
    </style>
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
                <a href="#assets" data-mylive-nav>
                    <i>02</i><span>My Assets</span>
                </a>
                <a href="#sets" data-mylive-nav>
                    <i>03</i><span>My DJ Sets</span>
                </a>
                <a href="#live" data-mylive-nav>
                    <i>04</i><span>Listen Live</span>
                </a>
                <?php if ($publicProfile): ?>
                    <a href="#profile" data-mylive-nav>
                        <i>05</i><span>My Profile</span>
                    </a>
                <?php endif; ?>
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
        <div class="slot-pill"
             data-mylive-own-status
             data-account-id="<?= (int)$account['id'] ?>"
             data-account-mode="<?= $isGuestAccount ? 'guest' : 'resident' ?>"
             data-weekly-slot="<?= deseo_mylive_e($myliveSlotLabel) ?>">
            <span class="slot-pill-kicker">
                <i class="slot-live-bullet" aria-hidden="true"></i>
                <b data-mylive-own-label><?= $isGuestAccount ? 'GUEST DJ ACCESS' : (count($weeklySlots) > 1 ? 'YOUR WEEKLY SLOTS' : 'YOUR WEEKLY SLOT') ?></b>
            </span>
            <strong data-mylive-own-main><?= deseo_mylive_e($myliveSlotLabel) ?></strong>
            <small data-mylive-own-slot hidden><?= deseo_mylive_e($myliveSlotLabel) ?></small>
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

    <?php if (empty($communicationPrefs['push_enabled'])): ?>
        <section class="mylive-quick-push-card" data-mylive-push-quick>
            <div class="mylive-quick-push-copy">
                <span>PUSH NOTIFICATIONS</span>
                <strong>Μείνε ενημερωμένος για το show σου.</strong>
                <p><?= $isGuestAccount
                    ? 'Ενεργοποίησε push alerts για ανακοινώσεις και ενημερώσεις του MyLive. Τα Guest accounts δεν έχουν weekly recurrence.'
                    : 'Ενεργοποίησε push alerts για DJ Set reminders και το ON AIR NOW του MyLive.' ?></p>
            </div>
            <button type="button" class="mylive-quick-push-button" data-mylive-push-quick-enable>
                ENABLE PUSH ALERTS
            </button>
        </section>
    <?php endif; ?>

    <section class="mylive-next-show <?= $nextShowIsLive ? 'is-live' : '' ?>" aria-label="Next show">
        <div class="mylive-next-show-main">
            <div class="mylive-next-show-kicker">
                <span><i></i> <?= $isGuestAccount ? 'GUEST APPEARANCE' : 'NEXT SHOW' ?></span>
                <b class="<?= deseo_mylive_e($nextShowStatusClass) ?>"><?= deseo_mylive_e($nextShowStatusLabel) ?></b>
            </div>

            <div class="mylive-next-show-title">
                <div>
                    <strong><?= deseo_mylive_e($nextShowDay) ?><?= $nextShowTime !== '' ? ' · ' . deseo_mylive_e($nextShowTime) : '' ?></strong>
                    <span><?= deseo_mylive_e($nextShowDate) ?><?= $nextShowWhen !== '' ? ' · ' . deseo_mylive_e($nextShowWhen) : '' ?></span>
                    <?php if (!$isGuestAccount && $nextShowStartIso !== '' && !$nextShowSeasonComplete): ?>
                        <small class="mylive-next-show-countdown"
                               data-mylive-next-countdown
                               data-start="<?= deseo_mylive_e($nextShowStartIso) ?>"
                               data-end="<?= deseo_mylive_e($nextShowEndIso) ?>">
                            Countdown to broadcast
                        </small>
                    <?php endif; ?>
                </div>
                <div class="mylive-next-episode">
                    <?php if ($nextShowSeasonComplete): ?>
                        <span>SEASON</span>
                        <strong>06</strong>
                    <?php else: ?>
                        <span>NEXT EPISODE</span>
                        <strong>EP<?= str_pad((string)$nextShowEpisode, 3, '0', STR_PAD_LEFT) ?></strong>
                    <?php endif; ?>
                </div>
            </div>

            <p class="mylive-next-show-message"><?= deseo_mylive_e($nextShowMessage) ?></p>

            <?php if (!$nextShowSeasonComplete): ?>
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
            <?php endif; ?>
        </div>

        <div class="mylive-next-show-action">
            <span><?= $nextShowIsLive ? 'ON AIR NOW' : 'DESEO RADIO · SEASON 6' ?></span>
            <?php if ($nextShowSeasonComplete): ?>
                <strong>Season 6 complete.</strong>
            <?php elseif ($nextShowSetStatus === 'not_uploaded'): ?>
                <strong>Your set is next.</strong>
                <a href="#sets">UPLOAD DJ SET</a>
            <?php elseif ($nextShowSetStatus === 'needs_changes'): ?>
                <strong>Action required.</strong>
                <a href="#sets">VIEW DJ SET</a>
            <?php elseif ($nextShowSetStatus === 'scheduled'): ?>
                <strong>Ready for broadcast.</strong>
                <a href="#sets">VIEW EP<?= str_pad((string)$nextShowEpisode, 3, '0', STR_PAD_LEFT) ?></a>
            <?php else: ?>
                <strong>Delivery in progress.</strong>
                <a href="#sets">VIEW DJ SET</a>
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
                            <div class="asset-actions">
                                <a href="/mylive/asset.php?id=<?= (int)$asset['id'] ?>" class="asset-download">Download</a>
                                <?php if ($isImage): ?>
                                    <button
                                        type="button"
                                        class="asset-share"
                                        data-mylive-share-asset
                                        data-share-url="/mylive/asset.php?id=<?= (int)$asset['id'] ?>&view=1"
                                        data-share-name="<?= deseo_mylive_e((string)$asset['original_name']) ?>"
                                        data-share-title="<?= deseo_mylive_e((string)$asset['title']) ?>"
                                        data-share-mime="<?= deseo_mylive_e((string)$asset['mime_type']) ?>">
                                        Share it
                                    </button>
                                <?php endif; ?>
                            </div>
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

                <?php if (!$isGuestAccount && $deliveryShows): ?>
                    <div class="form-grid" style="margin-bottom:14px;">
                        <div class="field full">
                            <label>Broadcast slot</label>
                            <select name="program_id" required>
                                <?php foreach ($deliveryShows as $deliveryShow): ?>
                                    <option value="<?= (int)($deliveryShow['program_id'] ?? 0) ?>">
                                        <?= deseo_mylive_e(
                                            dj_season_day_label((int)$deliveryShow['day_of_week'])
                                            . ' · ' . $deliveryShow['show_start']->format('d.m.Y')
                                            . ' · ' . $deliveryShow['show_start']->format('H:i')
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($requiresEpisodeArtist): ?>
                            <div class="field full">
                                <label>DJ / Artist playing this slot</label>
                                <input type="text" name="episode_dj_name" maxlength="180" required
                                       placeholder="π.χ. John Doe">
                                <small>Υποχρεωτικό για αυτό το radioshow πριν από κάθε upload.</small>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($requiresEpisodeArtist): ?>
                    <div class="form-grid" style="margin-bottom:14px;">
                        <div class="field full">
                            <label>DJ / Artist playing this slot</label>
                            <input type="text" name="episode_dj_name" maxlength="180" required
                                   placeholder="π.χ. John Doe">
                        </div>
                    </div>
                <?php endif; ?>

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
                    <p>Μετά τη μετάδοση, μόλις δημοσιευτεί το DJ Set σου στο HearThis, το προσωπικό του link θα εμφανιστεί εδώ.</p>
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
                                <?php if (!empty($set['episode_dj_name'])): ?>
                                    <small><b>DJ:</b> <?= deseo_mylive_e((string)$set['episode_dj_name']) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($set['target_show_start'])): ?>
                                    <small><b>Broadcast:</b> <?= deseo_mylive_e(date('d.m.Y · H:i', strtotime((string)$set['target_show_start']))) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($set['admin_note'])): ?><small><?= deseo_mylive_e($set['admin_note']) ?></small><?php endif; ?>
                            </div>
                            <div class="set-status">
                                <span class="status-<?= deseo_mylive_e((string)$set['status']) ?>"><?= deseo_mylive_e(strtoupper(str_replace('_', ' ', (string)$set['status']))) ?></span>

                                <?php
                                // Owned, ID-backed URL is available immediately
                                // after upload acceptance; RSS may still be pending.
                                $episodeHearThisUrl = deseo_mylive_hearthis_episode_link($set, (int)$account['id']);
                                ?>
                                <?php if ($episodeHearThisUrl !== ''): ?>
                                    <a href="<?= deseo_mylive_e($episodeHearThisUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Listen to EP<?= (int)$set['episode_no'] ?> on HearThis">Το link του DJ Set σου</a>
                                <?php elseif ((string)($set['status'] ?? '') === 'broadcasted'): ?>
                                    <small>Το link σου ετοιμάζεται.</small>
                                <?php endif; ?>
                                <?php if (!empty($set['file_deleted_at'])): ?>
                                    <small class="set-retention is-deleted">
                                        Το DJ Set σου παραμένει στο ιστορικό.
                                    </small>
                                <?php else: ?>
                                    <a href="/mylive/download.php?id=<?= (int)$set['id'] ?>">Download</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="mylive-episode-platforms" aria-label="Οι πλατφόρμες του DJ Set σου">
                <div class="mylive-episode-platforms-copy">
                    <span class="eyebrow">ΜΕΤΑ ΤΗ ΜΕΤΑΔΟΣΗ</span>
                    <h4>Το DJ Set σου, παντού.</h4>
                    <p>Μετά τη μετάδοση στον Deseo Radio, το set σου θα δημοσιεύεται σταδιακά και στις τρεις πλατφόρμες.</p>
                </div>
                <div class="mylive-episode-platform-links">
                    <a href="https://podcasts.apple.com/us/podcast/deseo-radioshows/id1711008342" target="_blank" rel="noopener noreferrer" aria-label="Apple Podcasts · Deseo RadioShows">
                        <span class="mylive-episode-platform-icon" aria-hidden="true">
                            <svg width="31" height="31" style="display:block;width:31px!important;height:31px!important;max-width:31px!important;max-height:31px!important;flex:none!important" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                <circle cx="16" cy="14" r="2.3"/><path d="M13.2 21c.2-2 1.3-3.4 2.8-3.4s2.6 1.4 2.8 3.4l-.6 6h-4.4l-.6-6ZM9.5 19a9 9 0 1 1 13 0M12 17a5.5 5.5 0 1 1 8 0"/>
                            </svg>
                        </span>
                        <span>Apple Podcasts</span>
                    </a>
                    <a href="https://hearthis.at/deseoradio/set/season-6/" target="_blank" rel="noopener noreferrer" aria-label="HearThis · Deseo Radio Season 6">
                        <span class="mylive-episode-platform-icon" aria-hidden="true">
                            <svg width="31" height="31" style="display:block;width:31px!important;height:31px!important;max-width:31px!important;max-height:31px!important;flex:none!important" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                <path d="M16 27S4.5 20.2 4.5 12.5a6 6 0 0 1 11.5-2.4 6 6 0 0 1 11.5 2.4C27.5 20.2 16 27 16 27Z"/>
                                <path d="M8.5 16h3l1.4-3.5 2.8 7 2.2-5 1.2 1.5h4.4"/>
                            </svg>
                        </span>
                        <span>HearThis</span>
                    </a>
                    <a href="https://www.mixcloud.com/deseoradio/" target="_blank" rel="noopener noreferrer" aria-label="Mixcloud · Deseo Radio">
                        <span class="mylive-episode-platform-icon" aria-hidden="true">
                            <svg width="31" height="31" style="display:block;width:31px!important;height:31px!important;max-width:31px!important;max-height:31px!important;flex:none!important" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                <path d="M3.5 20V12m4 11V9m4 15V7m4 17V11"/>
                                <path d="M19 23h6a4 4 0 0 0 .3-8 6 6 0 0 0-10.2-3"/>
                            </svg>
                        </span>
                        <span>Mixcloud</span>
                    </a>
                </div>
            </div>
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

    <?php if ($publicProfile): ?>
        <section class="public-profile-editor mylive-anchor-section" id="profile">
            <div class="public-profile-head">
                <div>
                    <span class="eyebrow">YOUR PUBLIC DJ PROFILE</span>
                    <h2>What listeners see.</h2>
                    <p>Το bio που έδωσες στην αίτησή σου εμφανίζεται ήδη εδώ και δεν χάνεται. Μπορείς να το κρατήσεις όπως είναι ή να το επεξεργαστείς. Για δημόσια παρουσίαση προτείνεται επαγγελματικό bio στα Αγγλικά. Η φωτογραφία και το show title έρχονται πάντα από το επίσημο Radio Program του Deseo.</p>
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

            <div class="public-profile-bio-guide">
                <div>
                    <span>PUBLIC BIO · ENGLISH RECOMMENDED</span>
                    <p><?= deseo_mylive_e(deseo_mylive_dj_bio_guidance_text()) ?></p>
                </div>
                <a href="<?= deseo_mylive_e(deseo_mylive_dj_bio_chatgpt_url()) ?>"
                   target="_blank"
                   rel="noopener noreferrer">
                    <?= deseo_mylive_e(deseo_mylive_dj_bio_chatgpt_cta()) ?>
                </a>
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
                    <span>BIO</span>
                    <textarea name="bio" maxlength="1600" rows="7" placeholder="Your bio from the Season 6 application will appear here…"><?= deseo_mylive_e((string)($publicProfile['draft_bio'] ?? '')) ?></textarea>
                    <small>Έως 1.600 χαρακτήρες · το bio της αίτησής σου διατηρείται και εμφανίζεται εδώ. Μπορείς προαιρετικά να το βελτιώσεις ή να το μετατρέψεις σε επαγγελματικό αγγλικό bio με το ChatGPT.</small>
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
                    ΠΡΟΤΕΙΝΕ ΜΙΑ ΕΠΙΧΕΙΡΗΣΗ
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

                <?php if ($isGuestAccount): ?>
                    <div class="mylive-settings-row">
                        <div>
                            <strong>Guest DJ notifications</strong>
                            <small>Το Guest access είναι one-off. Δεν δημιουργείται αυτόματα νέο show ή reminder κάθε εβδομάδα.</small>
                        </div>
                        <span>—</span>
                        <span>—</span>
                    </div>
                <?php else: ?>
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
                <?php endif; ?>

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
    var ownStatus = document.querySelector('[data-mylive-own-status]');
    var ownStatusLabel = ownStatus ? ownStatus.querySelector('[data-mylive-own-label]') : null;
    var ownStatusMain = ownStatus ? ownStatus.querySelector('[data-mylive-own-main]') : null;
    var ownStatusSlot = ownStatus ? ownStatus.querySelector('[data-mylive-own-slot]') : null;
    var ownAccountId = ownStatus ? Number(ownStatus.getAttribute('data-account-id') || 0) : 0;
    var ownAccountMode = ownStatus ? (ownStatus.getAttribute('data-account-mode') || 'resident') : 'resident';
    var ownWeeklySlot = ownStatus ? (ownStatus.getAttribute('data-weekly-slot') || '') : '';
    var ownDefaultLabel = ownStatusLabel && ownStatusLabel.textContent.trim() !== ''
        ? ownStatusLabel.textContent.trim()
        : (ownAccountMode === 'guest' ? 'GUEST DJ ACCESS' : 'YOUR WEEKLY SLOT');
    var refreshTimer = null;
    var refreshMinute = Math.floor(Date.now() / 60000);

    function programTime(value) {
        return value && typeof value === 'string' ? value.slice(0, 5) : '--:--';
    }

    function renderOwnLiveState(show) {
        if (!ownStatus) return;

        var isOwnLive = !!show
            && ownAccountId > 0
            && Number(show.mylive_account_id || 0) === ownAccountId;

        ownStatus.classList.toggle('is-live-now', isOwnLive);

        if (ownStatusLabel) {
            ownStatusLabel.textContent = isOwnLive ? 'ON AIR NOW' : ownDefaultLabel;
        }
        if (ownStatusMain) {
            ownStatusMain.textContent = isOwnLive ? 'Είσαι LIVE Τώρα!' : ownWeeklySlot;
        }
        if (ownStatusSlot) {
            ownStatusSlot.textContent = ownWeeklySlot;
            ownStatusSlot.hidden = !isOwnLive;
        }
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
            renderOwnLiveState(show);
            return;
        }

        renderOwnLiveState(null);
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
            refreshMinute = Math.floor(Date.now() / 60000);
        })
        .catch(function () {
            renderOwnLiveState(null);
            card.classList.remove('is-loading');
            label.textContent = 'LIVE BROADCAST';
            name.textContent = 'DESEO RADIO';
            time.textContent = 'Live status temporarily unavailable';
        });
    }

    function scheduleRefresh() {
        if (refreshTimer) window.clearTimeout(refreshTimer);
        var minute = 60 * 1000;
        var delay = minute - (Date.now() % minute) + 800;

        refreshTimer = window.setTimeout(function () {
            renderOwnLiveState(null);
    refreshOnAir().finally(scheduleRefresh);
        }, delay);
    }

    refreshOnAir().finally(scheduleRefresh);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'visible') return;
        var currentMinute = Math.floor(Date.now() / 60000);
        if (currentMinute !== refreshMinute) {
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
