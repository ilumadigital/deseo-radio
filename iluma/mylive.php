<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/mylive-email-reminders.php';
require_once __DIR__ . '/../includes/mylive-push.php';
require_once __DIR__ . '/admin-ui.php';

deseo_mylive_bootstrap($pdo);
deseo_mylive_push_bootstrap($pdo);

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
    $stmt = $pdo->prepare(
        "SELECT a.*, b.status AS application_status
         FROM dj_portal_accounts a
         LEFT JOIN dj_season_bookings b ON b.id = a.booking_id
         WHERE a.id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) throw new RuntimeException('Το MyLive account δεν βρέθηκε.');
    return $account;
}


function mylive_admin_email_test_context(PDO $pdo, array $account): array {
    $accountId = (int)($account['id'] ?? 0);

    $programStmt = $pdo->prepare(
        "SELECT id AS program_id, day_of_week, start_time, end_time
         FROM program
         WHERE mylive_account_id = ?
         ORDER BY day_of_week ASC, start_time ASC
         LIMIT 1"
    );
    $programStmt->execute([$accountId]);
    $program = $programStmt->fetch(PDO::FETCH_ASSOC);

    if (!$program) {
        $program = [
            'program_id' => 0,
            'day_of_week' => (int)($account['day_of_week'] ?? 0),
            'start_time' => (string)($account['start_time'] ?? ''),
            'end_time' => (string)($account['end_time'] ?? ''),
        ];
    }

    $day = (int)($program['day_of_week'] ?? 0);
    $start = trim((string)($program['start_time'] ?? ''));
    if ($day < 1 || $day > 7 || $start === '') {
        throw new RuntimeException('Δεν υπάρχει έγκυρο weekly slot για να δημιουργηθεί το test email.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Athens'));
    [$showStart, $showEnd] = deseo_mylive_email_program_occurrence($program, $now);

    $pendingSet = deseo_mylive_email_pending_set($pdo, $accountId);
    $latestEpisode = deseo_mylive_email_latest_episode($pdo, $accountId);
    $episode = $pendingSet
        ? (int)$pendingSet['episode_no']
        : max(1, $latestEpisode + 1);

    return [
        'program_id' => (int)($program['program_id'] ?? 0),
        'show_start' => $showStart,
        'show_end' => $showEnd,
        'episode' => $episode,
        'pending_set' => $pendingSet,
    ];
}

function mylive_admin_asset_extension(string $name): string {
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

function mylive_admin_asset_media_folder_label(string $rootLabel, string $relativeFolder): string {
    if ($relativeFolder === '.' || $relativeFolder === '') {
        return $rootLabel;
    }

    $parts = explode('/', str_replace('\\', '/', $relativeFolder));
    $mapped = array_map(
        static fn(string $part): string => str_replace('_', ' ', $part),
        $parts
    );

    return $rootLabel . ' / ' . implode(' / ', $mapped);
}

function mylive_admin_asset_media_library_collect(string $absoluteRoot, string $publicBase, string $rootLabel): array {
    $rootReal = realpath($absoluteRoot);
    if (!$rootReal || !is_dir($rootReal)) return [];

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'mp3', 'wav'];
    $items = [];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) continue;

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $allowed, true)) continue;

            $size = (int)$file->getSize();
            if ($size < 1 || $size > DESEO_MYLive_ASSET_MAX_BYTES) continue;

            $real = $file->getRealPath();
            if (!$real || !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) continue;

            $relative = ltrim(substr($real, strlen($rootReal)), DIRECTORY_SEPARATOR);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($relative === '' || str_contains($relative, '/.')) continue;

            $segments = array_map(
                static fn(string $segment): string => rawurlencode($segment),
                explode('/', $relative)
            );

            $url = rtrim($publicBase, '/') . '/' . implode('/', $segments);
            $folder = mylive_admin_asset_media_folder_label($rootLabel, dirname($relative));

            $items[] = [
                'url' => $url,
                'name' => basename($relative),
                'folder' => $folder,
                'extension' => $extension,
                'size' => $size,
                'mtime' => (int)$file->getMTime(),
                'is_image' => in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true),
                'is_audio' => in_array($extension, ['mp3', 'wav'], true),
            ];
        }
    } catch (Throwable $mediaScanError) {
        error_log('MyLive asset media browser scan failed for ' . $rootLabel . ': ' . $mediaScanError->getMessage());
        return [];
    }

    return $items;
}

