<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Athens');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/iluma/connection.php';
require_once __DIR__ . '/includes/dj-season.php';

function deseo_e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dj_form_csrf_token(): string {
    if (empty($_SESSION['dj_form_csrf'])) {
        $_SESSION['dj_form_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['dj_form_csrf'];
}

function dj_form_verify_csrf(?string $token): bool {
    return is_string($token)
        && isset($_SESSION['dj_form_csrf'])
        && hash_equals((string)$_SESSION['dj_form_csrf'], $token);
}

function dj_form_length(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

dj_season_bootstrap($pdo);

$errors = [];
$success = isset($_GET['submitted']);
$old = [
    'full_name' => '',
    'artist_name' => '',
    'email' => '',
    'instagram' => '',
    'website' => '',
    'bio' => '',
    'set_type' => 'new',
    'tracklist' => '',
    'slot_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($old) as $key) {
        $old[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if (!dj_form_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Η συνεδρία της φόρμας έληξε. Ανανέωσε τη σελίδα και δοκίμασε ξανά.';
    }

    if (trim((string)($_POST['company'] ?? '')) !== '') {
        $errors[] = 'Δεν ήταν δυνατή η υποβολή της φόρμας.';
    }

    $lastSubmission = (int)($_SESSION['dj_last_submission'] ?? 0);
    if ($lastSubmission > 0 && (time() - $lastSubmission) < 60) {
        $errors[] = 'Έχει ήδη γίνει πρόσφατη υποβολή από αυτή τη συσκευή. Περίμενε λίγο πριν ξαναδοκιμάσεις.';
    }

    if ($old['full_name'] === '' || dj_form_length($old['full_name']) > 180) {
        $errors[] = 'Συμπλήρωσε σωστά το ονοματεπώνυμό σου.';
    }

    if ($old['artist_name'] === '' || dj_form_length($old['artist_name']) > 180) {
        $errors[] = 'Συμπλήρωσε το DJ / Artist Name.';
    }

    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || dj_form_length($old['email']) > 254) {
        $errors[] = 'Συμπλήρωσε ένα έγκυρο email.';
    }

    if ($old['bio'] === '' || dj_form_length($old['bio']) > 1000) {
        $errors[] = 'Το bio είναι υποχρεωτικό και πρέπει να είναι έως 1.000 χαρακτήρες.';
    }

    if ($old['tracklist'] !== '' && dj_form_length($old['tracklist']) > 6000) {
        $errors[] = 'Η tracklist είναι πολύ μεγάλη για τη φόρμα.';
    }

    $allowedSetTypes = ['new', 'previous', 'exclusive'];
    if (!in_array($old['set_type'], $allowedSetTypes, true)) {
        $errors[] = 'Επίλεξε έγκυρο τύπο DJ set.';
    }

    $instagram = dj_season_clean_url($old['instagram']);
    $website = dj_season_clean_url($old['website']);
    if ($old['instagram'] !== '' && $instagram === '') {
        $errors[] = 'Το Instagram / social link δεν είναι έγκυρο.';
    }
    if ($old['website'] !== '' && $website === '') {
        $errors[] = 'Το website / portfolio link δεν είναι έγκυρο.';
    }

    $slotId = filter_var($old['slot_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$slotId) {
        $errors[] = 'Επίλεξε ένα διαθέσιμο slot.';
    }

    foreach ([
        'age_confirmed' => 'Πρέπει να επιβεβαιώσεις ότι είσαι 18 ετών ή άνω.',
        'rights_confirmed' => 'Πρέπει να επιβεβαιώσεις τα δικαιώματα και την ευθύνη για το περιεχόμενο του set.',
        'ai_confirmed' => 'Πρέπει να αποδεχτείς την πολιτική απαγόρευσης AI-generated μουσικής.',
        'terms_accepted' => 'Πρέπει να αποδεχτείς τους Όρους Συμμετοχής.',
        'privacy_acknowledged' => 'Πρέπει να επιβεβαιώσεις ότι έλαβες γνώση της Πολιτικής Απορρήτου.',
    ] as $field => $message) {
        if (empty($_POST[$field])) {
            $errors[] = $message;
        }
    }

    $upload = $_FILES['photo'] ?? null;
    $photoMime = '';
    $photoExt = '';
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Η φωτογραφία είναι υποχρεωτική.';
    } elseif (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'Παρουσιάστηκε πρόβλημα κατά τη μεταφόρτωση της φωτογραφίας.';
    } elseif ((int)($upload['size'] ?? 0) > 5 * 1024 * 1024) {
        $errors[] = 'Η φωτογραφία δεν μπορεί να ξεπερνά τα 5 MB.';
    } elseif (!is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
        $errors[] = 'Η φωτογραφία δεν αναγνωρίστηκε ως έγκυρο upload.';
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $photoMime = (string)$finfo->file((string)$upload['tmp_name']);
        $allowedImages = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowedImages[$photoMime])) {
            $errors[] = 'Επιτρέπονται μόνο JPG, PNG ή WEBP φωτογραφίες.';
        } else {
            $photoExt = $allowedImages[$photoMime];
        }
    }

    if (!$errors && $slotId) {
        $storedPhoto = null;

        try {
            $pdo->beginTransaction();

            $slotStmt = $pdo->prepare(
                "SELECT id, season, day_of_week, start_time, end_time, is_active
                 FROM dj_season_slots
                 WHERE id = ? AND season = ?
                 FOR UPDATE"
            );
            $slotStmt->execute([$slotId, DESEO_DJ_SEASON]);
            $slot = $slotStmt->fetch(PDO::FETCH_ASSOC);

            if (!$slot || (int)$slot['is_active'] !== 1) {
                throw new RuntimeException('Το συγκεκριμένο slot δεν είναι πλέον διαθέσιμο.');
            }

            $bookedStmt = $pdo->prepare("SELECT id FROM dj_season_bookings WHERE slot_id = ? LIMIT 1");
            $bookedStmt->execute([$slotId]);
            if ($bookedStmt->fetchColumn()) {
                throw new RuntimeException('Το συγκεκριμένο slot μόλις δεσμεύτηκε. Επίλεξε άλλο διαθέσιμο slot.');
            }

            $uploadDir = __DIR__ . '/iluma/uploads/djs';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Δεν ήταν δυνατή η αποθήκευση της φωτογραφίας.');
            }

            $filename = 'season6-' . bin2hex(random_bytes(16)) . '.' . $photoExt;
            $absolutePhoto = $uploadDir . '/' . $filename;
            if (!move_uploaded_file((string)$upload['tmp_name'], $absolutePhoto)) {
                throw new RuntimeException('Δεν ήταν δυνατή η αποθήκευση της φωτογραφίας.');
            }

            $storedPhoto = '/iluma/uploads/djs/' . $filename;
            $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->format('Y-m-d H:i:s');
            $bookingToken = hash('sha256', random_bytes(32));

            $insert = $pdo->prepare(
                "INSERT INTO dj_season_bookings (
                    season, slot_id, full_name, artist_name, email, instagram, website,
                    bio, photo_path, set_type, tracklist, status,
                    rights_confirmed, ai_confirmed, age_confirmed,
                    terms_version, terms_accepted_at,
                    privacy_version, privacy_acknowledged_at, booking_token
                 ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending',
                    1, 1, 1, ?, ?, ?, ?, ?
                 )"
            );
            $insert->execute([
                DESEO_DJ_SEASON,
                $slotId,
                $old['full_name'],
                $old['artist_name'],
                strtolower($old['email']),
                $instagram,
                $website,
                $old['bio'],
                $storedPhoto,
                $old['set_type'],
                $old['tracklist'],
                DESEO_DJ_TERMS_VERSION,
                $now,
                DESEO_DJ_PRIVACY_VERSION,
                $now,
                $bookingToken,
            ]);

            $pdo->commit();
            $_SESSION['dj_last_submission'] = time();
            $_SESSION['dj_form_csrf'] = bin2hex(random_bytes(32));
            header('Location: /djs?submitted=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($storedPhoto) {
                $absolute = __DIR__ . $storedPhoto;
                if (is_file($absolute)) @unlink($absolute);
            }

            if ($e instanceof RuntimeException) {
                $errors[] = $e->getMessage();
            } elseif ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                $errors[] = 'Το συγκεκριμένο slot μόλις δεσμεύτηκε. Επίλεξε άλλο διαθέσιμο slot.';
            } else {
                error_log('Season 6 DJ booking failed: ' . $e->getMessage());
                $errors[] = 'Η υποβολή δεν ολοκληρώθηκε. Δοκίμασε ξανά ή επικοινώνησε στο radio@iluma.gr.';
            }
        }
    }
}

