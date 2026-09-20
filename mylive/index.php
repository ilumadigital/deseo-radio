<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/audience.php';
require_once __DIR__ . '/../includes/turnstile.php';

deseo_mylive_session_start();
deseo_mylive_bootstrap($pdo);
deseo_audience_bootstrap($pdo);

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

            header('Location: /mylive/');
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

        if ($action === 'save_public_profile' || $action === 'publish_public_profile') {
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
                $notice = 'Το Public Profile δημοσιεύτηκε. Θα εμφανίζεται στο site όταν το show σου είναι συνδεδεμένο με το MyLive profile.';
            } else {
                $notice = 'Το draft του Public Profile αποθηκεύτηκε. Οι αλλαγές δεν είναι ακόμη δημόσιες.';
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
    <title>MyLive · Deseo Radio</title>
    <link rel="icon" href="/assets/img/favicon.png">
    <link rel="stylesheet" href="/mylive/style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
    <script src="/assets/js/deseo-lockdown.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-lockdown.js') ?: 1 ?>"></script>
    <?php if ($turnstileConfigured): ?>
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

    <section class="login-card">
        <div class="login-card-head">
            <span>MYLIVE</span>
            <h2>Καλώς ήρθες.</h2>
            <p>Μπες με τα στοιχεία πρόσβασης που έλαβες από το Deseo Radio.</p>
        </div>

        <?php if ($error): ?><div class="alert error"><?= deseo_mylive_e($error) ?></div><?php endif; ?>

        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= deseo_mylive_e(deseo_mylive_csrf()) ?>">
            <input type="hidden" name="action" value="login">

            <label>
                <span>Email</span>
                <input type="email" name="email" autocomplete="username" inputmode="email" required autofocus>
            </label>

            <label>
                <span>Password</span>
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
    <link rel="stylesheet" href="/mylive/style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
    <script src="/assets/js/deseo-lockdown.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-lockdown.js') ?: 1 ?>"></script>
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

$publicProfile = !empty($account['public_profile_enabled'])
    ? deseo_mylive_public_profile($pdo, (int)$account['id'])
    : null;
$publicProfileHasChanges = $publicProfile
    ? deseo_mylive_public_profile_has_unpublished_changes($publicProfile)
    : false;

$sets = deseo_mylive_sets($pdo, (int)$account['id']);
$assets = deseo_mylive_assets($pdo, (int)$account['id']);
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
    <link rel="stylesheet" href="/mylive/style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
    <script src="/assets/js/deseo-lockdown.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-lockdown.js') ?: 1 ?>"></script>
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
                        <i>02</i><span>Public Profile</span>
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

                <div class="public-profile-status <?= !empty($publicProfile['is_published']) ? 'is-published' : 'is-draft' ?>">
                    <span><?= !empty($publicProfile['is_published']) ? 'PUBLISHED' : 'DRAFT ONLY' ?></span>
                    <?php if (!empty($publicProfile['is_published'])): ?>
                        <small><?= !empty($publicProfile['published_at']) ? deseo_mylive_e(date('d.m.Y · H:i', strtotime((string)$publicProfile['published_at']))) : 'Live' ?></small>
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
                        <button class="primary-button" type="submit" name="action" value="publish_public_profile">Publish Profile</button>
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
                <p>Άκου live τον σταθμό και δες το σημερινό πρόγραμμα όπως ακριβώς εμφανίζεται στο Deseo Radio.</p>
            </div>
            <span class="mylive-live-state"><i></i> LIVE 24/7</span>
        </div>

        <div class="mylive-station-grid">
            <article class="mylive-player-deck">
                <div class="mylive-player-frame">
                    <iframe src="https://play.iradios.gr/widget/deseo-radio"
                            width="100%"
                            frameborder="0"
                            allow="autoplay; encrypted-media; clipboard-write;"
                            style="border:none; width:100%; max-width:600px; aspect-ratio:1 / 1; margin:0 auto; display:block; box-shadow:0 20px 40px rgba(0,0,0,0.5); border-radius:32px; overflow:hidden;"></iframe>
                </div>
            </article>

            <article class="deseo-panel mylive-program-panel" id="mylive-program">
                <header class="deseo-panel-header">
                    <h3>PROGRAM</h3>
                    <span>DESEO RADIO</span>
                </header>

                <div class="deseo-panel-body" id="mylive-program-body" aria-live="polite">
                    <div class="deseo-panel-empty">Loading today’s program…</div>
                </div>
            </article>
        </div>
    </section>

    <section class="mylive-referral-section mylive-anchor-section" id="rewards">
        <div class="mylive-referral-copy">
            <span class="mylive-referral-kicker"><i></i> DJ PARTNER REWARD</span>
            <h2>Φέρε το brand.<br>Κράτα το 20%.</h2>
        </div>

        <div class="mylive-referral-action">
            <p>Ξέρεις μια επιχείρηση που θέλει να ακουστεί στο Deseo Radio; Σύστησέ τη στην ILUMA και κέρδισε <strong>20%</strong> από κάθε νέα διαφημιστική καμπάνια που κλείνει μέσω της δικής σου σύστασης.</p>

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

    var body = document.getElementById('mylive-program-body');
    if (!body) return;

    var refreshTimer = null;
    var refreshHour = Math.floor(Date.now() / 3600000);

    function programTime(value) {
        return value && typeof value === 'string' ? value.slice(0, 5) : '--:--';
    }

    function createImage(show) {
        var image = document.createElement('img');
        image.className = 'deseo-row-cover';
        image.src = show.photo_path || '/assets/img/bg.png';
        image.alt = show.dj_name || 'Deseo Radio';
        image.addEventListener('error', function () {
            if (this.getAttribute('src') !== '/assets/img/bg.png') {
                this.setAttribute('src', '/assets/img/bg.png');
            }
        });
        return image;
    }

    function renderProgram(today, nextShow) {
        body.textContent = '';

        if (!Array.isArray(today) || !today.length) {
            var empty = document.createElement('div');
            empty.className = 'deseo-panel-empty';
            empty.textContent = 'Δεν υπάρχει καταχωρημένο πρόγραμμα για σήμερα.';
            body.appendChild(empty);
            return;
        }

        today.forEach(function (show) {
            var row = document.createElement('div');
            row.className = 'deseo-panel-row deseo-program-row' + (show.is_live ? ' is-live' : '');

            row.appendChild(createImage(show));

            var copy = document.createElement('span');
            copy.className = 'deseo-row-copy';

            var time = document.createElement('small');
            time.textContent = programTime(show.start_time) + ' — ' + programTime(show.end_time);

            var name = document.createElement('strong');
            name.textContent = show.dj_name || 'Deseo Radio';

            copy.appendChild(time);
            copy.appendChild(name);
            row.appendChild(copy);

            if (show.is_live) {
                var live = document.createElement('span');
                live.className = 'deseo-live-tag';
                live.textContent = 'LIVE';
                row.appendChild(live);
            } else if (show.profile) {
                var profile = document.createElement('span');
                profile.className = 'deseo-profile-tag';
                profile.textContent = 'PROFILE';
                row.appendChild(profile);
            }

            body.appendChild(row);
        });

        if (nextShow) {
            var next = document.createElement('div');
            next.className = 'deseo-next-pill';

            var label = document.createElement('span');
            label.textContent = 'Next:';

            var name = document.createElement('strong');
            name.textContent = nextShow.dj_name || 'Deseo Radio';

            var time = document.createElement('small');
            time.textContent = '· ' + programTime(nextShow.start_time);

            next.appendChild(label);
            next.appendChild(name);
            next.appendChild(time);
            body.appendChild(next);
        }
    }

    function refreshProgram() {
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
            if (!payload || !Array.isArray(payload.today)) return;
            renderProgram(payload.today, payload.next || null);
            refreshHour = Math.floor(Date.now() / 3600000);
        })
        .catch(function () {
            if (!body.children.length || body.querySelector('.deseo-panel-empty')) {
                body.innerHTML = '<div class="deseo-panel-empty">Το πρόγραμμα δεν είναι διαθέσιμο αυτή τη στιγμή.</div>';
            }
        });
    }

    function scheduleRefresh() {
        if (refreshTimer) window.clearTimeout(refreshTimer);
        var hour = 60 * 60 * 1000;
        var delay = hour - (Date.now() % hour) + 1200;

        refreshTimer = window.setTimeout(function () {
            refreshProgram().finally(scheduleRefresh);
        }, delay);
    }

    refreshProgram().finally(scheduleRefresh);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'visible') return;
        var currentHour = Math.floor(Date.now() / 3600000);
        if (currentHour !== refreshHour) {
            refreshProgram().finally(scheduleRefresh);
        }
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) refreshProgram().finally(scheduleRefresh);
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

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(done).catch(function () {
                    window.prompt('Copy your referral link:', url);
                });
            } else {
                window.prompt('Copy your referral link:', url);
            }
        });
    });
}());
</script>
</body>
</html>
