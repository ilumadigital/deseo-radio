<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/turnstile.php';

$turnstileSiteKey = deseo_turnstile_site_key();
$turnstileConfigured = deseo_turnstile_configured();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Η συνεδρία έληξε. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.';
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    } elseif ($action === 'login' && !admin_is_logged_in()) {
        if (!$turnstileConfigured) {
            $error = 'Η επαλήθευση ασφαλείας δεν είναι διαθέσιμη αυτή τη στιγμή.';
            error_log('CMS login Turnstile is not configured.');
        } else {
            $turnstileToken = trim((string)($_POST['cf-turnstile-response'] ?? ''));
            $remoteIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
            $turnstileResult = deseo_turnstile_validate($turnstileToken, $remoteIp, 'cms_login');

            if (empty($turnstileResult['success'])) {
                $error = 'Η επαλήθευση ασφαλείας απέτυχε. Ολοκληρώστε ξανά το Cloudflare check.';
                error_log('CMS login Turnstile rejected request: ' . json_encode($turnstileResult['error-codes'] ?? []));
            } else {
                $now = time();
                $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
                $lastAttempt = (int) ($_SESSION['login_last_attempt'] ?? 0);

                if ($attempts >= 5 && ($now - $lastAttempt) < 300) {
                    $error = 'Πολλές αποτυχημένες προσπάθειες. Δοκιμάστε ξανά σε λίγα λεπτά.';
                } else {
                    $password = (string) ($_POST['password'] ?? '');
                    if (hash_equals(ADMIN_PASSWORD, $password)) {
                        session_regenerate_id(true);
                        $_SESSION['iluma_admin'] = true;
                        $_SESSION['login_attempts'] = 0;
                        $_SESSION['login_last_attempt'] = 0;
                        header('Location: index.php');
                        exit;
                    }

                    $_SESSION['login_attempts'] = $attempts + 1;
                    $_SESSION['login_last_attempt'] = $now;
                    $error = 'Λάθος κωδικός.';
                }
            }
        }
    }
}

if (!admin_is_logged_in()):
?>
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090909">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex,notranslate">
    <meta name="googlebot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <meta name="bingbot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <title>Deseo CMS Login</title>
    <link rel="stylesheet" href="/iluma/admin.css?v=<?= @filemtime(__DIR__ . '/admin.css') ?: 1 ?>">
    <?php if ($turnstileConfigured): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body>
<div class="login-wrap">
    <section class="login-card">
        <img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio">
        <h1>Studio access</h1>
        <p>Διαχείριση προγράμματος και εβδομαδιαίου Airplay.</p>
        <?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="login">
            <div class="field">
                <label for="password">Master password</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required autofocus>
            </div>

            <?php if ($turnstileConfigured): ?>
                <div class="login-turnstile">
                    <div class="cf-turnstile"
                         data-sitekey="<?= admin_e($turnstileSiteKey) ?>"
                         data-theme="dark"
                         data-size="flexible"
                         data-action="cms_login"></div>
                </div>
            <?php else: ?>
                <div class="notice notice-error">Η επαλήθευση Cloudflare δεν είναι ρυθμισμένη. Η είσοδος παραμένει κλειδωμένη.</div>
            <?php endif; ?>

            <button class="button button-primary" type="submit" <?= $turnstileConfigured ? '' : 'disabled' ?>>Enter CMS</button>
        </form>
        <div class="login-meta">Private management area · Deseo Radio / ILUMA Digital Agency</div>
    </section>
</div>
</body>
</html>
<?php
exit;
endif;

require_once __DIR__ . '/admin-ui.php';
require_once __DIR__ . '/../includes/dj-season.php';
require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/audience.php';
dj_season_bootstrap($pdo);
deseo_mylive_bootstrap($pdo);
deseo_audience_bootstrap($pdo);

$currentAudienceMonthLabel = deseo_audience_month_label();
$monthlyAudience = deseo_audience_monthly_listeners($pdo);

