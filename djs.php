<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Athens');

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
}

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
require_once __DIR__ . '/includes/turnstile.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/mylive-email-reminders.php';
deseo_mylive_maybe_run_email_scheduler($pdo);

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

$turnstileSiteKey = deseo_turnstile_site_key();
$turnstileConfigured = deseo_turnstile_configured();

$errors = [];
$success = isset($_GET['submitted']);
$confirmationMailFailed = $success && (string)($_GET['mail'] ?? '') === '0';
$old = [
    'full_name' => '',
    'artist_name' => '',
    'email' => '',
    'instagram' => '',
    'website' => '',
    'bio' => '',
    'set_type' => 'new',
    'work_sample_url' => '',
    'slot_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($old) as $key) {
        $old[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if (!dj_form_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = deseo_t('dj.error.session');
    }

    if (trim((string)($_POST['company'] ?? '')) !== '') {
        $errors[] = deseo_t('dj.error.submit');
    }

    $lastSubmission = (int)($_SESSION['dj_last_submission'] ?? 0);
    if ($lastSubmission > 0 && (time() - $lastSubmission) < 60) {
        $errors[] = deseo_t('dj.error.rate_limit');
    }

    if (!$turnstileConfigured) {
        $errors[] = deseo_t('dj.error.security_unconfigured');
        error_log('Season 6 Turnstile is not configured. Set TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY.');
    } else {
        $turnstileToken = trim((string)($_POST['cf-turnstile-response'] ?? ''));
        $remoteIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
        $turnstileResult = deseo_turnstile_validate($turnstileToken, $remoteIp, 'dj_inquiry');

        if (empty($turnstileResult['success'])) {
            $errors[] = deseo_t('dj.error.security_failed');
            $codes = $turnstileResult['error-codes'] ?? [];
            error_log('Season 6 Turnstile rejected submission: ' . json_encode($codes));
        }
    }

    if ($old['full_name'] === '' || dj_form_length($old['full_name']) > 180) {
        $errors[] = deseo_t('dj.error.full_name');
    }

    if ($old['artist_name'] === '' || dj_form_length($old['artist_name']) > 180) {
        $errors[] = deseo_t('dj.error.artist_name');
    }

    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || dj_form_length($old['email']) > 254) {
        $errors[] = deseo_t('dj.error.email');
    }

    if ($old['bio'] === '' || dj_form_length($old['bio']) > 1000) {
        $errors[] = deseo_t('dj.error.bio');
    }

    $allowedSetTypes = ['new', 'previous', 'exclusive'];
    if (!in_array($old['set_type'], $allowedSetTypes, true)) {
        $errors[] = deseo_t('dj.error.set_type');
    }

    $instagram = dj_season_clean_url($old['instagram']);
    $website = dj_season_clean_url($old['website']);
    $workSampleUrl = dj_season_clean_url($old['work_sample_url']);
    if ($old['instagram'] !== '' && $instagram === '') {
        $errors[] = deseo_t('dj.error.instagram');
    }
    if ($old['website'] !== '' && $website === '') {
        $errors[] = deseo_t('dj.error.website');
    }
    if ($old['work_sample_url'] === '' || $workSampleUrl === '') {
        $errors[] = deseo_t('dj.error.sample_required');
    } elseif (dj_form_length($workSampleUrl) > 500) {
        $errors[] = deseo_t('dj.error.sample_too_long');
    }

    $slotId = filter_var($old['slot_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$slotId) {
        $errors[] = deseo_t('dj.error.slot_required');
    }

    foreach ([
        'age_confirmed' => deseo_t('dj.error.age'),
        'rights_confirmed' => deseo_t('dj.error.rights'),
        'ai_confirmed' => deseo_t('dj.error.ai'),
        'terms_accepted' => deseo_t('dj.error.terms'),
        'privacy_acknowledged' => deseo_t('dj.error.privacy'),
    ] as $field => $message) {
        if (empty($_POST[$field])) {
            $errors[] = $message;
        }
    }

    $upload = $_FILES['photo'] ?? null;
    $photoMime = '';
    $photoExt = '';
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = deseo_t('dj.error.photo_required');
    } elseif (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = deseo_t('dj.error.photo_upload');
    } elseif ((int)($upload['size'] ?? 0) > 5 * 1024 * 1024) {
        $errors[] = deseo_t('dj.error.photo_size');
    } elseif (!is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
        $errors[] = deseo_t('dj.error.photo_invalid');
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $photoMime = (string)$finfo->file((string)$upload['tmp_name']);
        $allowedImages = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowedImages[$photoMime])) {
            $errors[] = deseo_t('dj.error.photo_format');
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
                throw new RuntimeException(deseo_t('dj.error.slot_unavailable'));
            }

            $uploadDir = __DIR__ . '/iluma/uploads/djs';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException(deseo_t('dj.error.photo_save'));
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
                    bio, photo_path, set_type, work_sample_url, tracklist, status,
                    rights_confirmed, ai_confirmed, age_confirmed,
                    terms_version, terms_accepted_at,
                    privacy_version, privacy_acknowledged_at, booking_token
                 ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', 'pending',
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
                $workSampleUrl,
                DESEO_DJ_TERMS_VERSION,
                $now,
                DESEO_DJ_PRIVACY_VERSION,
                $now,
                $bookingToken,
            ]);

            $bookingId = (int)$pdo->lastInsertId();
            $pdo->commit();

            $mailSent = true;
            try {
                $mailStmt = $pdo->prepare(
                    "SELECT b.*, s.day_of_week, s.start_time, s.end_time
                     FROM dj_season_bookings b
                     INNER JOIN dj_season_slots s ON s.id = b.slot_id
                     WHERE b.id = ? AND b.season = ?
                     LIMIT 1"
                );
                $mailStmt->execute([$bookingId, DESEO_DJ_SEASON]);
                $submittedBooking = $mailStmt->fetch(PDO::FETCH_ASSOC);

                if ($submittedBooking) {
                    $mail = deseo_dj_submission_email($submittedBooking);
                    deseo_send_smtp_mail(
                        (string)$submittedBooking['email'],
                        (string)$submittedBooking['artist_name'],
                        (string)$mail['subject'],
                        (string)$mail['html'],
                        (string)$mail['text']
                    );
                }
            } catch (Throwable $mailError) {
                $mailSent = false;
                error_log('Season 6 submission confirmation email failed: ' . $mailError->getMessage());
            }

            if (!empty($submittedBooking)) {
                try {
                    $adminMail = deseo_dj_admin_submission_email($submittedBooking);
                    deseo_send_smtp_mail(
                        'greg@iluma.gr',
                        'Greg · ILUMA',
                        (string)$adminMail['subject'],
                        (string)$adminMail['html'],
                        (string)$adminMail['text']
                    );
                } catch (Throwable $adminMailError) {
                    error_log('Season 6 admin application notification failed: ' . $adminMailError->getMessage());
                }
            }

            $_SESSION['dj_last_submission'] = time();
            $_SESSION['dj_form_csrf'] = bin2hex(random_bytes(32));
            header('Location: /dj?submitted=1&mail=' . ($mailSent ? '1' : '0') . '&lang=' . rawurlencode(deseo_lang()));
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
                $errors[] = deseo_t('dj.error.db_conflict');
            } else {
                error_log('Season 6 DJ booking failed: ' . $e->getMessage());
                $errors[] = deseo_t('dj.error.generic');
            }
        }
    }
}

