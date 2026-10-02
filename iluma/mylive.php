<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/mylive-hearthis.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/mylive-email-reminders.php';
require_once __DIR__ . '/../includes/mylive-push.php';
require_once __DIR__ . '/admin-ui.php';

deseo_mylive_bootstrap($pdo);
deseo_mylive_push_bootstrap($pdo);
deseo_mylive_maybe_run_email_scheduler($pdo);

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
    $occurrence = deseo_mylive_email_program_occurrence($program, $now);
    if ($occurrence === null) {
        throw new RuntimeException('Δεν υπάρχει επόμενη μετάδοση για αυτό το weekly slot μέσα στη Season 6 (14/10/2026–30/05/2027).');
    }
    [$showStart, $showEnd] = $occurrence;

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

function mylive_admin_asset_media_folder_display(string $folder): string {
    $folder = trim(str_replace('\\', '/', $folder), " /\t\n\r\0\x0B");
    if ($folder === '') return '';

    $parts = array_values(array_filter(
        array_map('trim', explode('/', $folder)),
        static fn(string $part): bool => $part !== ''
    ));

    if (!$parts) return $folder;

    return (string)end($parts);
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

            } elseif ($action === 'bulk_toggle_public_profiles') {
                $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;

                $bulkStmt = $pdo->query(
                    "SELECT id, artist_name, email, public_profile_enabled, account_status
                     FROM dj_portal_accounts
                     WHERE account_status = 'active'
                       AND is_active = 1
                     ORDER BY artist_name ASC, id ASC"
                );
                $bulkAccounts = $bulkStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$bulkAccounts) {
                    throw new RuntimeException('Δεν υπάρχουν MyLive accounts διαθέσιμα για μαζική αλλαγή Public Profile.');
                }

                if ($enabled) {
                    $enabledCount = 0;
                    $alreadyEnabledCount = 0;
                    $failedProfiles = [];

                    foreach ($bulkAccounts as $bulkAccount) {
                        $bulkAccountId = (int)$bulkAccount['id'];

                        if (!empty($bulkAccount['public_profile_enabled'])) {
                            $alreadyEnabledCount++;
                            continue;
                        }

                        deseo_mylive_public_profile_ensure($pdo, $bulkAccountId);
                        $pdo->prepare(
                            "UPDATE dj_portal_accounts
                             SET public_profile_enabled = 1
                             WHERE id = ?
                               AND account_status = 'active'
                               AND is_active = 1"
                        )->execute([$bulkAccountId]);

                        try {
                            $mail = deseo_mylive_public_profile_enabled_email($bulkAccount);
                            deseo_send_smtp_mail(
                                (string)$bulkAccount['email'],
                                (string)$bulkAccount['artist_name'],
                                $mail['subject'],
                                $mail['html'],
                                $mail['text']
                            );
                            $enabledCount++;
                        } catch (Throwable $mailError) {
                            $pdo->prepare(
                                "UPDATE dj_portal_accounts
                                 SET public_profile_enabled = 0
                                 WHERE id = ?"
                            )->execute([$bulkAccountId]);

                            $failedProfiles[] = (string)$bulkAccount['artist_name'];
                            error_log(
                                'MyLive bulk Public Profile activation email failed for account '
                                . $bulkAccountId
                                . ': '
                                . $mailError->getMessage()
                            );
                        }
                    }

                    $notice = 'Public Profiles ενεργοποιήθηκαν για '
                        . $enabledCount
                        . ' DJ'
                        . ($enabledCount === 1 ? '' : 's')
                        . '.';

                    if ($alreadyEnabledCount > 0) {
                        $notice .= ' ' . $alreadyEnabledCount . ' ήταν ήδη ενεργά.';
                    }

                    if ($failedProfiles) {
                        $visibleFailures = array_slice($failedProfiles, 0, 5);
                        $moreFailures = count($failedProfiles) - count($visibleFailures);
                        $error = 'Δεν ενεργοποιήθηκαν '
                            . count($failedProfiles)
                            . ' Public Profile'
                            . (count($failedProfiles) === 1 ? '' : 's')
                            . ' επειδή απέτυχε η αποστολή email: '
                            . implode(', ', $visibleFailures)
                            . ($moreFailures > 0 ? ' +' . $moreFailures . ' ακόμη' : '')
                            . '.';
                    }
                } else {
                    $disableStmt = $pdo->prepare(
                        "UPDATE dj_portal_accounts
                         SET public_profile_enabled = 0
                         WHERE account_status = 'active'
                           AND is_active = 1
                           AND public_profile_enabled = 1"
                    );
                    $disableStmt->execute();
                    $disabledCount = $disableStmt->rowCount();

                    $notice = 'Public Profiles απενεργοποιήθηκαν για '
                        . $disabledCount
                        . ' DJ'
                        . ($disabledCount === 1 ? '' : 's')
                        . '. Τα αποθηκευμένα bios, drafts και social links διατηρήθηκαν.';
                }

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
                                . '. Τα διαθέσιμα social links και το bio της αίτησης μεταφέρθηκαν όπου υπήρχαν. Ο DJ μπορεί προαιρετικά να κρατήσει το bio ως έχει ή να το βελτιώσει μέσα από το MyLive.';
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

                if (!in_array($type, ['artwork', 'hearthis_cover', 'dj_spot', 'dj_spot_30', 'other'], true)) $type = 'other';
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

                if ($type === 'hearthis_cover') {
                    // The HearThis cover is the DJ's dedicated square 02 asset.
                    // Never allow the station logo or promotional 01 by accident.
                    if (!preg_match('/(?:^|[^0-9])02\\.(?:png|jpe?g|webp)$/i', $originalName)) {
                        throw new RuntimeException('Επίλεξε το τετράγωνο DJ artwork 02 (π.χ. GregLef02.png), όχι το 01 ή το λογότυπο του σταθμού.');
                    }
                    $imageSource = $sourceMode === 'upload' ? $tmp : $sourceAbsolute;
                    $imageInfo = @getimagesize($imageSource);
                    if (!is_array($imageInfo) || empty($imageInfo[0])
                        || (int)$imageInfo[0] !== (int)$imageInfo[1]
                        || !in_array((string)($imageInfo['mime'] ?? ''), ['image/png', 'image/jpeg', 'image/webp'], true)) {
                        throw new RuntimeException('Το HearThis artwork 02 πρέπει να είναι έγκυρη τετράγωνη εικόνα PNG, JPG ή WEBP.');
                    }
                    $mime = (string)$imageInfo['mime'];
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

            } elseif ($action === 'update_set' || $action === 'update_set_from_library') {
                $setId = (int)($_POST['set_id'] ?? 0);
                $status = (string)($_POST['status'] ?? 'received');
                if ($action === 'update_set_from_library') {
                    // The quick editor changes only status; preserve the current
                    // admin note without trusting a stale hidden form value.
                    $noteStmt = $pdo->prepare("SELECT admin_note FROM dj_portal_sets WHERE id = ? LIMIT 1");
                    $noteStmt->execute([$setId]);
                    $currentNote = $noteStmt->fetchColumn();
                    if ($currentNote === false) {
                        throw new RuntimeException('Το DJ Set δεν βρέθηκε.');
                    }
                    $note = (string)$currentNote;
                } else {
                    $note = trim((string)($_POST['admin_note'] ?? ''));
                }

                $updatedSet = deseo_mylive_update_set_status(
                    $pdo, $setId, $status, $note, (string)($_POST['scheduled_show_end'] ?? '')
                );

                if ($status === 'broadcasted' && empty($updatedSet['file_deleted_at'])) {
                    $notice = 'Το DJ Set σημειώθηκε ως BROADCASTED. Το MP3 παραμένει στον server μέχρι να επιβεβαιωθεί το HearThis URL και να αποθηκευτεί στο PMS.';
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
        CASE COALESCE(b.status, '')
            WHEN 'approved' THEN 0
            WHEN 'guest' THEN 1
            ELSE 2
        END,
        CASE
            WHEN a.day_of_week BETWEEN 1 AND 7 THEN a.day_of_week
            ELSE 8
        END,
        a.start_time ASC,
        a.artist_name ASC,
        a.id ASC"
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
$hearthisCoverByAccount = [];
$hearthisCoverErrorByAccount = [];
foreach ($accounts as $account) {
    $accountId = (int)$account['id'];
    $assetsByAccount[$accountId] = deseo_mylive_assets($pdo, $accountId);
    try {
        $hearthisCoverByAccount[$accountId] = deseo_hearthis_cover($pdo, $accountId);
    } catch (Throwable $coverLookupError) {
        $hearthisCoverByAccount[$accountId] = null;
        $hearthisCoverErrorByAccount[$accountId] = $coverLookupError->getMessage();
    }

    $stmt = $pdo->prepare(
        "SELECT id, episode_no, stored_name, file_size, status, admin_note,
                broadcasted_at, delete_after, file_deleted_at, scheduled_show_end,
                hearthis_status, hearthis_url, hearthis_error, hearthis_meta_warning,
                hearthis_set_status, hearthis_set_id, uploaded_at
         FROM dj_portal_sets WHERE account_id = ? ORDER BY episode_no DESC LIMIT 8"
    );
    $stmt->execute([$accountId]);
    $setsByAccount[$accountId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// The overview and delivery library use the complete set history, not the
// eight-episode limit used by each DJ's management panel.
$receivedSetsStmt = $pdo->query(
    "SELECT s.id, s.account_id, s.episode_no, s.stored_name, s.file_size,
            s.status, s.file_deleted_at, s.scheduled_show_end, s.hearthis_status,
            s.hearthis_url, s.hearthis_error, s.hearthis_meta_warning,
            s.hearthis_set_status, s.hearthis_set_id, s.uploaded_at, a.artist_name
     FROM dj_portal_sets s
     INNER JOIN dj_portal_accounts a ON a.id = s.account_id
     ORDER BY s.uploaded_at DESC, s.id DESC"
);
$receivedSets = $receivedSetsStmt->fetchAll(PDO::FETCH_ASSOC);
$setStatusLabels = [
    'received' => 'RECEIVED',
    'checked' => 'CHECKED',
    'scheduled' => 'SCHEDULED',
    'needs_changes' => 'NEEDS CHANGES',
    'broadcasted' => 'BROADCASTED',
];
$setStatusCounts = array_fill_keys(deseo_mylive_set_statuses(), 0);
foreach ($receivedSets as $receivedSet) {
    $setStatus = (string)$receivedSet['status'];
    if (isset($setStatusCounts[$setStatus])) {
        $setStatusCounts[$setStatus]++;
    }
}

$activeAccountCount = count(array_filter(
    $managedAccounts,
    static fn(array $account): bool => (string)($account['account_status'] ?? '') === 'active' && !empty($account['is_active'])
));
$disabledAccountCount = count($managedAccounts) - $activeAccountCount;
$totalSetCount = count($receivedSets);
$totalAssetCount = array_sum(array_map(static fn(array $account): int => (int)$account['asset_count'], $managedAccounts));
$noAssetAccountCount = count(array_filter(
    $managedAccounts,
    static fn(array $account): bool => (int)($account['asset_count'] ?? 0) === 0
));
$publicProfileBulkAccounts = array_values(array_filter(
    $managedAccounts,
    static fn(array $account): bool =>
        (string)($account['account_status'] ?? '') === 'active'
        && !empty($account['is_active'])
));
$publicProfileEnabledCount = count(array_filter(
    $publicProfileBulkAccounts,
    static fn(array $account): bool => !empty($account['public_profile_enabled'])
));
$allPublicProfilesEnabled = count($publicProfileBulkAccounts) > 0
    && $publicProfileEnabledCount === count($publicProfileBulkAccounts);
$bulkPublicProfileTarget = $allPublicProfilesEnabled ? 0 : 1;

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
            <p>Control center για γρήγορη διαχείριση των DJs, approvals, assets και DJ Sets χωρίς endless scrolling.</p>
        </div>
        <div class="mylive-hub-heading-actions">
            <?php if ($publicProfileBulkAccounts): ?>
                <form method="post"
                      data-deseo-confirm="<?= $bulkPublicProfileTarget
                          ? admin_e('Να ενεργοποιηθεί το Public Profile σε όλους τους ' . count($publicProfileBulkAccounts) . ' ενεργούς DJs; Θα σταλεί ενημερωτικό email μόνο σε όσους ενεργοποιούνται τώρα.')
                          : admin_e('Να απενεργοποιηθεί το Public Profile σε όλους τους ' . count($publicProfileBulkAccounts) . ' ενεργούς DJs; Τα bios, drafts και social links θα διατηρηθούν.') ?>"
                      data-deseo-confirm-title="<?= $bulkPublicProfileTarget ? 'Enable all Public Profiles' : 'Disable all Public Profiles' ?>"
                      data-deseo-confirm-label="<?= $bulkPublicProfileTarget ? 'Enable all' : 'Disable all' ?>"
                      <?= $bulkPublicProfileTarget ? '' : 'data-deseo-confirm-danger' ?>>
                    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                    <input type="hidden" name="action" value="bulk_toggle_public_profiles">
                    <input type="hidden" name="enabled" value="<?= $bulkPublicProfileTarget ?>">
                    <button class="button <?= $bulkPublicProfileTarget ? 'button-primary' : 'button-danger' ?>" type="submit">
                        <?= $bulkPublicProfileTarget ? 'Enable All Public Profiles' : 'Disable All Public Profiles' ?>
                    </button>
                </form>
            <?php endif; ?>
            <a class="button button-secondary" href="/mylive/" target="_blank" rel="noopener">Open MyLive</a>
        </div>
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
        <div class="mylive-hub-stat is-set-scheduled">
            <span>SCHEDULED SETS</span>
            <strong><?= $setStatusCounts['scheduled'] ?></strong>
            <small>προγραμματισμένα episodes</small>
        </div>
        <div class="mylive-hub-stat is-set-broadcasted">
            <span>BROADCASTED SETS</span>
            <strong><?= $setStatusCounts['broadcasted'] ?></strong>
            <small>ολοκληρωμένες μεταδόσεις</small>
        </div>
        <div class="mylive-hub-stat">
            <span>ASSETS</span>
            <strong><?= $totalAssetCount ?></strong>
            <small>artwork & imaging</small>
        </div>
        <div class="mylive-hub-stat <?= $noAssetAccountCount > 0 ? 'is-attention' : '' ?>">
            <span>NO ASSETS</span>
            <strong><?= $noAssetAccountCount ?></strong>
            <small>χρειάζονται υλικό</small>
        </div>
        <div class="mylive-hub-stat">
            <span>PUBLIC PROFILES</span>
            <strong><?= $publicProfileEnabledCount ?></strong>
            <small>ενεργά profiles</small>
        </div>
    </section>

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
                <h2>DJ Control Center</h2>
                <p>Επίλεξε DJ από αριστερά και διαχειρίσου assets, episodes και account χωρίς να ψάχνεις μέσα σε 30 κάρτες.</p>
            </div>
            <strong><?= count($managedAccounts) ?></strong>
        </div>

        <div class="mylive-management-shell">
            <aside class="mylive-roster-panel">
                <div class="mylive-toolbar mylive-toolbar-roster">
                    <label class="mylive-search">
                        <span>FIND DJ</span>
                        <input id="myliveAccountSearch" type="search" placeholder="Artist, email ή slot…" autocomplete="off">
                    </label>
                    <div class="mylive-filters" role="group" aria-label="Filter MyLive accounts">
                        <button type="button" class="is-active" data-mylive-filter="all">All <b><?= count($managedAccounts) ?></b></button>
                        <button type="button" data-mylive-filter="active">Active <b><?= $activeAccountCount ?></b></button>
                        <button type="button" data-mylive-filter="no-assets">No Assets <b><?= $noAssetAccountCount ?></b></button>
                        <button type="button" data-mylive-filter="disabled">Disabled <b><?= $disabledAccountCount ?></b></button>
                    </div>
                </div>

                <div class="mylive-roster" id="myliveRoster">
                    <?php foreach ($managedAccounts as $rosterAccount): ?>
                        <?php
                        $rosterId = (int)$rosterAccount['id'];
                        $rosterSlot = deseo_mylive_slot($rosterAccount);
                        $rosterStatus = (string)($rosterAccount['account_status'] ?? (!empty($rosterAccount['is_active']) ? 'active' : 'disabled'));
                        $rosterAssets = (int)($rosterAccount['asset_count'] ?? 0);
                        $rosterSets = (int)($rosterAccount['set_count'] ?? 0);
                        $rosterSearch = strtolower(trim(
                            (string)$rosterAccount['artist_name'] . ' ' .
                            (string)$rosterAccount['full_name'] . ' ' .
                            (string)$rosterAccount['email'] . ' ' .
                            $rosterSlot
                        ));
                        ?>
                        <button type="button"
                                class="mylive-roster-item"
                                data-account-select="<?= $rosterId ?>"
                                data-account-status="<?= admin_e($rosterStatus) ?>"
                                data-account-assets="<?= $rosterAssets ?>"
                                data-account-search="<?= admin_e($rosterSearch) ?>">
                            <?php if (!empty($rosterAccount['application_photo'])): ?>
                                <img src="<?= admin_e((string)$rosterAccount['application_photo']) ?>" alt="">
                            <?php else: ?>
                                <span class="mylive-roster-avatar"><?= admin_e(strtoupper(substr((string)$rosterAccount['artist_name'], 0, 1))) ?></span>
                            <?php endif; ?>

                            <span class="mylive-roster-copy">
                                <strong><?= admin_e((string)$rosterAccount['artist_name']) ?></strong>
                                <small><?= admin_e($rosterSlot) ?></small>
                                <em><?= admin_e((string)$rosterAccount['email']) ?></em>
                            </span>

                            <span class="mylive-roster-metrics">
                                <b class="<?= $rosterAssets === 0 ? 'is-empty' : '' ?>"><?= $rosterAssets ?> assets</b>
                                <b><?= $rosterSets ?> sets</b>
                                <i class="<?= $rosterStatus === 'active' ? 'is-active' : 'is-disabled' ?>" aria-label="<?= admin_e($rosterStatus) ?>"></i>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="mylive-roster-empty" id="myliveRosterEmpty" hidden>Δεν βρέθηκε DJ με αυτά τα φίλτρα.</div>
            </aside>

            <div class="mylive-workspace">
                <div class="mylive-workspace-placeholder" id="myliveWorkspacePlaceholder">
                    <span>DJ WORKSPACE</span>
                    <strong>Επίλεξε έναν DJ</strong>
                    <p>Θα εμφανιστούν εδώ τα assets, τα DJ Sets και οι ρυθμίσεις του account.</p>
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
                    data-account-id="<?= $accountId ?>"
                    data-account-status="<?= admin_e($accountStatus) ?>"
                    data-account-assets="<?= (int)$account['asset_count'] ?>"
                    data-account-search="<?= admin_e($accountSearch) ?>"
                    hidden
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

                    <div class="mylive-account-quicknav">
                        <button type="button" class="is-primary" data-mylive-open-detail="assets">+ Add / Manage Assets</button>
                        <button type="button" data-mylive-open-detail="episodes">DJ Sets</button>
                        <button type="button" data-mylive-open-detail="account">Account</button>
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
                        <details class="mylive-v3-detail" data-mylive-detail="account">
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

                        <details class="mylive-v3-detail" data-mylive-detail="episodes">
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
                                              data-deseo-confirm="Να καταχωριστεί ως BROADCASTED; Το MP3 παραμένει στον server έως ότου επιβεβαιωθεί το HearThis URL." data-deseo-confirm-title="BROADCASTED · HearThis Sync" data-deseo-confirm-label="BROADCASTED" data-deseo-confirm-if-status="broadcasted"
                                              <?= !empty($set['file_deleted_at']) ? 'data-file-removed="1"' : '' ?>>
                                            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update_set">
                                            <input type="hidden" name="set_id" value="<?= (int)$set['id'] ?>">
                                            <div class="mylive-set-copy">
                                                <span>EP<?= str_pad((string)(int)$set['episode_no'], 3, '0', STR_PAD_LEFT) ?></span>
                                                <strong><?= admin_e($set['stored_name']) ?></strong>
                                                <small><?= admin_e(deseo_mylive_format_bytes((int)$set['file_size'])) ?> · <?= admin_e((string)$set['uploaded_at']) ?></small>
                                                <small>HEARTHIS: <?= admin_e(strtoupper(str_replace('_', ' ', (string)($set['hearthis_status'] ?? 'pending')))) ?><?php if (!empty($set['scheduled_show_end'])): ?> · <?= admin_e((string)$set['scheduled_show_end']) ?> (Athens)<?php endif; ?></small>
                                                <?php if (!empty($set['hearthis_error'])): ?><small title="<?= admin_e((string)$set['hearthis_error']) ?>">Review: <?= admin_e((string)$set['hearthis_error']) ?></small><?php endif; ?>
                                                <small>SEASON 6 SET: <?= admin_e(strtoupper(str_replace('_', ' ', (string)($set['hearthis_set_status'] ?? 'pending')))) ?></small>
                                                <?php if (!empty($set['hearthis_meta_warning'])): ?><small title="<?= admin_e((string)$set['hearthis_meta_warning']) ?>">HearThis optional metadata warning: <?= admin_e((string)$set['hearthis_meta_warning']) ?></small><?php endif; ?>
                                                <?php if (deseo_hearthis_public_url((string)($set['hearthis_url'] ?? ''))): ?><a href="<?= admin_e((string)$set['hearthis_url']) ?>" target="_blank" rel="noopener noreferrer">Open HearThis episode</a><?php endif; ?>
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
                                            <label>SHOW ENDS · ATHENS
                                                <input type="datetime-local" name="scheduled_show_end" value="<?= admin_e(!empty($set['scheduled_show_end']) ? str_replace(' ', 'T', substr((string)$set['scheduled_show_end'], 0, 16)) : '') ?>" aria-label="Show end date and time in Athens">
                                            </label>

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

                        <details class="mylive-v3-detail" data-mylive-detail="assets" open>
                            <summary>
                                <div><span>DESEO / ILUMA</span><strong>Assets</strong></div>
                                <small><?= count($assetsByAccount[$accountId]) ?> available · upload / assign</small>
                                <b>+</b>
                            </summary>
                            <div class="mylive-v3-detail-body">
                                <div class="mylive-hearthis-cover" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:12px;border:1px solid #38383c;border-radius:8px;margin-bottom:14px;">
                                    <?php $djCover = $hearthisCoverByAccount[$accountId] ?? null; ?>
                                    <?php if ($djCover !== null): ?>
                                        <img src="<?= admin_e((string)$djCover['preview_url']) ?>" loading="lazy" width="86" height="86" alt="<?= admin_e((string)$account['artist_name']) ?> · HearThis artwork 02" style="width:86px;height:86px;aspect-ratio:1;object-fit:cover;border-radius:6px;">
                                        <div style="min-width:0;flex:1">
                                            <strong>HEARTHIS ARTWORK 02 · READY</strong>
                                            <small style="display:block;overflow-wrap:anywhere;"><?= admin_e((string)($djCover['source_path'] ?: $djCover['name'])) ?></small>
                                            <small style="display:block;">This DJ-specific square image will be used automatically for future HearThis DJ Sets.</small>
                                        </div>
                                    <?php else: ?>
                                        <div>
                                            <strong>HEARTHIS ARTWORK · DEFAULT FALLBACK</strong>
                                            <small style="display:block;overflow-wrap:anywhere;"><?= admin_e((string)($hearthisCoverErrorByAccount[$accountId] ?? 'Square DJ image 02 is unavailable.')) ?></small>
                                            <small style="display:block;">DJ Set upload continues without custom artwork. HearThis may use embedded MP3 ID3 artwork or its account/platform default. You can optionally assign square artwork 02 below.</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <form method="post" enctype="multipart/form-data" class="mylive-asset-upload mylive-v3-asset-upload">
                                    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="upload_asset">
                                    <input type="hidden" name="account_id" value="<?= $accountId ?>">

                                    <select name="asset_type">
                                        <option value="artwork">Promotional Artwork · 01</option>
                                        <option value="hearthis_cover">HearThis Square Cover · 02</option>
                                        <option value="dj_spot">Personal DJ Imaging</option>
                                        <option value="dj_spot_30">30' Imaging</option>
                                        <option value="other">Additional Asset</option>
                                    </select>
                                    <input type="text" name="title" placeholder="Optional custom title">
                                    <input type="hidden" name="server_asset_path" value="" data-mylive-server-asset-path>
                                    <input class="file-input" type="file" name="asset_file" accept=".jpg,.jpeg,.png,.webp,.pdf,.mp3,.wav">
                                    <button class="button button-secondary" type="button" data-mylive-asset-browser>Browse server / File Manager</button>
                                    <small data-mylive-server-asset-label style="grid-column:1/-1;color:#66666b;font-size:8px;line-height:1.45;">Η εικόνα 02 αναζητείται αυτόματα στον φάκελο ημέρας/DJ του File Manager, δίπλα στο καταχωρισμένο 01. Αν δεν βρεθεί, το DJ Set συνεχίζει χωρίς custom artwork (το HearThis μπορεί να χρησιμοποιήσει ID3 embedded artwork ή προεπιλεγμένη εικόνα). Μπορείς προαιρετικά να επιλέξεις χειροκίνητα το square artwork 02 εδώ.</small>
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
            </div>
        </div>
    </section>


    <section class="panel mylive-library" aria-labelledby="myliveLibraryTitle">
        <div class="mylive-library-heading">
            <div>
                <span>DJ DELIVERY / ALL EPISODES</span>
                <h2 id="myliveLibraryTitle">DJ Sets received</h2>
                <p>Όλα τα DJ Sets που έχουν παραληφθεί έως σήμερα, με το τρέχον status και πρόσβαση στο διαθέσιμο αρχείο.</p>
            </div>
            <div class="mylive-library-total">
                <strong><?= $totalSetCount ?></strong>
                <small>TOTAL UPLOADS</small>
            </div>
        </div>

        <?php if (!$receivedSets): ?>
            <div class="mylive-library-empty">Δεν έχουμε λάβει ακόμη κάποιο DJ Set.</div>
        <?php else: ?>
            <div class="mylive-library-toolbar">
                <label class="mylive-library-search">
                    <span>SEARCH DJ SETS</span>
                    <input id="myliveLibrarySearch" type="search" placeholder="DJ, episode ή filename…" autocomplete="off">
                </label>
                <div class="mylive-library-filters" role="group" aria-label="Filter DJ Sets by status">
                    <button type="button" class="is-active" data-mylive-library-filter="all">All <b><?= $totalSetCount ?></b></button>
                    <?php foreach ($setStatusLabels as $status => $label): ?>
                        <button type="button" data-mylive-library-filter="<?= admin_e($status) ?>"><?= admin_e($label) ?> <b><?= $setStatusCounts[$status] ?></b></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mylive-library-results" aria-live="polite">
                <span>SHOWING <b id="myliveLibraryVisibleCount"><?= $totalSetCount ?></b> OF <?= $totalSetCount ?> DJ SETS</span>
                <span>NEWEST FIRST</span>
            </div>
            <div class="mylive-library-list" id="myliveLibraryList">
                <?php foreach ($receivedSets as $set): ?>
                    <?php
                    $setStatus = (string)$set['status'];
                    $statusClass = isset($setStatusLabels[$setStatus]) ? $setStatus : 'unknown';
                    $episodeLabel = 'EP' . str_pad((string)(int)$set['episode_no'], 3, '0', STR_PAD_LEFT);
                    ?>
                    <form method="post" class="mylive-library-row"
                          data-mylive-library-status="<?= admin_e($setStatus) ?>"
                          data-mylive-library-search="<?= admin_e((string)$set['artist_name'] . ' ' . $episodeLabel . ' ' . (string)$set['stored_name']) ?>"
                          data-deseo-confirm="Να καταχωριστεί ως BROADCASTED; Το MP3 παραμένει μέχρι να αποθηκευτεί επιβεβαιωμένο HearThis URL."
                          data-deseo-confirm-title="BROADCASTED · HearThis Sync"
                          data-deseo-confirm-label="BROADCASTED"
                          data-deseo-confirm-if-status="broadcasted"
                          <?= !empty($set['file_deleted_at']) ? 'data-file-removed="1"' : '' ?>>
                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_set_from_library">
                        <input type="hidden" name="set_id" value="<?= (int)$set['id'] ?>">
                        <div class="mylive-library-dj">
                            <span><?= admin_e($episodeLabel) ?></span>
                            <strong><?= admin_e((string)$set['artist_name']) ?></strong>
                        </div>
                        <div class="mylive-library-file">
                            <strong title="<?= admin_e((string)$set['stored_name']) ?>"><?= admin_e((string)$set['stored_name']) ?></strong>
                            <small><?= admin_e(deseo_mylive_format_bytes((int)$set['file_size'])) ?> · <?= admin_e(date('d.m.Y · H:i', strtotime((string)$set['uploaded_at']))) ?></small>
                            <small title="<?= admin_e((string)($set['hearthis_error'] ?? '')) ?>">HEARTHIS: <?= admin_e(strtoupper(str_replace('_', ' ', (string)($set['hearthis_status'] ?? 'pending')))) ?><?php if (!empty($set['scheduled_show_end'])): ?> · <?= admin_e((string)$set['scheduled_show_end']) ?> (Athens)<?php endif; ?></small>
                            <small>SEASON 6 SET: <?= admin_e(strtoupper(str_replace('_', ' ', (string)($set['hearthis_set_status'] ?? 'pending')))) ?></small>
                            <?php if (!empty($set['hearthis_meta_warning'])): ?><small title="<?= admin_e((string)$set['hearthis_meta_warning']) ?>">Metadata warning: <?= admin_e((string)$set['hearthis_meta_warning']) ?></small><?php endif; ?>
                            <?php if (deseo_hearthis_public_url((string)($set['hearthis_url'] ?? ''))): ?><a href="<?= admin_e((string)$set['hearthis_url']) ?>" target="_blank" rel="noopener noreferrer">Open HearThis episode</a><?php endif; ?>
                        </div>
                        <div class="mylive-library-status">
                            <span class="mylive-library-badge is-status-<?= admin_e($statusClass) ?>"><?= admin_e($setStatusLabels[$setStatus] ?? strtoupper(str_replace('_', ' ', $setStatus))) ?></span>
                            <select name="status" aria-label="Αλλαγή status για <?= admin_e($episodeLabel . ' · ' . (string)$set['artist_name']) ?>">
                                <?php foreach (deseo_mylive_set_statuses() as $statusChoice): ?>
                                    <option value="<?= admin_e($statusChoice) ?>" <?= $setStatus === $statusChoice ? 'selected' : '' ?>><?= admin_e($setStatusLabels[$statusChoice] ?? strtoupper(str_replace('_', ' ', $statusChoice))) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>SHOW ENDS · ATHENS
                                <input type="datetime-local" name="scheduled_show_end" value="<?= admin_e(!empty($set['scheduled_show_end']) ? str_replace(' ', 'T', substr((string)$set['scheduled_show_end'], 0, 16)) : '') ?>" aria-label="Show end date and time in Athens">
                            </label>
                        </div>
                        <div class="mylive-library-action">
                            <button class="button button-primary" type="submit">Save</button>
                            <?php if (!empty($set['file_deleted_at'])): ?>
                                <span class="mylive-library-removed" title="Το episode παραμένει στο ιστορικό, αλλά το audio έχει διαγραφεί από τον server.">FILE REMOVED</span>
                            <?php else: ?>
                                <a class="button button-secondary" href="mylive-download.php?type=set&amp;id=<?= (int)$set['id'] ?>" aria-label="Download <?= admin_e($episodeLabel . ' · ' . (string)$set['artist_name']) ?>">Download</a>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>
            <div class="mylive-library-empty" id="myliveLibraryNoResults" hidden>Δεν βρέθηκαν DJ Sets με αυτά τα φίλτρα.</div>
        <?php endif; ?>
    </section>

    <?php if ($pendingAccounts): ?>
        <section class="panel mylive-pending-panel mylive-v3-pending">
            <div class="mylive-panel-head">
                <div>
                    <span>APPROVAL QUEUE</span>
                    <h2>DJs waiting for MyLive access</h2>
                    <p>Έγκρινε γρήγορα τα pending accounts. Δημιουργείται temporary password και στέλνεται αυτόματα το onboarding email.</p>
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
                                    <?php if (!empty($pending['application_instagram'])): ?><a href="<?= admin_e($pending['application_instagram']) ?>" target="_blank" rel="noopener">Social</a><?php endif; ?>
                                    <?php if (!empty($pending['application_website'])): ?><a href="<?= admin_e($pending['application_website']) ?>" target="_blank" rel="noopener">Website</a><?php endif; ?>
                                    <?php if (!empty($pending['application_work_sample'])): ?><a href="<?= admin_e($pending['application_work_sample']) ?>" target="_blank" rel="noopener noreferrer">Work sample</a><?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <form method="post" class="mylive-pending-approve" data-deseo-confirm="Να ενεργοποιηθεί το MyLive για <?= admin_e($pending['artist_name']) ?> και να σταλεί το onboarding email;" data-deseo-confirm-title="Ενεργοποίηση MyLive" data-deseo-confirm-label="Ενεργοποίηση">
                            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                            <input type="hidden" name="action" value="approve_pending">
                            <input type="hidden" name="account_id" value="<?= (int)$pending['id'] ?>">
                            <button class="button button-primary" type="submit">Approve & Send Access</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
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
                        <span><?= admin_e(mylive_admin_asset_media_folder_display($folder)) ?></span><b><?= $folderCount ?></b>
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
                                <small><?= admin_e(mylive_admin_asset_media_folder_display((string)$media['folder'])) ?> · <?= admin_e(deseo_mylive_format_bytes((int)$media['size'])) ?></small>
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
    var roster=document.getElementById('myliveRoster');
    var rosterEmpty=document.getElementById('myliveRosterEmpty');
    var placeholder=document.getElementById('myliveWorkspacePlaceholder');
    var buttons=Array.prototype.slice.call(document.querySelectorAll('[data-mylive-filter]'));
    if(!list||!roster)return;

    var filter='all';
    var rosterItems=Array.prototype.slice.call(roster.querySelectorAll('[data-account-select]'));
    var cards=Array.prototype.slice.call(list.querySelectorAll('[data-account-id]'));
    var selectedId='';
    try{
        selectedId=window.localStorage ? (localStorage.getItem('deseoMyliveAdminSelectedDj')||'') : '';
    }catch(e){
        selectedId='';
    }

    function cardFor(id){
        return cards.find(function(card){return card.getAttribute('data-account-id')===String(id);})||null;
    }

    function rosterFor(id){
        return rosterItems.find(function(item){return item.getAttribute('data-account-select')===String(id);})||null;
    }

    function selectAccount(id,openSection){
        var item=rosterFor(id);
        if(!item||item.hidden)return;

        selectedId=String(id);
        if(window.localStorage){
            try{localStorage.setItem('deseoMyliveAdminSelectedDj',selectedId);}catch(e){}
        }

        rosterItems.forEach(function(row){
            row.classList.toggle('is-selected',row===item);
        });

        cards.forEach(function(card){
            card.hidden=card.getAttribute('data-account-id')!==selectedId;
        });

        if(placeholder)placeholder.hidden=true;

        var selectedCard=cardFor(selectedId);
        if(openSection&&selectedCard){
            var detail=selectedCard.querySelector('[data-mylive-detail="'+openSection+'"]');
            if(detail){
                selectedCard.querySelectorAll('[data-mylive-detail]').forEach(function(other){
                    other.open=other===detail;
                });
                detail.open=true;
                window.setTimeout(function(){
                    detail.scrollIntoView({behavior:'smooth',block:'nearest'});
                },40);
            }
        }
    }

    function matches(item){
        var q=search ? search.value.trim().toLowerCase() : '';
        var status=(item.getAttribute('data-account-status')||'').toLowerCase();
        var assets=parseInt(item.getAttribute('data-account-assets')||'0',10);
        var haystack=(item.getAttribute('data-account-search')||'').toLowerCase();

        var filterMatch=
            filter==='all'
            || (filter==='active'&&status==='active')
            || (filter==='disabled'&&status==='disabled')
            || (filter==='no-assets'&&assets===0);

        return filterMatch&&(!q||haystack.indexOf(q)!==-1);
    }

    function apply(){
        var visibleItems=[];
        rosterItems.forEach(function(item){
            var show=matches(item);
            item.hidden=!show;
            if(show)visibleItems.push(item);
        });

        if(rosterEmpty)rosterEmpty.hidden=visibleItems.length!==0;

        var selected=rosterFor(selectedId);
        if(!selected||selected.hidden){
            if(visibleItems.length){
                selectAccount(visibleItems[0].getAttribute('data-account-select'));
            }else{
                selectedId='';
                cards.forEach(function(card){card.hidden=true;});
                rosterItems.forEach(function(item){item.classList.remove('is-selected');});
                if(placeholder)placeholder.hidden=false;
            }
        }
    }

    rosterItems.forEach(function(item){
        item.addEventListener('click',function(){
            selectAccount(item.getAttribute('data-account-select'));
        });
    });

    list.addEventListener('click',function(event){
        var quick=event.target.closest('[data-mylive-open-detail]');
        if(!quick)return;
        var card=quick.closest('[data-account-id]');
        if(!card)return;
        selectAccount(card.getAttribute('data-account-id'),quick.getAttribute('data-mylive-open-detail'));
    });

    buttons.forEach(function(button){
        button.addEventListener('click',function(){
            filter=button.getAttribute('data-mylive-filter')||'all';
            buttons.forEach(function(item){item.classList.toggle('is-active',item===button);});
            apply();
        });
    });

    if(search)search.addEventListener('input',apply);

    apply();
    if(!selectedId){
        var first=rosterItems.find(function(item){return !item.hidden;});
        if(first)selectAccount(first.getAttribute('data-account-select'));
    }else{
        var saved=rosterFor(selectedId);
        if(saved&&!saved.hidden)selectAccount(selectedId);
    }
}());

(function(){
    var list=document.getElementById('myliveLibraryList');
    if(!list)return;

    var search=document.getElementById('myliveLibrarySearch');
    var empty=document.getElementById('myliveLibraryNoResults');
    var visibleCount=document.getElementById('myliveLibraryVisibleCount');
    var rows=Array.prototype.slice.call(list.querySelectorAll('[data-mylive-library-status]'));
    var filters=Array.prototype.slice.call(document.querySelectorAll('[data-mylive-library-filter]'));
    var selected='all';

    function apply(){
        var query=search?search.value.trim().toLocaleLowerCase():'';
        var count=0;
        rows.forEach(function(row){
            var status=row.getAttribute('data-mylive-library-status')||'';
            var haystack=(row.getAttribute('data-mylive-library-search')||'').toLocaleLowerCase();
            var show=(selected==='all'||status===selected)&&(!query||haystack.indexOf(query)!==-1);
            row.hidden=!show;
            if(show)count++;
        });
        if(visibleCount)visibleCount.textContent=String(count);
        if(empty)empty.hidden=count!==0;
    }

    filters.forEach(function(button){
        button.addEventListener('click',function(){
            selected=button.getAttribute('data-mylive-library-filter')||'all';
            filters.forEach(function(item){item.classList.toggle('is-active',item===button);});
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
