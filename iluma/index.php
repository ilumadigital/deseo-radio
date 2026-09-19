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
                    $email = strtolower(trim((string)($_POST['email'] ?? '')));
                    $password = (string)($_POST['password'] ?? '');
                    $account = filter_var($email, FILTER_VALIDATE_EMAIL)
                        ? admin_authenticate_credentials($email, $password)
                        : null;

                    if ($account) {
                        session_regenerate_id(true);
                        $_SESSION['iluma_user_email'] = (string)$account['email'];
                        $_SESSION['iluma_user_role'] = (string)$account['role'];
                        $_SESSION['login_attempts'] = 0;
                        $_SESSION['login_last_attempt'] = 0;
                        unset($_SESSION['iluma_admin']);
                        header('Location: index.php');
                        exit;
                    }

                    if ($email === DESEO_CMS_MANAGER_EMAIL && RADIO_MANAGER_PASSWORD === '') {
                        error_log('CMS manager login attempted but RADIO_MANAGER_PASSWORD is not configured.');
                    }

                    $_SESSION['login_attempts'] = $attempts + 1;
                    $_SESSION['login_last_attempt'] = $now;
                    $error = 'Το email ή το password δεν είναι σωστό.';
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
        <p>Σύνδεση στο ILUMA CMS με τον προσωπικό λογαριασμό σου.</p>
        <?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="login">
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" autocomplete="username" inputmode="email" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required>
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
require_once __DIR__ . '/../includes/audience-report.php';
dj_season_bootstrap($pdo);
deseo_mylive_bootstrap($pdo);
deseo_audience_bootstrap($pdo);

try {
    deseo_audience_maybe_send_monthly_report($pdo);
} catch (Throwable $reportError) {
    error_log('Audience monthly report overview fallback failed: ' . $reportError->getMessage());
}

$latestAudience = deseo_audience_latest_record($pdo);
$currentAudienceMonthLabel = $latestAudience
    ? deseo_audience_month_label((string)$latestAudience['month_key'])
    : deseo_audience_month_label();
$monthlyAudience = $latestAudience ? (int)$latestAudience['monthly_listeners'] : 0;