$slots = dj_season_slots($pdo);
$slotsByDay = [];
foreach ($slots as $slot) {
    $slotsByDay[(int)$slot['day_of_week']][] = $slot;
}

$meta_title = deseo_t('dj.meta.title');
$meta_desc = deseo_t('dj.meta.desc');
$meta_canonical = 'https://deseoradio.com/dj';
$meta_robots = 'noindex,nofollow,noarchive,nosnippet,noimageindex';
$private_page = true;
$extra_styles = ['/assets/css/djs.css'];

require_once __DIR__ . '/includes/head-meta.php';
require_once __DIR__ . '/includes/header.php';
?>

<main id="main-content" class="dj-season-page">
    <section class="dj-season-hero">
        <div class="wide-shell dj-season-hero-grid">
            <div>
                <div class="dj-season-brand-lockup">
                    <span>SEASON 6 · DJ CALL</span>
                </div>
                <h1 class="metal-title">Bring your sound.<br>Join Season 6.</h1>
                <p class="dj-season-lead" data-i18n="dj.hero.lead"><?= deseo_e(deseo_t('dj.hero.lead')) ?></p>
                <div class="dj-call-countdown dj-season-countdown" id="dj-season-countdown" data-deadline="2026-10-10T23:55:00+03:00" aria-live="polite">
                    <span class="dj-call-countdown-label">ΑΠΟΜΕΝΟΥΝ</span>
                    <strong class="dj-call-countdown-value">
                        <span data-countdown-days>--</span> <small>ΜΕΡΕΣ</small>
                        <i aria-hidden="true">—</i>
                        <span data-countdown-hours>--</span> <small>ΩΡΕΣ</small>
                        <i aria-hidden="true">—</i>
                        <span data-countdown-minutes>--</span> <small>ΛΕΠΤΑ</small>
                    </strong>
                </div>
            </div>

            <aside class="dj-season-intro-card">
                <span>WHAT WE NEED</span>
                <ol>
                    <li><strong>01</strong><p data-i18n="dj.need.1"><?= deseo_e(deseo_t('dj.need.1')) ?></p></li>
                    <li><strong>02</strong><p data-i18n="dj.need.2"><?= deseo_e(deseo_t('dj.need.2')) ?></p></li>
                    <li><strong>03</strong><p data-i18n="dj.need.3"><?= deseo_e(deseo_t('dj.need.3')) ?></p></li>
                </ol>
                <p class="dj-season-small" data-i18n="dj.need.note"><?= deseo_e(deseo_t('dj.need.note')) ?></p>
            </aside>
        </div>
    </section>

    <section class="dj-season-content">
        <div class="wide-shell dj-season-layout">
            <div class="dj-season-info">
                <span class="kicker">DESEO RADIO · SEASON 6</span>
                <h2 data-i18n="dj.info.title"><?= deseo_e(deseo_t('dj.info.title')) ?></h2>
                <p data-i18n="dj.info.p1"><?= deseo_e(deseo_t('dj.info.p1')) ?></p>
                <p data-i18n="dj.info.p2"><?= deseo_e(deseo_t('dj.info.p2')) ?></p>

            </div>

            <div class="dj-season-form-card" id="apply">
                <div class="dj-season-form-head">
                    <div>
                        <span>SEASON 6 · DJ INQUIRY</span>
                        <h2>Send your profile</h2>
                    </div>
                    <strong>S6</strong>
                </div>

                <?php if ($success): ?>
                    <div class="dj-alert dj-alert-success">
                        <strong><?= deseo_e(deseo_t('dj.success.title')) ?></strong>
                        <p><?= deseo_e(deseo_t('dj.success.received')) ?> <?= deseo_e($confirmationMailFailed ? deseo_t('dj.success.mail_failed') : deseo_t('dj.success.mail_sent')) ?> <?= deseo_e(sprintf(deseo_t('dj.success.deadline'), DESEO_DJ_DECISION_DEADLINE)) ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($errors): ?>
                    <div class="dj-alert dj-alert-error" role="alert">
                        <strong><?= deseo_e(deseo_t('dj.error.heading')) ?></strong>
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
                                <span data-i18n="dj.field.full_name"><?= deseo_e(deseo_t('dj.field.full_name')) ?></span>
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
                                <span><span data-i18n="dj.field.photo"><?= deseo_e(deseo_t('dj.field.photo')) ?></span> <small data-i18n="dj.field.photo_note"><?= deseo_e(deseo_t('dj.field.photo_note')) ?></small></span>
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>02 · YOUR SOUND</legend>
                        <div class="dj-form-grid">
                            <label>
                                <span data-i18n="dj.field.set_type"><?= deseo_e(deseo_t('dj.field.set_type')) ?></span>
                                <select name="set_type" required>
                                    <option value="new" data-i18n="dj.set.new" <?= $old['set_type'] === 'new' ? 'selected' : '' ?>><?= deseo_e(deseo_t('dj.set.new')) ?></option>
                                    <option value="previous" data-i18n="dj.set.previous" <?= $old['set_type'] === 'previous' ? 'selected' : '' ?>><?= deseo_e(deseo_t('dj.set.previous')) ?></option>
                                    <option value="exclusive" data-i18n="dj.set.exclusive" <?= $old['set_type'] === 'exclusive' ? 'selected' : '' ?>><?= deseo_e(deseo_t('dj.set.exclusive')) ?></option>
                                </select>
                            </label>
                            <label>
                                <span>Work sample link * <small>SoundCloud / Mixcloud / YouTube / Drive</small></span>
                                <input type="url" name="work_sample_url" maxlength="500" value="<?= deseo_e($old['work_sample_url']) ?>" placeholder="https://..." required>
                            </label>
                            <div class="dj-work-sample-note dj-field-full">
                                <strong data-i18n="dj.sample.title"><?= deseo_e(deseo_t('dj.sample.title')) ?></strong>
                                <span data-i18n="dj.sample.note"><?= deseo_e(deseo_t('dj.sample.note')) ?></span>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="dj-slot-fieldset">
                        <legend>03 · DAY & TIME</legend>
                        <p class="dj-field-note" data-i18n="dj.slot.note"><?= deseo_e(deseo_t('dj.slot.note')) ?></p>

                        <div class="dj-slot-days">
                            <?php foreach ([3, 4, 5, 6, 7] as $day): ?>
                                <section class="dj-slot-day">
                                    <h3 data-i18n="day.<?= (int)$day ?>"><?= deseo_e(deseo_t_day($day)) ?></h3>
                                    <div class="dj-slot-grid">
                                        <?php foreach ($slotsByDay[$day] ?? [] as $slot): ?>
                                            <label class="dj-slot">
                                                <input
                                                    type="radio"
                                                    name="slot_id"
                                                    value="<?= (int)$slot['id'] ?>"
                                                    <?= ((string)$slot['id'] === $old['slot_id']) ? 'checked' : '' ?>
                                                    required>
                                                <span>
                                                    <strong><?= deseo_e(dj_season_format_time($slot['start_time'])) ?></strong>
                                                    <small data-i18n="dj.slot.available"><?= deseo_e(deseo_t('dj.slot.available')) ?></small>
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
                            <label><input type="checkbox" name="age_confirmed" value="1" required><span data-i18n="dj.confirm.age"><?= deseo_e(deseo_t('dj.confirm.age')) ?></span></label>
                            <label><input type="checkbox" name="rights_confirmed" value="1" required><span data-i18n="dj.confirm.rights"><?= deseo_e(deseo_t('dj.confirm.rights')) ?></span></label>
                            <label><input type="checkbox" name="ai_confirmed" value="1" required><span data-i18n="dj.confirm.ai"><?= deseo_e(deseo_t('dj.confirm.ai')) ?></span></label>
                            <label><input type="checkbox" name="terms_accepted" value="1" required><span><span data-i18n="dj.confirm.terms_prefix"><?= deseo_e(deseo_t('dj.confirm.terms_prefix')) ?></span> <a href="/djterms" target="_blank" rel="noopener" data-i18n="dj.confirm.terms_link"><?= deseo_e(deseo_t('dj.confirm.terms_link')) ?></a>.</span></label>
                            <label><input type="checkbox" name="privacy_acknowledged" value="1" required><span><span data-i18n="dj.confirm.privacy_prefix"><?= deseo_e(deseo_t('dj.confirm.privacy_prefix')) ?></span> <a href="/privacy" target="_blank" rel="noopener" data-i18n="dj.confirm.privacy_link"><?= deseo_e(deseo_t('dj.confirm.privacy_link')) ?></a> <span data-i18n="dj.confirm.privacy_suffix"><?= deseo_e(deseo_t('dj.confirm.privacy_suffix')) ?></span></span></label>
                        </div>
                    </fieldset>

                    <div class="dj-turnstile-wrap">
                        <?php if ($turnstileConfigured): ?>
                            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                            <div class="cf-turnstile"
                                 data-sitekey="<?= deseo_e($turnstileSiteKey) ?>"
                                 data-theme="dark"
                                 data-language="<?= deseo_e(deseo_lang()) ?>"
                                 data-action="dj_inquiry"></div>
                        <?php else: ?>
                            <div class="dj-turnstile-missing" data-i18n="dj.security.closed"><?= deseo_e(deseo_t('dj.security.closed')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="dj-submit-row">
                        <p data-i18n="dj.submit.note"><?= deseo_e(deseo_t('dj.submit.note')) ?></p>
                        <button type="submit" class="button button-red" <?= $turnstileConfigured ? '' : 'disabled' ?>>SEND INQUIRY · SEASON 6</button>
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
    var countdown = document.getElementById('dj-season-countdown');
    var countdownTimer = null;

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

    function updateCountdown() {
        if (!countdown) return;

        var deadline = new Date(countdown.getAttribute('data-deadline')).getTime();
        var remaining = Math.max(0, deadline - Date.now());
        var totalMinutes = Math.floor(remaining / 60000);
        var days = Math.floor(totalMinutes / 1440);
        var hours = Math.floor((totalMinutes % 1440) / 60);
        var minutes = totalMinutes % 60;
        var pad = function (value) { return String(value).padStart(2, '0'); };

        var daysEl = countdown.querySelector('[data-countdown-days]');
        var hoursEl = countdown.querySelector('[data-countdown-hours]');
        var minutesEl = countdown.querySelector('[data-countdown-minutes]');

        if (daysEl) daysEl.textContent = pad(days);
        if (hoursEl) hoursEl.textContent = pad(hours);
        if (minutesEl) minutesEl.textContent = pad(minutes);

        if (remaining <= 0) {
            countdown.classList.add('is-ended');
            if (countdownTimer) clearInterval(countdownTimer);
        }
    }

    updateTerms();
    updateBioCount();
    updateCountdown();

    if (countdown) {
        countdownTimer = setInterval(updateCountdown, 1000);
    }
}());
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
