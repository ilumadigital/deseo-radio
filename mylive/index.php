<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/turnstile.php';

deseo_mylive_session_start();
deseo_mylive_bootstrap($pdo);

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    header('Cache-Control: no-store, private');
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
                "SELECT a.id, a.password_hash
                 FROM dj_portal_accounts a
                 INNER JOIN dj_season_bookings b ON b.id = a.booking_id
                 WHERE LOWER(a.email) = ? AND a.is_active = 1 AND b.season = ?
                 LIMIT 1"
            );
            $stmt->execute([$email, DESEO_DJ_SEASON]);
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

            $update = $pdo->prepare("UPDATE dj_portal_accounts SET last_login_at = NOW() WHERE id = ?");
            $update->execute([(int)$account['id']]);

            header('Location: /mylive/');
            exit;
        }

        if ($action === 'upload') {
            if (!deseo_mylive_logged_in()) {
                throw new RuntimeException('Η συνεδρία σου έχει λήξει. Κάνε ξανά login.');
            }

            $accountId = deseo_mylive_account_id();
            $account = deseo_mylive_account($pdo, $accountId);
            if (!$account) {
                throw new RuntimeException('Το account δεν είναι πλέον ενεργό.');
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
                    UPLOAD_ERR_NO_FILE => 'Δεν επιλέχθηκε αρχείο.',
                ];
                throw new RuntimeException($uploadMessages[$uploadError] ?? 'Το upload δεν ολοκληρώθηκε.');
            }

            $size = (int)($file['size'] ?? 0);
            if ($size < 1024 || $size > DESEO_MYLive_MAX_BYTES) {
                throw new RuntimeException('Το αρχείο πρέπει να είναι μικρότερο από 1 GB.');
            }

            $originalName = basename((string)($file['name'] ?? ''));
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['mp3', 'wav'], true)) {
                throw new RuntimeException('Για το MyLive δεχόμαστε μόνο MP3 ή WAV.');
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

            $allowedMime = $extension === 'mp3'
                ? ['audio/mpeg', 'audio/mp3', 'application/octet-stream']
                : ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave', 'application/octet-stream'];

            if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
                throw new RuntimeException('Το αρχείο δεν φαίνεται να είναι έγκυρο ' . strtoupper($extension) . '.');
            }

            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT id FROM dj_portal_accounts WHERE id = ? FOR UPDATE");
            $lock->execute([$accountId]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Το account δεν βρέθηκε.');
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
                    $mime,
                ]);
                $pdo->commit();
            } catch (Throwable $dbError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                @unlink($absolutePath);
                throw $dbError;
            }

            $message = sprintf('Το EP%03d ανέβηκε επιτυχώς.', $episode);
            if ($isAjax) {
                mylive_json(true, $message, ['episode' => $episode, 'filename' => $storedName]);
            }
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
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>MyLive · Deseo Radio</title>
    <link rel="icon" href="/assets/img/favicon.png">
    <link rel="stylesheet" href="/mylive/style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
    <?php if ($turnstileConfigured): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body class="mylive-login-page">
<main class="login-shell">
    <section class="login-brand">
        <img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio">
        <span>SEASON 6 · DJ ACCESS</span>
        <h1>Your sets.<br>One place.</h1>
        <p>Το προσωπικό σου σημείο για να παραδίδεις τα DJ Sets σου στο Deseo Radio.</p>
    </section>

    <section class="login-card">
        <div class="login-card-head">
            <span>MY LIVE</span>
            <h2>Καλώς ήρθες.</h2>
            <p>Μπες με τα στοιχεία που σου έστειλε το Deseo Radio.</p>
        </div>

        <?php if ($error): ?><div class="alert error"><?= deseo_mylive_e($error) ?></div><?php endif; ?>

        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= deseo_mylive_e(deseo_mylive_csrf()) ?>">
            <input type="hidden" name="action" value="login">

            <label>
                <span>Email</span>
                <input type="email" name="email" autocomplete="email" required autofocus>
            </label>

            <label>
                <span>Password</span>
                <input type="password" name="password" autocomplete="current-password" required>
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

        <small>Private DJ delivery area · Deseo Radio / ILUMA</small>
    </section>
</main>
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
$sets = deseo_mylive_sets($pdo, (int)$account['id']);
$nextEpisode = deseo_mylive_next_episode($pdo, (int)$account['id']);
$dayLabel = dj_season_day_label((int)$account['day_of_week']);
$startTime = dj_season_format_time((string)$account['start_time']);
?>
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#070708">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>MyLive · <?= deseo_mylive_e($account['artist_name']) ?> · Deseo Radio</title>
    <link rel="icon" href="/assets/img/favicon.png">
    <link rel="stylesheet" href="/mylive/style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: 1 ?>">
</head>
<body class="mylive-dashboard-page">
<header class="portal-header">
    <a href="/mylive/" class="portal-logo"><img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio"></a>
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

<main class="portal-shell">
    <section class="portal-intro">
        <div>
            <span class="eyebrow">DESEO RADIO · MY LIVE</span>
            <h1>Τα DJ Sets σου.</h1>
        </div>
        <p>Ανέβασε το επόμενο επεισόδιο και εμείς αναλαμβάνουμε το σωστό filename, την αρίθμηση και την αποθήκευση.</p>
    </section>

    <?php if ($notice): ?><div class="alert success"><?= deseo_mylive_e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= deseo_mylive_e($error) ?></div><?php endif; ?>

    <section class="upload-card">
        <div class="upload-copy">
            <span>NEXT DELIVERY</span>
            <h2>EP<?= str_pad((string)$nextEpisode, 3, '0', STR_PAD_LEFT) ?></h2>
            <p>MP3 ή WAV · έως 1 GB</p>
        </div>

        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= deseo_mylive_e(deseo_mylive_csrf()) ?>">
            <input type="hidden" name="action" value="upload">
            <input id="setFile" type="file" name="dj_set" accept=".mp3,.wav,audio/mpeg,audio/wav" hidden required>

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

    <section class="repository">
        <div class="repository-head">
            <div>
                <span class="eyebrow">YOUR REPOSITORY</span>
                <h2>Episodes</h2>
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
                        </div>
                        <div class="set-status">
                            <span><?= deseo_mylive_e(strtoupper((string)$set['status'])) ?></span>
                            <a href="/mylive/download.php?id=<?= (int)$set['id'] ?>">Download</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

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

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/mylive/', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.onprogress = event => {
            if (!event.lengthComputable) return;
            bar.style.width = Math.round((event.loaded / event.total) * 100) + '%';
        };

        xhr.onload = () => {
            let result = null;
            try { result = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status >= 200 && xhr.status < 300 && result && result.ok) {
                bar.style.width = '100%';
                showToast(result.message || 'Το set ανέβηκε.');
                setTimeout(() => window.location.reload(), 700);
                return;
            }
            button.disabled = false;
            button.textContent = 'Try again';
            showToast((result && result.message) || 'Το upload δεν ολοκληρώθηκε.', true);
        };

        xhr.onerror = () => {
            button.disabled = false;
            button.textContent = 'Try again';
            showToast('Η σύνδεση διακόπηκε κατά το upload.', true);
        };

        xhr.send(new FormData(form));
    });
})();
</script>
</body>
</html>