$airplayCount = (int) $pdo->query("SELECT COUNT(*) FROM airplay WHERE position BETWEEN 1 AND 6")->fetchColumn();
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
<div class="dashboard-home">
    <section class="dashboard-hero">
        <div class="dashboard-hero-copy">
            <div class="dashboard-kicker">DESEO STUDIO · SEASON 6</div>
            <h1>Content overview</h1>
            <p>Το κεντρικό control room για το πρόγραμμα, το Airplay, τους DJs και το MyLive του Deseo Radio.</p>

            <div class="dashboard-hero-meta">
                <span><b><?= $todayCount ?></b> shows today</span>
                <span><b><?= $airplayCount ?>/6</b> airplay slots</span>
                <span><b><?= $myLiveAccountCount ?></b> active MyLive</span>
            </div>
        </div>

        <?php if (admin_can_access('audience')): ?>
            <a class="dashboard-audience-card" href="audience.php">
                <div class="dashboard-audience-top">
                    <span>MONTHLY AUDIENCE</span>
                    <b>OPEN ↗</b>
                </div>
                <strong><?= $monthlyAudience > 0 ? admin_e(deseo_audience_format($monthlyAudience)) : '—' ?></strong>
                <p>Listeners · <?= admin_e($currentAudienceMonthLabel) ?></p>
                <div class="dashboard-audience-line"></div>
                <small>Audience stats & estimated DJ reach</small>
            </a>
        <?php else: ?>
            <div class="dashboard-audience-card is-readonly" aria-label="Monthly audience">
                <div class="dashboard-audience-top">
                    <span>MONTHLY AUDIENCE</span>
                </div>
                <strong><?= $monthlyAudience > 0 ? admin_e(deseo_audience_format($monthlyAudience)) : '—' ?></strong>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-metrics" aria-label="CMS metrics">
        <a href="airplay.php" class="dashboard-metric">
            <span>01 · AIRPLAY</span>
            <strong><?= $airplayCount ?><em>/6</em></strong>
            <small>weekly rotation</small>
        </a>
        <a href="program.php" class="dashboard-metric">
            <span>02 · PROGRAM</span>
            <strong><?= $programCount ?></strong>
            <small>total slots</small>
        </a>
        <a href="program.php" class="dashboard-metric">
            <span>03 · TODAY</span>
            <strong><?= $todayCount ?></strong>
            <small>shows on air today</small>
        </a>
        <a href="playlists.php" class="dashboard-metric">
            <span>04 · PLAYLISTS</span>
            <strong><?= $playlistCount ?></strong>
            <small>Spotify collections</small>
        </a>
        <a href="dj-season.php" class="dashboard-metric">
            <span>05 · SEASON 6</span>
            <strong><?= $djApplicationCount ?></strong>
            <small>DJ applications</small>
        </a>
        <a href="mylive.php" class="dashboard-metric">
            <span>06 · MYLIVE</span>
            <strong><?= $myLiveAccountCount ?></strong>
            <small>active accounts</small>
        </a>
    </section>

    <div class="dashboard-section-head">
        <div>
            <span>WORKSPACES</span>
            <h2>Manage Deseo Radio</h2>
        </div>
        <p>Όλα τα βασικά εργαλεία του σταθμού, οργανωμένα ανά λειτουργία.</p>
    </div>

    <section class="dashboard-workspaces">
        <article class="dashboard-workspace">
            <div class="dashboard-workspace-head">
                <div>
                    <span>ON AIR & CONTENT</span>
                    <h3>Broadcast control</h3>
                </div>
                <b>01</b>
            </div>

            <a class="dashboard-workspace-row is-featured" href="airplay.php">
                <div>
                    <span>Weekly rotation · max 6</span>
                    <strong>Airplay</strong>
                    <small>Spotify tracks, artwork και σειρά εμφάνισης.</small>
                </div>
                <b><?= $airplayCount ?>/6</b>
                <i>↗</i>
            </a>

            <a class="dashboard-workspace-row" href="program.php">
                <div>
                    <span>Live schedule</span>
                    <strong>Radio Program</strong>
                    <small>DJs, ημέρες, ώρες και φωτογραφίες.</small>
                </div>
                <b><?= $programCount ?></b>
                <i>↗</i>
            </a>

            <a class="dashboard-workspace-row" href="playlists.php">
                <div>
                    <span>Spotify curation</span>
                    <strong>Playlists</strong>
                    <small>Collections, covers και σειρά εμφάνισης.</small>
                </div>
                <b><?= $playlistCount ?></b>
                <i>↗</i>
            </a>
        </article>

        <article class="dashboard-workspace">
            <div class="dashboard-workspace-head">
                <div>
                    <span>DJS & SEASON 6</span>
                    <h3>Artist management</h3>
                </div>
                <b>02</b>
            </div>

            <a class="dashboard-workspace-row" href="dj-season.php">
                <div>
                    <span>Season 6 onboarding</span>
                    <strong>DJ Applications</strong>
                    <small>Submissions, slots, bios και approvals.</small>
                </div>
                <b><?= $djApplicationCount ?></b>
                <i>↗</i>
            </a>

            <a class="dashboard-workspace-row is-featured" href="mylive.php">
                <div>
                    <span>DJ workspace</span>
                    <strong>MyLive</strong>
                    <small>Accounts, DJ Sets, assets και onboarding.</small>
                </div>
                <b><?= $myLiveAccountCount ?></b>
                <i>↗</i>
            </a>

            <?php if (admin_can_access('audience')): ?>
                <a class="dashboard-workspace-row" href="audience.php">
                    <div>
                        <span>Performance</span>
                        <strong>Audience</strong>
                        <small>Monthly listeners και estimated DJ reach.</small>
                    </div>
                    <b>↗</b>
                    <i>↗</i>
                </a>
            <?php endif; ?>
        </article>
    </section>
</div>
<?php admin_page_end(); ?>