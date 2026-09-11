<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

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
                header('Location: index.php');
                exit;
            }

            $_SESSION['login_attempts'] = $attempts + 1;
            $_SESSION['login_last_attempt'] = $now;
            $error = 'Λάθος κωδικός.';
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
    <title>Deseo CMS Login</title>
    <link rel="stylesheet" href="/iluma/admin.css?v=<?= @filemtime(__DIR__ . '/admin.css') ?: 1 ?>">
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
            <button class="button button-primary" type="submit">Enter CMS</button>
        </form>
        <div class="login-meta">Private management area · Deseo Radio / ILUMA</div>
    </section>
</div>
</body>
</html>
<?php
exit;
endif;

require_once __DIR__ . '/admin-ui.php';

$airplayCount = (int) $pdo->query("SELECT COUNT(*) FROM airplay")->fetchColumn();
$programCount = (int) $pdo->query("SELECT COUNT(*) FROM program")->fetchColumn();
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
</section>

<section class="quick-grid">
    <a class="quick-card" href="airplay.php"><small>Weekly rotation</small><h2>Airplay Top 10</h2><p>Ανανέωσε Spotify tracks, artwork και ranking.</p></a>
    <a class="quick-card" href="program.php"><small>Live schedule</small><h2>Radio Program</h2><p>Διαχειρίσου DJs, ημέρες, ώρες και φωτογραφίες.</p></a>
</section>
<?php admin_page_end(); ?>