function mylive_admin_asset_media_library(): array {
    $items = array_merge(
        mylive_admin_asset_media_library_collect(__DIR__ . '/uploads', '/iluma/uploads', 'Uploads'),
        mylive_admin_asset_media_library_collect(dirname(__DIR__) . '/assets/img', '/assets/img', 'Site Assets')
    );

    usort($items, static function(array $a, array $b): int {
        $timeCompare = ((int)$b['mtime']) <=> ((int)$a['mtime']);
        return $timeCompare !== 0
            ? $timeCompare
            : strcasecmp((string)$a['name'], (string)$b['name']);
    });

    return array_slice($items, 0, 500);
}

function mylive_admin_resolve_server_asset(string $reference): array {
    $reference = trim($reference);
    if ($reference === '' || str_contains($reference, "\0")) {
        throw new RuntimeException('Επίλεξε έγκυρο αρχείο από το File Manager.');
    }

    $path = (string)(parse_url($reference, PHP_URL_PATH) ?? '');
    $path = rawurldecode($path);

    $roots = [
        '/iluma/uploads/' => __DIR__ . '/uploads',
        '/assets/img/' => dirname(__DIR__) . '/assets/img',
    ];

    foreach ($roots as $publicPrefix => $absoluteRoot) {
        if (!str_starts_with($path, $publicPrefix)) continue;

        $rootReal = realpath($absoluteRoot);
        if (!$rootReal || !is_dir($rootReal)) break;

        $relative = ltrim(substr($path, strlen($publicPrefix)), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            throw new RuntimeException('Το επιλεγμένο server asset δεν είναι έγκυρο.');
        }

        $candidate = realpath($rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if (
            !$candidate
            || !is_file($candidate)
            || !str_starts_with($candidate, $rootReal . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Το επιλεγμένο server asset δεν βρέθηκε.');
        }

        $extension = mylive_admin_asset_extension($candidate);
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'mp3', 'wav'];
        if (!in_array($extension, $allowed, true)) {
            throw new RuntimeException('Επιτρέπονται JPG, PNG, WEBP, PDF, MP3 και WAV.');
        }

        $size = (int)filesize($candidate);
        if ($size < 1 || $size > DESEO_MYLive_ASSET_MAX_BYTES) {
            throw new RuntimeException('Το asset πρέπει να είναι έως 256 MB.');
        }

        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($candidate);
        }

        return [
            'absolute' => $candidate,
            'name' => basename($candidate),
            'extension' => $extension,
            'size' => $size,
            'mime' => $mime,
        ];
    }

    throw new RuntimeException('Το αρχείο πρέπει να προέρχεται από ασφαλή φάκελο του File Manager.');
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

            } elseif ($action === 'test_set_reminder_email') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $account = mylive_admin_account($pdo, $accountId);

                if (!filter_var((string)$account['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Το MyLive account δεν έχει έγκυρο email.');
                }

                $context = mylive_admin_email_test_context($pdo, $account);
                $showStart = $context['show_start'];

                $mail = deseo_mylive_set_due_email($account, [
                    'episode' => (int)$context['episode'],
                    'show_date' => $showStart->format('d.m.Y'),
                    'show_time' => $showStart->format('H:i'),
                ]);
                $mail['subject'] = '[TEST] ' . (string)$mail['subject'];

                deseo_send_smtp_mail(
                    (string)$account['email'],
                    (string)$account['artist_name'],
                    (string)$mail['subject'],
                    (string)$mail['html'],
                    (string)$mail['text'],
                    true
                );

                $notice = 'Στάλθηκε TEST Set Reminder email στον '
                    . (string)$account['artist_name']
                    . ' · '
                    . (string)$account['email']
                    . ' · EP'
                    . str_pad((string)(int)$context['episode'], 3, '0', STR_PAD_LEFT)
                    . '. Δεν επηρεάστηκε το automation log.';

            } elseif ($action === 'test_on_air_email') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $account = mylive_admin_account($pdo, $accountId);

                if (!filter_var((string)$account['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Το MyLive account δεν έχει έγκυρο email.');
                }

                $context = mylive_admin_email_test_context($pdo, $account);
                $showStart = $context['show_start'];

                $mail = deseo_mylive_on_air_social_email($account, [
                    'episode' => (int)$context['episode'],
                    'show_time' => $showStart->format('H:i'),
                ]);
                $mail['subject'] = '[TEST] ' . (string)$mail['subject'];

                deseo_send_smtp_mail(
                    (string)$account['email'],
                    (string)$account['artist_name'],
                    (string)$mail['subject'],
                    (string)$mail['html'],
                    (string)$mail['text'],
                    true
                );

                $notice = 'Στάλθηκε TEST On Air email στον '
                    . (string)$account['artist_name']
                    . ' · '
                    . (string)$account['email']
                    . '. Δεν επηρεάστηκε το automation log.';

            } elseif ($action === 'test_set_reminder_push') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $account = mylive_admin_account($pdo, $accountId);

                if (!deseo_mylive_push_account_enabled($pdo, $accountId)) {
                    throw new RuntimeException('Ο DJ δεν έχει ενεργοποιήσει Push Notifications στο MyLive.');
                }
                if (deseo_mylive_push_subscription_count($pdo, $accountId) < 1) {
                    throw new RuntimeException('Δεν υπάρχει ενεργή συσκευή push για αυτό το MyLive account.');
                }

                $context = mylive_admin_email_test_context($pdo, $account);
                $showStart = $context['show_start'];
                $episode = (int)$context['episode'];

                deseo_mylive_push_send_to_account(
                    $pdo,
                    $accountId,
                    '[TEST] DJ Set Reminder · Deseo Radio',
                    (string)$account['artist_name'] . ', σε 3 ημέρες είναι το επόμενο show σου. Ανέβασε το EP'
                        . str_pad((string)$episode, 3, '0', STR_PAD_LEFT)
                        . ' στο MyLive.',
                    'https://deseoradio.com/mylive/#sets',
                    [
                        'name' => 'TEST · Set Reminder · ' . (string)$account['artist_name'],
                        'expire_push' => '30m',
                        'auto_hide' => 1,
                    ]
                );

                $notice = 'Στάλθηκε TEST Set Reminder push στον '
                    . (string)$account['artist_name']
                    . ' · '
                    . deseo_mylive_push_subscription_count($pdo, $accountId)
                    . ' ενεργή συσκευή/ές. Δεν επηρεάστηκε το automation log.';

            } elseif ($action === 'test_on_air_push') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $account = mylive_admin_account($pdo, $accountId);

                if (!deseo_mylive_push_account_enabled($pdo, $accountId)) {
                    throw new RuntimeException('Ο DJ δεν έχει ενεργοποιήσει Push Notifications στο MyLive.');
                }
                if (deseo_mylive_push_subscription_count($pdo, $accountId) < 1) {
                    throw new RuntimeException('Δεν υπάρχει ενεργή συσκευή push για αυτό το MyLive account.');
                }

                deseo_mylive_push_send_to_account(
                    $pdo,
                    $accountId,
                    '[TEST] Είσαι τώρα στον αέρα · Deseo Radio',
                    (string)$account['artist_name']
                        . ', το show σου είναι live τώρα. Ανέβασε το δημιουργικό σου στα social media και κάνε tag το Deseo Radio.',
                    'https://deseoradio.com',
                    [
                        'name' => 'TEST · On Air · ' . (string)$account['artist_name'],
                        'expire_push' => '30m',
                        'auto_hide' => 0,
                    ]
                );

                $notice = 'Στάλθηκε TEST On Air push στον '
                    . (string)$account['artist_name']
                    . ' · '
                    . deseo_mylive_push_subscription_count($pdo, $accountId)
                    . ' ενεργή συσκευή/ές. Δεν επηρεάστηκε το automation log.';

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
                $serverAssetPath = trim((string)($_POST['server_asset_path'] ?? ''));

                if (!in_array($type, ['artwork', 'dj_spot', 'dj_spot_30', 'other'], true)) $type = 'other';
                if ($title === '') $title = deseo_mylive_asset_label($type);

                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'mp3', 'wav'];
                $sourceMode = '';
                $sourceAbsolute = '';
                $tmp = '';
                $mime = '';
                $size = 0;
                $originalName = '';
                $extension = '';

                $file = isset($_FILES['asset_file']) && is_array($_FILES['asset_file'])
                    ? $_FILES['asset_file']
                    : null;
                $uploadError = is_array($file)
                    ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE)
                    : UPLOAD_ERR_NO_FILE;

                if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                    if ($uploadError !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('Το asset upload δεν ολοκληρώθηκε.');
                    }

                    $size = (int)($file['size'] ?? 0);
                    if ($size < 1 || $size > DESEO_MYLive_ASSET_MAX_BYTES) {
                        throw new RuntimeException('Το asset πρέπει να είναι έως 256 MB.');
                    }

                    $originalName = basename((string)($file['name'] ?? ''));
                    $extension = mylive_admin_asset_extension($originalName);
                    if (!in_array($extension, $allowed, true)) {
                        throw new RuntimeException('Επιτρέπονται JPG, PNG, WEBP, PDF, MP3 και WAV.');
                    }

                    $tmp = (string)($file['tmp_name'] ?? '');
                    if ($tmp === '' || !is_uploaded_file($tmp)) {
                        throw new RuntimeException('Μη έγκυρο upload.');
                    }

                    if (class_exists('finfo')) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = (string)$finfo->file($tmp);
                    }

                    $sourceMode = 'upload';
                } elseif ($serverAssetPath !== '') {
                    $serverAsset = mylive_admin_resolve_server_asset($serverAssetPath);
                    $sourceMode = 'server';
                    $sourceAbsolute = (string)$serverAsset['absolute'];
                    $originalName = (string)$serverAsset['name'];
                    $extension = (string)$serverAsset['extension'];
                    $size = (int)$serverAsset['size'];
                    $mime = (string)$serverAsset['mime'];
                } else {
                    throw new RuntimeException('Ανέβασε νέο αρχείο ή επίλεξε ένα από το File Manager.');
                }

                $dir = dirname(__DIR__) . '/mylive/storage/assets/' . $accountId;
                if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                    throw new RuntimeException('Δεν ήταν δυνατή η δημιουργία asset folder.');
                }

                $storedName = deseo_mylive_slug((string)$account['artist_name'])
                    . '_' . strtoupper($type) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $extension;
                $absolute = $dir . '/' . $storedName;

                if ($sourceMode === 'upload') {
                    if (!move_uploaded_file($tmp, $absolute)) {
                        throw new RuntimeException('Δεν ήταν δυνατή η αποθήκευση του asset.');
                    }
                } else {
                    if (!copy($sourceAbsolute, $absolute)) {
                        throw new RuntimeException('Δεν ήταν δυνατή η αντιγραφή του asset από το File Manager.');
                    }
                }

                $relative = 'storage/assets/' . $accountId . '/' . $storedName;
                $stmt = $pdo->prepare(
                    "INSERT INTO dj_portal_assets
                     (account_id, asset_type, title, original_name, stored_name, file_path, file_size, mime_type)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );

                try {
                    $stmt->execute([$accountId, $type, $title, $originalName, $storedName, $relative, $size, $mime]);
                } catch (Throwable $assetDbError) {
                    if (is_file($absolute)) @unlink($absolute);
                    throw $assetDbError;
                }

                $notice = $sourceMode === 'server'
                    ? 'Το asset προστέθηκε από το File Manager στο MyLive του ' . (string)$account['artist_name'] . '.'
                    : 'Το asset ανέβηκε στο MyLive του ' . (string)$account['artist_name'] . '.';

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

                if ($status === 'broadcasted' && !empty($updatedSet['file_deleted_at'])) {
                    $notice = 'Το DJ Set σημειώθηκε ως BROADCASTED και το audio file διαγράφηκε αμέσως από τον server. Το episode παραμένει κανονικά στη βάση και στο MyLive ιστορικό.';
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

$assetMediaLibrary = mylive_admin_asset_media_library();
$assetMediaFolders = array_values(array_unique(array_map(
    static fn(array $item): string => (string)$item['folder'],
    $assetMediaLibrary
)));
usort($assetMediaFolders, 'strnatcasecmp');

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

                        <form method="post" class="mylive-pending-approve" data-deseo-confirm="Να ενεργοποιηθεί το MyLive για <?= admin_e($pending['artist_name']) ?> και να σταλεί το onboarding email;" data-deseo-confirm-title="Ενεργοποίηση MyLive" data-deseo-confirm-label="Ενεργοποίηση">
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
                                    <form method="post" data-deseo-confirm="Να εκδοθεί νέο temporary password και να σταλεί ξανά το onboarding email;" data-deseo-confirm-title="Νέο temporary password" data-deseo-confirm-label="Αποστολή">
                                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="reset_access">
                                        <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                        <button class="button button-secondary" type="submit">Reset Access & Resend</button>
                                    </form>
                                    <div class="mylive-email-test-actions">
                                        <span>EMAIL TESTS</span>
                                        <form method="post"
                                              data-deseo-confirm="Να σταλεί τώρα TEST Set Reminder email στο <?= admin_e((string)$account['email']) ?>; Δεν επηρεάζεται το automation log."
                                              data-deseo-confirm-title="Test · Set Reminder"
                                              data-deseo-confirm-label="Send test email">
                                            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="test_set_reminder_email">
                                            <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                            <button class="button button-secondary mylive-email-test-button" type="submit">Send Set Reminder</button>
                                        </form>
                                        <form method="post"
                                              data-deseo-confirm="Να σταλεί τώρα TEST On Air / Social email στο <?= admin_e((string)$account['email']) ?>; Δεν επηρεάζεται το automation log."
                                              data-deseo-confirm-title="Test · On Air Email"
                                              data-deseo-confirm-label="Send test email">
                                            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="test_on_air_email">
                                            <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                            <button class="button button-secondary mylive-email-test-button" type="submit">Send On Air Email</button>
                                        </form>
                                    </div>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle_account">
                                        <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                        <input type="hidden" name="active" value="<?= !empty($account['is_active']) ? '0' : '1' ?>">
                                        <button class="button <?= !empty($account['is_active']) ? 'button-danger' : 'button-primary' ?>" type="submit">
                                            <?= !empty($account['is_active']) ? 'Disable Account' : 'Enable Account' ?>
                                        </button>
                                    </form>
                                    <form method="post" data-deseo-confirm="ΟΡΙΣΤΙΚΗ ΔΙΑΓΡΑΦΗ: Θα διαγραφούν το MyLive account, όλα τα DJ Sets και όλα τα προσωπικά assets. Συνέχεια;" data-deseo-confirm-title="Οριστική διαγραφή MyLive" data-deseo-confirm-label="Οριστική διαγραφή" data-deseo-confirm-danger>
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
                                        <form method="post"
                                              class="mylive-set-admin-row"
                                              data-deseo-confirm="BROADCASTED: Το audio file θα διαγραφεί ΑΜΕΣΩΣ και οριστικά από τον server. Το episode θα παραμείνει στο ιστορικό. Συνέχεια;" data-deseo-confirm-title="BROADCASTED · Διαγραφή audio" data-deseo-confirm-label="BROADCASTED" data-deseo-confirm-if-status="broadcasted" data-deseo-confirm-danger
                                              <?= !empty($set['file_deleted_at']) ? 'data-file-removed="1"' : '' ?>>
                                            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update_set">
                                            <input type="hidden" name="set_id" value="<?= (int)$set['id'] ?>">
                                            <div class="mylive-set-copy">
                                                <span>EP<?= str_pad((string)(int)$set['episode_no'], 3, '0', STR_PAD_LEFT) ?></span>
                                                <strong><?= admin_e($set['stored_name']) ?></strong>
                                                <small><?= admin_e(deseo_mylive_format_bytes((int)$set['file_size'])) ?> · <?= admin_e((string)$set['uploaded_at']) ?></small>
                                                <?php if (!empty($set['file_deleted_at'])): ?>
                                                    <em>Episode retained · audio file deleted from server</em>
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
                                                    FILE REMOVED · <?= admin_e(date('d.m.Y · H:i', strtotime((string)$set['file_deleted_at']))) ?>
                                                </span>
                                            <?php else: ?>
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
                                    <input type="hidden" name="server_asset_path" value="" data-mylive-server-asset-path>
                                    <input class="file-input" type="file" name="asset_file" accept=".jpg,.jpeg,.png,.webp,.pdf,.mp3,.wav">
                                    <button class="button button-secondary" type="button" data-mylive-asset-browser>Browse server / File Manager</button>
                                    <small data-mylive-server-asset-label style="grid-column:1/-1;color:#66666b;font-size:8px;line-height:1.45;">Upload νέο αρχείο ή επίλεξε υπάρχον από τον server.</small>
                                    <button class="button button-primary" type="submit">Add Asset</button>
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
                                                <form method="post" data-deseo-confirm="Να διαγραφεί αυτό το asset από το MyLive;" data-deseo-confirm-title="Διαγραφή asset" data-deseo-confirm-label="Διαγραφή" data-deseo-confirm-danger>
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

<div class="schedule-media-modal" id="myliveAssetMediaModal" hidden>
    <div class="schedule-media-backdrop" data-mylive-media-close></div>
    <section class="schedule-media-manager" role="dialog" aria-modal="true" aria-labelledby="myliveAssetMediaTitle">
        <header class="schedule-media-header">
            <div>
                <span>SERVER FILE MANAGER</span>
                <h2 id="myliveAssetMediaTitle">Choose DJ asset</h2>
                <p><?= count($assetMediaLibrary) ?> διαθέσιμα αρχεία από ασφαλείς φακέλους του Deseo Radio.</p>
            </div>
            <button type="button" class="schedule-media-close" data-mylive-media-close aria-label="Close">×</button>
        </header>

        <div class="schedule-media-layout">
            <aside class="schedule-media-folders">
                <button type="button" class="is-active" data-mylive-media-folder="all">
                    <span>ALL FILES</span><b><?= count($assetMediaLibrary) ?></b>
                </button>
                <?php foreach ($assetMediaFolders as $folder): ?>
                    <?php
                    $folderCount = count(array_filter(
                        $assetMediaLibrary,
                        static fn(array $item): bool => (string)$item['folder'] === $folder
                    ));
                    ?>
                    <button type="button" data-mylive-media-folder="<?= admin_e($folder) ?>">
                        <span><?= admin_e($folder) ?></span><b><?= $folderCount ?></b>
                    </button>
                <?php endforeach; ?>
            </aside>

            <div class="schedule-media-content">
                <div class="schedule-media-toolbar">
                    <label>
                        <span>SEARCH</span>
                        <input id="myliveAssetMediaSearch" type="search" placeholder="Filename or folder…" autocomplete="off">
                    </label>
                    <small>Click ένα αρχείο για να το προσθέσεις στον συγκεκριμένο DJ.</small>
                </div>

                <div class="schedule-media-grid" id="myliveAssetMediaGrid">
                    <?php foreach ($assetMediaLibrary as $media): ?>
                        <button type="button"
                                class="schedule-media-item"
                                data-mylive-media-item
                                data-mylive-media-url="<?= admin_e((string)$media['url']) ?>"
                                data-mylive-media-name="<?= admin_e((string)$media['name']) ?>"
                                data-mylive-media-folder="<?= admin_e((string)$media['folder']) ?>"
                                data-mylive-media-search="<?= admin_e(strtolower((string)$media['name'] . ' ' . (string)$media['folder'])) ?>">
                            <span class="schedule-media-thumb">
                                <?php if (!empty($media['is_image'])): ?>
                                    <img src="<?= admin_e((string)$media['url']) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span style="width:100%;height:100%;display:grid;place-items:center;color:#8b8b90;font-size:16px;font-weight:900;letter-spacing:.12em;">
                                        <?= !empty($media['is_audio']) ? 'AUDIO' : admin_e(strtoupper((string)$media['extension'])) ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                            <span class="schedule-media-meta">
                                <strong><?= admin_e((string)$media['name']) ?></strong>
                                <small><?= admin_e((string)$media['folder']) ?> · <?= admin_e(deseo_mylive_format_bytes((int)$media['size'])) ?></small>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="schedule-media-empty" id="myliveAssetMediaEmpty" <?= $assetMediaLibrary ? 'hidden' : '' ?>>
                    Δεν βρέθηκαν διαθέσιμα αρχεία.
                </div>
            </div>
        </div>
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

(function(){
    var modal=document.getElementById('myliveAssetMediaModal');
    var search=document.getElementById('myliveAssetMediaSearch');
    var empty=document.getElementById('myliveAssetMediaEmpty');
    var items=Array.prototype.slice.call(document.querySelectorAll('[data-mylive-media-item]'));
    var folders=Array.prototype.slice.call(document.querySelectorAll('[data-mylive-media-folder]'));
    var activeFolder='all';
    var activeForm=null;

    function closeBrowser(){
        if(!modal)return;
        modal.classList.remove('is-open');
        document.body.classList.remove('schedule-media-open');
        window.setTimeout(function(){modal.hidden=true;},160);
    }

    function openBrowser(form){
        if(!modal||!form)return;
        activeForm=form;
        modal.hidden=false;
        document.body.classList.add('schedule-media-open');
        window.requestAnimationFrame(function(){
            modal.classList.add('is-open');
            if(search)search.focus();
        });
    }

    function applyFilters(){
        var query=search?search.value.trim().toLowerCase():'';
        var visible=0;

        items.forEach(function(item){
            var folder=item.getAttribute('data-mylive-media-folder')||'';
            var haystack=item.getAttribute('data-mylive-media-search')||'';
            var folderMatch=activeFolder==='all'||folder===activeFolder;
            var searchMatch=!query||haystack.indexOf(query)!==-1;
            var show=folderMatch&&searchMatch;
            item.hidden=!show;
            if(show)visible++;
        });

        if(empty)empty.hidden=visible!==0;
    }

    document.querySelectorAll('[data-mylive-asset-browser]').forEach(function(button){
        button.addEventListener('click',function(){
            openBrowser(button.closest('form'));
        });
    });

    document.querySelectorAll('[data-mylive-media-close]').forEach(function(button){
        button.addEventListener('click',closeBrowser);
    });

    folders.forEach(function(button){
        button.addEventListener('click',function(){
            activeFolder=button.getAttribute('data-mylive-media-folder')||'all';
            folders.forEach(function(item){item.classList.toggle('is-active',item===button);});
            applyFilters();
        });
    });

    if(search)search.addEventListener('input',applyFilters);

    items.forEach(function(item){
        item.addEventListener('click',function(){
            if(!activeForm)return;

            var url=item.getAttribute('data-mylive-media-url')||'';
            var name=item.getAttribute('data-mylive-media-name')||url;
            var hidden=activeForm.querySelector('[data-mylive-server-asset-path]');
            var fileInput=activeForm.querySelector('input[name="asset_file"]');
            var label=activeForm.querySelector('[data-mylive-server-asset-label]');

            if(hidden)hidden.value=url;
            if(fileInput)fileInput.value='';
            if(label){
                label.textContent='Selected from server: '+name;
                label.style.color='var(--acid)';
            }

            closeBrowser();
        });
    });

    document.querySelectorAll('.mylive-asset-upload input[name="asset_file"]').forEach(function(input){
        input.addEventListener('change',function(){
            var form=input.closest('form');
            if(!form)return;

            var hidden=form.querySelector('[data-mylive-server-asset-path]');
            var label=form.querySelector('[data-mylive-server-asset-label]');
            var file=input.files&&input.files[0];

            if(file&&hidden)hidden.value='';
            if(label){
                label.textContent=file?'New upload: '+file.name:'Upload νέο αρχείο ή επίλεξε υπάρχον από τον server.';
                label.style.color=file?'var(--acid)':'#66666b';
            }
        });
    });

    document.addEventListener('keydown',function(event){
        if(event.key==='Escape'&&modal&&!modal.hidden)closeBrowser();
    });
}());
</script>
<?php admin_page_end(); ?>