$airplayCount = (int) $pdo->query("SELECT COUNT(*) FROM airplay")->fetchColumn();
$programCount = (int) $pdo->query("SELECT COUNT(*) FROM program")->fetchColumn();
$playlistCount = 0;
try {
    $playlistTable = $pdo->query("SHOW TABLES LIKE 'playlists'")->fetchColumn();
    if ($playlistTable) {
        $playlistCount = (int)$pdo->query("SELECT COUNT(*) FROM playlists")->fetchColumn();
    }
} catch (Throwable $playlistCountError) {
    error_log('Playlist count unavailable: ' . $playlistCountError->getMessage());
}
$myLiveAccountCount = 0;
try {
    $myLiveAccountCount = (int)$pdo->query("SELECT COUNT(*) FROM dj_portal_accounts WHERE is_active = 1")->fetchColumn();
} catch (Throwable $myLiveCountError) {
    error_log('MyLive account count unavailable: ' . $myLiveCountError->getMessage());
}

$djApplicationCount = 0;
try {
    $djStmt = $pdo->prepare("SELECT COUNT(*) FROM dj_season_bookings WHERE season = ?");
    $djStmt->execute([DESEO_DJ_SEASON]);
    $djApplicationCount = (int)$djStmt->fetchColumn();
} catch (Throwable $djCountError) {
    error_log('DJ application count unavailable: ' . $djCountError->getMessage());
}

$today = (int) date('N');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM program WHERE day_of_week = ?");
$stmt->execute([$today]);
$todayCount = (int) $stmt->fetchColumn();

admin_page_start('Overview', 'dashboard');
?>
<div class="page-heading">
    <div><span>Deseo Studio</span><h1>Content overview</h1><p>Το κεντρικό σημείο ελέγχου για όσα εμφανίζονται στο Deseo Radio.</p></div>
</div>

<section class="stats">
    <div class="stat"><strong><?= $airplayCount ?></strong><span>Airplay tracks</span></div>
    <div class="stat"><strong><?= $programCount ?></strong><span>Program slots</span></div>
    <div class="stat"><strong><?= $todayCount ?></strong><span>Shows today</span></div>
    <div class="stat"><strong><?= $playlistCount ?></strong><span>Playlists</span></div>
    <div class="stat"><strong><?= $djApplicationCount ?></strong><span>Season 6 DJs</span></div>
    <div class="stat"><strong><?= $myLiveAccountCount ?></strong><span>MyLive accounts</span></div>
    <div class="stat"><strong><?= $monthlyAudience > 0 ? admin_e(deseo_audience_format($monthlyAudience)) : '—' ?></strong><span>Listeners · <?= admin_e($currentAudienceMonthLabel) ?></span></div>
</section>

<section class="quick-grid">
    <a class="quick-card" href="airplay.php"><small>Weekly rotation</small><h2>Airplay Top 10</h2><p>Ανανέωσε Spotify tracks, artwork και ranking.</p></a>
    <a class="quick-card" href="program.php"><small>Live schedule</small><h2>Radio Program</h2><p>Διαχειρίσου DJs, ημέρες, ώρες και φωτογραφίες.</p></a>
    <a class="quick-card" href="dj-season.php"><small>Season 6 onboarding</small><h2>DJ Applications</h2><p>Δες submissions, slots, φωτογραφίες, bios και acceptance records.</p></a>
    <a class="quick-card" href="mylive.php"><small>DJ delivery workspace</small><h2>MyLive</h2><p>Accounts, onboarding, DJ Sets, artwork και branded imaging.</p></a>
    <a class="quick-card" href="audience.php"><small>Estimated DJ reach</small><h2>Audience</h2><p>Όρισε monthly listeners και έλεγξε την εκτιμώμενη απήχηση ανά MyLive slot.</p></a>
    <a class="quick-card" href="playlists.php"><small>Spotify curation</small><h2>Playlists</h2><p>Πρόσθεσε Spotify playlists, covers και σειρά εμφάνισης.</p></a>
</section>
<?php admin_page_end(); ?>