$slots = dj_season_slots($pdo);
$slotsByDay = [];
foreach ($slots as $slot) {
    $slotsByDay[(int)$slot['day_of_week']][] = $slot;
}

$meta_title = 'Deseo Radio Season 6 | DJ Sets';
$meta_desc = 'Join Deseo Radio Season 6. DJs and producers can select an available weekly slot and submit their profile for the new DJ Sets programme.';
$meta_canonical = 'https://deseoradio.com/djs';
$extra_styles = ['/assets/css/djs.css'];

require_once __DIR__ . '/includes/head-meta.php';
require_once __DIR__ . '/includes/header.php';
?>

<main id="main-content" class="dj-season-page">
    <section class="dj-season-hero">
        <div class="wide-shell dj-season-hero-grid">
            <div>
                <span class="kicker">DESEO RADIO · SEASON 6</span>
                <h1 class="metal-title">DJ Sets.<br>Choose your slot.</h1>
                <p class="dj-season-lead">
                    Η νέα σεζόν του Deseo Radio ανοίγει το εβδομαδιαίο πρόγραμμα σε επιλεγμένους DJs & producers.
                    Διάλεξε ένα πραγματικά διαθέσιμο slot, στείλε τα στοιχεία σου και γίνε μέρος της Season 6.
                </p>
                <div class="dj-season-pills" aria-label="Season 6 highlights">
                    <span>No fee / no payment</span>
                    <span>Weekly DJ Sets</span>
                    <span>No AI-generated music</span>
                </div>
            </div>

            <aside class="dj-season-intro-card">
                <span>HOW IT WORKS</span>
                <ol>
                    <li><strong>01</strong><p>Συμπλήρωσε DJ profile, φωτογραφία και bio.</p></li>
                    <li><strong>02</strong><p>Επίλεξε ημέρα & ώρα μόνο από τα διαθέσιμα slots.</p></li>
                    <li><strong>03</strong><p>Αποδέξου τους όρους και κατοχύρωσε τη θέση σου.</p></li>
                </ol>
                <p class="dj-season-small">Κατειλημμένα slots εμφανίζονται μόνο ως μη διαθέσιμα. Δεν δημοσιοποιούμε ποιος τα έχει κλείσει.</p>
            </aside>
        </div>
    </section>

    <section class="dj-season-content">
        <div class="wide-shell dj-season-layout">
            <div class="dj-season-info">
                <span class="kicker">THE FORMAT</span>
                <h2>Τι μπορείς να στείλεις</h2>
                <p>
                    Το set μπορεί να είναι νέο, παλαιότερο ή ειδικά δημιουργημένο για το Deseo Radio.
                    Δεν υπάρχει οικονομική αμοιβή. Η ILUMA Digital Agency θα δημιουργεί τα promotional assets
                    του DJ με ημέρα και ώρα μετάδοσης για social sharing και repost.
                </p>
                <p>
                    Θα χρειαστούμε τελικό DJ set και tracklist σύμφωνα με τις τεχνικές οδηγίες που θα σταλούν
                    μετά την επιβεβαίωση. Δεν επιτρέπονται τραγούδια ή recordings που έχουν δημιουργηθεί
                    εξ ολοκλήρου ή εν μέρει με generative AI.
                </p>

                <div class="dj-season-rule">
                    <span>AI POLICY</span>
                    <strong>Human-made music only.</strong>
                    <p>AI-assisted mastering/noise reduction δεν θεωρείται από μόνο του AI-generated track. Η απαγόρευση αφορά τη δημιουργία της μουσικής, των vocals, των στίχων ή του βασικού recording μέσω generative AI.</p>
                </div>
            </div>

            <div class="dj-season-form-card" id="apply">
                <div class="dj-season-form-head">
                    <div>
                        <span>SEASON 6 APPLICATION</span>
                        <h2>Κλείσε το slot σου</h2>
                    </div>
                    <strong>S6</strong>
                </div>

                <?php if ($success): ?>
                    <div class="dj-alert dj-alert-success">
                        <strong>Το slot σου καταχωρήθηκε.</strong>
                        <p>Λάβαμε τη συμμετοχή σου για τη Season 6. Η ομάδα ILUMA / Deseo θα χρησιμοποιήσει το email που δήλωσες για τα επόμενα βήματα και τις οδηγίες παράδοσης.</p>
                    </div>
                <?php endif; ?>

                <?php if ($errors): ?>
                    <div class="dj-alert dj-alert-error" role="alert">
                        <strong>Χρειάζεται μια διόρθωση.</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?><li><?= deseo_e($error) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="dj-application-form" id="dj-application-form">
                    <input type="hidden" name="csrf_token" value="<?= deseo_e(dj_form_csrf_token()) ?>">
                    <div class="dj-honeypot" aria-hidden="true">
                        <label>Company <input type="text" name="company" tabindex="-1" autocomplete="off"></label>
                    </div>

                    <fieldset>
                        <legend>01 · DJ PROFILE</legend>
                        <div class="dj-form-grid">
                            <label>
                                <span>Ονοματεπώνυμο *</span>
                                <input type="text" name="full_name" maxlength="180" value="<?= deseo_e($old['full_name']) ?>" autocomplete="name" required>
                            </label>
                            <label>
                                <span>DJ / Artist Name *</span>
                                <input type="text" name="artist_name" maxlength="180" value="<?= deseo_e($old['artist_name']) ?>" required>
                            </label>
                            <label>
                                <span>Email *</span>
                                <input type="email" name="email" maxlength="254" value="<?= deseo_e($old['email']) ?>" autocomplete="email" required>
                            </label>
                            <label>
                                <span>Instagram / Social</span>
                                <input type="text" name="instagram" maxlength="255" value="<?= deseo_e($old['instagram']) ?>" placeholder="instagram.com/...">
                            </label>
                            <label class="dj-field-full">
                                <span>Website / SoundCloud / Mixcloud</span>
                                <input type="text" name="website" maxlength="255" value="<?= deseo_e($old['website']) ?>" placeholder="https://...">
                            </label>
                            <label class="dj-field-full">
                                <span>Bio * <small><b id="bio-count">0</b>/1000</small></span>
                                <textarea name="bio" id="dj-bio" maxlength="1000" rows="6" required><?= deseo_e($old['bio']) ?></textarea>
                            </label>
                            <label class="dj-field-full dj-file-field">
                                <span>Φωτογραφία * <small>JPG / PNG / WEBP · έως 5 MB</small></span>
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>02 · DJ SET</legend>
                        <div class="dj-form-grid">
                            <label class="dj-field-full">
                                <span>Τύπος set *</span>
                                <select name="set_type" required>
                                    <option value="new" <?= $old['set_type'] === 'new' ? 'selected' : '' ?>>Νέο DJ set</option>
                                    <option value="previous" <?= $old['set_type'] === 'previous' ? 'selected' : '' ?>>Παλαιότερο / ήδη ηχογραφημένο set</option>
                                    <option value="exclusive" <?= $old['set_type'] === 'exclusive' ? 'selected' : '' ?>>Set ειδικά για το Deseo Radio</option>
                                </select>
                            </label>
                            <label class="dj-field-full">
                                <span>Tracklist <small>προαιρετικά τώρα · απαιτείται πριν τη μετάδοση</small></span>
                                <textarea name="tracklist" maxlength="6000" rows="5" placeholder="Artist — Track"><?= deseo_e($old['tracklist']) ?></textarea>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="dj-slot-fieldset">
                        <legend>03 · DAY & TIME</legend>
                        <p class="dj-field-note">Επίλεξε ένα διαθέσιμο slot. Τα slots της Season 6 είναι σταθερά: Πέμπτη & Παρασκευή 20:00–23:59, Σάββατο & Κυριακή 18:00–23:59. Κάθε slot είναι μίας ώρας και γίνεται μη διαθέσιμο μόνο όταν έχει ήδη δεσμευτεί.</p>

                        <div class="dj-slot-days">
                            <?php foreach ([4, 5, 6, 7] as $day): ?>
                                <section class="dj-slot-day">
                                    <h3><?= deseo_e(dj_season_day_label($day)) ?></h3>
                                    <div class="dj-slot-grid">
                                        <?php foreach ($slotsByDay[$day] ?? [] as $slot): ?>
                                            <?php $available = !empty($slot['available']); ?>
                                            <label class="dj-slot <?= $available ? '' : 'is-unavailable' ?>">
                                                <input
                                                    type="radio"
                                                    name="slot_id"
                                                    value="<?= (int)$slot['id'] ?>"
                                                    <?= ((string)$slot['id'] === $old['slot_id']) ? 'checked' : '' ?>
                                                    <?= $available ? '' : 'disabled' ?>
                                                    required>
                                                <span>
                                                    <strong><?= deseo_e(dj_season_format_time($slot['start_time'])) ?></strong>
                                                    <small><?= $available ? 'Διαθέσιμο' : 'Μη διαθέσιμο' ?></small>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <fieldset class="dj-terms-fieldset" id="dj-terms-fieldset" disabled>
                        <legend>04 · CONFIRMATION</legend>
                        <div class="dj-checks">
                            <label><input type="checkbox" name="age_confirmed" value="1" required><span>Δηλώνω ότι είμαι 18 ετών ή άνω και ότι τα στοιχεία που υποβάλλω είναι ακριβή.</span></label>
                            <label><input type="checkbox" name="rights_confirmed" value="1" required><span>Δηλώνω ότι έχω το δικαίωμα να παραδώσω το DJ set και ότι δεν θα συμπεριλάβω εν γνώσει μου παράνομο, leaked ή μη εξουσιοδοτημένο υλικό.</span></label>
                            <label><input type="checkbox" name="ai_confirmed" value="1" required><span>Επιβεβαιώνω ότι το set δεν θα περιλαμβάνει μουσικά έργα ή recordings δημιουργημένα εξ ολοκλήρου ή εν μέρει με generative AI.</span></label>
                            <label><input type="checkbox" name="terms_accepted" value="1" required><span>Έχω διαβάσει και αποδέχομαι τους <a href="/djterms" target="_blank" rel="noopener">Όρους Συμμετοχής & Συνεργασίας Season 6 ↗</a>.</span></label>
                            <label><input type="checkbox" name="privacy_acknowledged" value="1" required><span>Έχω λάβει γνώση της <a href="/privacy" target="_blank" rel="noopener">Πολιτικής Απορρήτου ↗</a> και της επεξεργασίας των στοιχείων μου για τη συμμετοχή.</span></label>
                        </div>
                    </fieldset>

                    <div class="dj-submit-row">
                        <p>Με την υποβολή δεσμεύεται το συγκεκριμένο slot. Δεν δημοσιεύεται το email ή το ονοματεπώνυμό σου.</p>
                        <button type="submit" class="button button-red">SUBMIT · SEASON 6</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>

<script>
(function () {
    'use strict';

    var form = document.getElementById('dj-application-form');
    var terms = document.getElementById('dj-terms-fieldset');
    var slots = form ? form.querySelectorAll('input[name="slot_id"]') : [];
    var bio = document.getElementById('dj-bio');
    var bioCount = document.getElementById('bio-count');

    function updateTerms() {
        if (!terms) return;
        var selected = form && form.querySelector('input[name="slot_id"]:checked');
        terms.disabled = !selected;
        terms.classList.toggle('is-ready', !!selected);
    }

    function updateBioCount() {
        if (bio && bioCount) bioCount.textContent = String(bio.value.length);
    }

    for (var i = 0; i < slots.length; i++) {
        slots[i].addEventListener('change', updateTerms);
    }

    if (bio) bio.addEventListener('input', updateBioCount);

    updateTerms();
    updateBioCount();
}());
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
