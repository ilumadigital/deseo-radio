<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/admin-ui.php';

deseo_mylive_bootstrap($pdo);

$profileAccountsStmt = $pdo->query(
    "SELECT id, artist_name, email
     FROM dj_portal_accounts
     WHERE is_active = 1
       AND account_status = 'active'
       AND public_profile_enabled = 1
     ORDER BY artist_name ASC, id ASC"
);
$profileAccounts = $profileAccountsStmt->fetchAll(PDO::FETCH_ASSOC);
$profileAccountIds = array_fill_keys(
    array_map(static fn(array $row): int => (int)$row['id'], $profileAccounts),
    true
);

$days = [1=>'Δευτέρα',2=>'Τρίτη',3=>'Τετάρτη',4=>'Πέμπτη',5=>'Παρασκευή',6=>'Σάββατο',7=>'Κυριακή'];
$dayShort = [1=>'ΔΕΥ',2=>'ΤΡΙ',3=>'ΤΕΤ',4=>'ΠΕΜ',5=>'ΠΑΡ',6=>'ΣΑΒ',7=>'ΚΥΡ'];
$success = null;
$error = null;

$requestedDay = (int)($_POST['day'] ?? $_GET['day'] ?? date('N'));
$selectedDay = ($requestedDay >= 1 && $requestedDay <= 7) ? $requestedDay : (int)date('N');

function valid_time(string $time): bool {
    $d = DateTime::createFromFormat('H:i', $time);
    return $d && $d->format('H:i') === $time;
}

function program_time_to_minutes(string $time): int {
    $parts = array_map('intval', array_slice(explode(':', $time), 0, 2));
    return (($parts[0] ?? 0) * 60) + ($parts[1] ?? 0);
}

function program_segments(string $start, string $end): array {
    $startMinutes = program_time_to_minutes($start);
    $endMinutes = program_time_to_minutes($end);

    if ($endMinutes > $startMinutes) {
        return [[$startMinutes, $endMinutes]];
    }

    return [[$startMinutes, 1440], [0, $endMinutes]];
}

function program_intervals_overlap(string $startA, string $endA, string $startB, string $endB): bool {
    foreach (program_segments($startA, $endA) as [$aStart, $aEnd]) {
        foreach (program_segments($startB, $endB) as [$bStart, $bEnd]) {
            if (max($aStart, $bStart) < min($aEnd, $bEnd)) {
                return true;
            }
        }
    }
    return false;
}

function program_collect_photos_for_ids(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT photo_path FROM program WHERE id IN ($placeholders)");
    $stmt->execute($ids);

    return array_values(array_filter(array_map(
        static fn(array $row): string => (string)($row['photo_path'] ?? ''),
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    )));
}

function program_delete_ids(PDO $pdo, array $ids): void {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM program WHERE id IN ($placeholders)");
    $stmt->execute($ids);
}


function program_normalize_photo_reference(string $value): string {
    $value = trim($value);
    if ($value === '') return '';

    if (strlen($value) > 1000) {
        throw new RuntimeException('Το image link είναι πολύ μεγάλο.');
    }

    if (preg_match('~^https?://~i', $value)) {
        $url = filter_var($value, FILTER_VALIDATE_URL);
        if (!$url) {
            throw new RuntimeException('Το image link δεν είναι έγκυρο.');
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Το image link πρέπει να χρησιμοποιεί http ή https.');
        }

        return $url;
    }

    if (!str_starts_with($value, '/')) {
        throw new RuntimeException('Το image link πρέπει να είναι πλήρες URL ή path του site που ξεκινά με /.');
    }

    if (str_contains($value, '..') || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        throw new RuntimeException('Το image path δεν είναι έγκυρο.');
    }

    $pathOnly = (string)(parse_url($value, PHP_URL_PATH) ?? '');
    if (!preg_match('~\.(?:jpe?g|png|webp)$~i', $pathOnly)) {
        throw new RuntimeException('Το local image path πρέπει να είναι JPG, JPEG, PNG ή WEBP.');
    }

    return $value;
}

function program_media_folder_label(string $rootLabel, string $relativeFolder): string {
    if ($relativeFolder === '.' || $relativeFolder === '') {
        return $rootLabel;
    }

    $parts = explode('/', str_replace('\\', '/', $relativeFolder));
    $mapped = [];

    foreach ($parts as $part) {
        $key = strtolower(trim($part));
        $mapped[] = match ($key) {
            'deseo_auto' => 'Deseo Auto',
            'deseo_djs' => 'deseo djs',
            default => str_replace('_', ' ', $part),
        };
    }

    // For the two dedicated Deseo media folders, show them as top-level
    // categories instead of prefixing them with "Uploads".
    $first = strtolower(trim($parts[0] ?? ''));
    if (in_array($first, ['deseo_auto', 'deseo_djs'], true)) {
        return implode(' / ', $mapped);
    }

    return $rootLabel . ' / ' . implode(' / ', $mapped);
}

function program_media_library_collect(string $absoluteRoot, string $publicBase, string $rootLabel): array {
    $rootReal = realpath($absoluteRoot);
    if (!$rootReal || !is_dir($rootReal)) return [];

    $items = [];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) continue;

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;

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

            $relativeFolder = dirname($relative);
            $folder = program_media_folder_label($rootLabel, $relativeFolder);

            $items[] = [
                'url' => $url,
                'name' => basename($relative),
                'folder' => $folder,
                'mtime' => (int)$file->getMTime(),
                'size' => (int)$file->getSize(),
            ];
        }
    } catch (Throwable $mediaScanError) {
        error_log('Program media browser scan failed for ' . $rootLabel . ': ' . $mediaScanError->getMessage());
        return [];
    }

    return $items;
}

function program_media_library(): array {
    $items = array_merge(
        program_media_library_collect(__DIR__ . '/uploads', '/iluma/uploads', 'Uploads'),
        program_media_library_collect(dirname(__DIR__) . '/assets/img', '/assets/img', 'Site Assets')
    );

    usort($items, static function(array $a, array $b): int {
        $timeCompare = ((int)$b['mtime']) <=> ((int)$a['mtime']);
        return $timeCompare !== 0
            ? $timeCompare
            : strcasecmp((string)$a['name'], (string)$b['name']);
    });

    return array_slice($items, 0, 500);
}

function program_cleanup_photos(PDO $pdo, array $paths): void {
    foreach (array_values(array_unique(array_filter($paths))) as $path) {
        $path = (string)$path;

        // Only files generated by this Program editor are eligible for automatic cleanup.
        // Existing images chosen through the Media Browser must never be deleted here.
        if (!preg_match('~^/iluma/uploads/(dj_[a-f0-9]{20}\.(?:jpe?g|png|webp))$~i', $path, $match)) {
            continue;
        }

        $check = $pdo->prepare("SELECT COUNT(*) FROM program WHERE photo_path = ?");
        $check->execute([$path]);
        if ((int)$check->fetchColumn() !== 0) continue;

        $file = __DIR__ . '/uploads/' . $match[1];
        if (is_file($file)) @unlink($file);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Η συνεδρία έληξε. Ανανεώστε τη σελίδα.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id) {
                $photos = program_collect_photos_for_ids($pdo, [(int)$id]);
                $pdo->beginTransaction();
                try {
                    program_delete_ids($pdo, [(int)$id]);
                    $pdo->commit();
                    program_cleanup_photos($pdo, $photos);
                    $success = 'Η εκπομπή διαγράφηκε.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Program delete failed: ' . $e->getMessage());
                    $error = 'Δεν ήταν δυνατή η διαγραφή.';
                }
            }
        }

        if ($action === 'delete_show_week') {
            $showName = trim((string)($_POST['show_name'] ?? ''));

            if ($showName === '') {
                $error = 'Δεν βρέθηκε έγκυρο όνομα εκπομπής.';
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id, photo_path
                     FROM program
                     WHERE LOWER(TRIM(dj_name)) = LOWER(TRIM(?))"
                );
                $stmt->execute([$showName]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$rows) {
                    $error = 'Δεν βρέθηκαν εμφανίσεις αυτής της εκπομπής.';
                } else {
                    $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
                    $photos = array_map(static fn(array $row): string => (string)$row['photo_path'], $rows);

                    $pdo->beginTransaction();
                    try {
                        program_delete_ids($pdo, $ids);
                        $pdo->commit();
                        program_cleanup_photos($pdo, $photos);

                        $deletedCount = count($ids);
                        $success = 'Διαγράφηκαν όλες οι εμφανίσεις του "' . $showName . '" από την εβδομάδα (' . $deletedCount . ' slots).';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('Program weekly show delete failed: ' . $e->getMessage());
                        $error = 'Δεν ήταν δυνατή η διαγραφή της εκπομπής από όλη την εβδομάδα.';
                    }
                }
            }
        }

        if ($action === 'delete_day') {
            $stmt = $pdo->prepare("SELECT id, photo_path FROM program WHERE day_of_week = ?");
            $stmt->execute([$selectedDay]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
            $photos = array_map(static fn(array $row): string => (string)$row['photo_path'], $rows);

            $pdo->beginTransaction();
            try {
                program_delete_ids($pdo, $ids);
                $pdo->commit();
                program_cleanup_photos($pdo, $photos);
                $success = 'Καθαρίστηκε όλο το πρόγραμμα της ' . $days[$selectedDay] . '.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Program day clear failed: ' . $e->getMessage());
                $error = 'Δεν ήταν δυνατός ο καθαρισμός της ημέρας.';
            }
        }

        if ($action === 'delete_all') {
            $photos = $pdo->query("SELECT photo_path FROM program WHERE photo_path <> ''")->fetchAll(PDO::FETCH_COLUMN);

            $pdo->beginTransaction();
            try {
                $pdo->exec("DELETE FROM program");
                $pdo->commit();
                program_cleanup_photos($pdo, $photos ?: []);
                $success = 'Διαγράφηκε όλο το πρόγραμμα.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Program clear all failed: ' . $e->getMessage());
                $error = 'Δεν ήταν δυνατή η διαγραφή όλου του προγράμματος.';
            }
        }

        if ($action === 'save') {
            $djName = trim((string)($_POST['dj_name'] ?? ''));
            $myliveAccountRaw = trim((string)($_POST['mylive_account_id'] ?? ''));
            $myliveAccountId = $myliveAccountRaw === ''
                ? null
                : filter_var($myliveAccountRaw, FILTER_VALIDATE_INT);
            $allDay = !empty($_POST['all_day']);
            $startTime = $allDay ? '00:00' : trim((string)($_POST['start_time'] ?? ''));
            $endTime = $allDay ? '23:59' : trim((string)($_POST['end_time'] ?? ''));
            $editIdRaw = trim((string)($_POST['edit_id'] ?? ''));
            $editId = $editIdRaw === '' ? null : filter_var($editIdRaw, FILTER_VALIDATE_INT);
            $postedDays = array_values(array_unique(array_filter(
                array_map('intval', (array)($_POST['days'] ?? [])),
                static fn(int $day): bool => $day >= 1 && $day <= 7
            )));
            sort($postedDays);
            $targetDays = $editId ? [$selectedDay] : $postedDays;
            $editRow = null;
            $uploadedFile = null;
            $photoReferenceRaw = trim((string)($_POST['photo_reference'] ?? ''));
            $photoPath = '';
            $oldEditPhoto = '';

            if ($djName === '' || mb_strlen($djName) > 255) {
                $error = 'Συμπληρώστε έγκυρο όνομα DJ / εκπομπής.';
            } elseif ($myliveAccountRaw !== '' && (!$myliveAccountId || !isset($profileAccountIds[(int)$myliveAccountId]))) {
                $error = 'Το επιλεγμένο MyLive DJ Profile δεν είναι διαθέσιμο.';
            } elseif (!$allDay && (!valid_time($startTime) || !valid_time($endTime))) {
                $error = 'Συμπληρώστε έγκυρες ώρες έναρξης και λήξης.';
            } elseif (!$allDay && $startTime === $endTime) {
                $error = 'Η ώρα έναρξης και λήξης δεν μπορεί να είναι ίδια.';
            } elseif ($editIdRaw !== '' && !$editId) {
                $error = 'Η εγγραφή προς επεξεργασία δεν είναι έγκυρη.';
            } elseif (!$editId && !$targetDays) {
                $error = 'Επιλέξτε τουλάχιστον μία ημέρα για τη νέα εκπομπή.';
            }

            if ($error === null && $editId) {
                $stmt = $pdo->prepare("SELECT * FROM program WHERE id = ? AND day_of_week = ?");
                $stmt->execute([(int)$editId, $selectedDay]);
                $editRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if (!$editRow) {
                    $error = 'Η εκπομπή που θέλετε να επεξεργαστείτε δεν βρέθηκε.';
                } else {
                    $photoPath = (string)($editRow['photo_path'] ?? '');
                    $oldEditPhoto = $photoPath;
                }
            }

            $hasPhotoUpload = isset($_FILES['photo'])
                && (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

            if ($error === null && $hasPhotoUpload) {
                if ((int)$_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Η φωτογραφία δεν ανέβηκε σωστά.';
                } elseif ((int)$_FILES['photo']['size'] > 5 * 1024 * 1024) {
                    $error = 'Η φωτογραφία πρέπει να είναι μικρότερη από 5MB.';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($_FILES['photo']['tmp_name']);
                    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];

                    if (!isset($allowed[$mime]) || @getimagesize($_FILES['photo']['tmp_name']) === false) {
                        $error = 'Επιτρέπονται μόνο πραγματικές JPG, PNG ή WEBP εικόνες.';
                    } else {
                        $uploadDir = __DIR__ . '/uploads';
                        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                            $error = 'Δεν ήταν δυνατή η δημιουργία του uploads folder.';
                        } else {
                            $filename = 'dj_' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
                            $uploadedFile = $uploadDir . '/' . $filename;

                            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadedFile)) {
                                $error = 'Η φωτογραφία δεν αποθηκεύτηκε. Ελέγξτε τα permissions του uploads folder.';
                            } else {
                                $photoPath = '/iluma/uploads/' . $filename;
                            }
                        }
                    }
                }
            } elseif ($error === null && $photoReferenceRaw !== '') {
                try {
                    $photoPath = program_normalize_photo_reference($photoReferenceRaw);
                } catch (RuntimeException $photoError) {
                    $error = $photoError->getMessage();
                }
            } elseif ($error === null && !$editRow) {
                $error = 'Δώσε image link, επίλεξε εικόνα από τον server ή ανέβασε νέα φωτογραφία.';
            }

            if ($error === null) {
                $existingStmt = $pdo->prepare(
                    "SELECT id, photo_path, start_time, end_time
                     FROM program
                     WHERE day_of_week = ?
                     ORDER BY start_time ASC, id ASC"
                );

                $conflictIds = [];
                $conflictPhotos = [];

                foreach ($targetDays as $targetDay) {
                    $existingStmt->execute([$targetDay]);
                    $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($existingRows as $row) {
                        if ($editId && $targetDay === $selectedDay && (int)$row['id'] === (int)$editId) continue;

                        if (program_intervals_overlap(
                            $startTime,
                            $endTime,
                            (string)$row['start_time'],
                            (string)$row['end_time']
                        )) {
                            $conflictIds[] = (int)$row['id'];
                            $conflictPhotos[] = (string)$row['photo_path'];
                        }
                    }
                }

                $pdo->beginTransaction();
                try {
                    program_delete_ids($pdo, $conflictIds);

                    if ($editRow) {
                        $stmt = $pdo->prepare(
                            "UPDATE program
                             SET dj_name = ?, photo_path = ?, mylive_account_id = ?, start_time = ?, end_time = ?
                             WHERE id = ? AND day_of_week = ?"
                        );
                        $stmt->execute([
                            $djName,
                            $photoPath,
                            $myliveAccountId ? (int)$myliveAccountId : null,
                            $startTime . ':00',
                            $endTime . ':00',
                            (int)$editId,
                            $selectedDay
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO program (dj_name, photo_path, mylive_account_id, day_of_week, start_time, end_time)
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        foreach ($targetDays as $targetDay) {
                            $stmt->execute([
                                $djName,
                                $photoPath,
                                $myliveAccountId ? (int)$myliveAccountId : null,
                                $targetDay,
                                $startTime . ':00',
                                $endTime . ':00'
                            ]);
                        }
                    }

                    $pdo->commit();

                    $photosToClean = $conflictPhotos;
                    if ($editRow && $oldEditPhoto !== '' && $oldEditPhoto !== $photoPath) {
                        $photosToClean[] = $oldEditPhoto;
                    }
                    program_cleanup_photos($pdo, $photosToClean);

                    $replaced = count(array_unique($conflictIds));
                    if ($editRow) {
                        $success = 'Η εκπομπή ενημερώθηκε στην ' . $days[$selectedDay] . '.';
                    } else {
                        $dayCount = count($targetDays);
                        $success = 'Η εκπομπή προστέθηκε σε ' . $dayCount . ' ημέρ' . ($dayCount === 1 ? 'α.' : 'ες.');
                        if ($replaced > 0) {
                            $success .= ' Αντικαταστάθηκαν ' . $replaced . ' υπάρχουσ' . ($replaced === 1 ? 'α εγγραφή.' : 'ες εγγραφές.');
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ($uploadedFile && is_file($uploadedFile)) @unlink($uploadedFile);
                    error_log('Program save failed: ' . $e->getMessage());
                    $error = 'Δεν ήταν δυνατή η αποθήκευση του προγράμματος.';
                }
            } elseif ($uploadedFile && is_file($uploadedFile)) {
                @unlink($uploadedFile);
            }
        }
    }
}

$countRows = $pdo->query(
    "SELECT day_of_week, COUNT(*) AS total
     FROM program
     WHERE day_of_week BETWEEN 1 AND 7
     GROUP BY day_of_week"
)->fetchAll(PDO::FETCH_ASSOC);

$dayCounts = array_fill(1, 7, 0);
foreach ($countRows as $row) {
    $day = (int)$row['day_of_week'];
    if ($day >= 1 && $day <= 7) {
        $dayCounts[$day] = (int)$row['total'];
    }
}
$totalProgram = array_sum($dayCounts);

$weeklyShowRows = $pdo->query(
    "SELECT LOWER(TRIM(dj_name)) AS show_key, COUNT(*) AS total
     FROM program
     GROUP BY LOWER(TRIM(dj_name))"
)->fetchAll(PDO::FETCH_ASSOC);

$weeklyShowCounts = [];
foreach ($weeklyShowRows as $row) {
    $weeklyShowCounts[(string)$row['show_key']] = (int)$row['total'];
}
$uniqueShowCount = count($weeklyShowCounts);

$stmt = $pdo->prepare(
    "SELECT *
     FROM program
     WHERE day_of_week = ?
     ORDER BY start_time ASC, id ASC"
);
$stmt->execute([$selectedDay]);
$program = $stmt->fetchAll(PDO::FETCH_ASSOC);

$editRecord = null;
$editSource = $_GET['edit'] ?? (
    ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save')
        ? ($_POST['edit_id'] ?? null)
        : null
);
$editRequest = filter_var($editSource, FILTER_VALIDATE_INT);
if ($editRequest) {
    foreach ($program as $item) {
        if ((int)$item['id'] === (int)$editRequest) {
            $editRecord = $item;
            break;
        }
    }
}

$formName = $editRecord ? (string)$editRecord['dj_name'] : '';
$formMyLiveAccountId = $editRecord ? (int)($editRecord['mylive_account_id'] ?? 0) : 0;
$formPhotoReference = $editRecord ? (string)($editRecord['photo_path'] ?? '') : '';
$formStart = $editRecord ? substr((string)$editRecord['start_time'], 0, 5) : '';
$formEnd = $editRecord ? substr((string)$editRecord['end_time'], 0, 5) : '';
$formAllDay = $editRecord
    && $formStart === '00:00'
    && in_array($formEnd, ['23:59', '23:59:59'], true);
$formDays = $editRecord ? [$selectedDay] : [$selectedDay];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save' && $error !== null) {
    $formName = trim((string)($_POST['dj_name'] ?? ''));
    $formMyLiveAccountId = (int)($_POST['mylive_account_id'] ?? 0);
    $formPhotoReference = trim((string)($_POST['photo_reference'] ?? ''));
    $formAllDay = !empty($_POST['all_day']);
    $formStart = trim((string)($_POST['start_time'] ?? ''));
    $formEnd = trim((string)($_POST['end_time'] ?? ''));
    if (!$editRecord) {
        $formDays = array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['days'] ?? [])),
            static fn(int $day): bool => $day >= 1 && $day <= 7
        )));
    }
}

$mediaLibrary = program_media_library();
$mediaFolders = array_values(array_unique(array_map(
    static fn(array $item): string => (string)$item['folder'],
    $mediaLibrary
)));
usort($mediaFolders, static function(string $a, string $b): int {
    $priority = [
        'Deseo Auto' => 1,
        'deseo djs' => 2,
        'Uploads' => 3,
        'Site Assets' => 4,
    ];

    $aBase = explode(' / ', $a)[0] ?? $a;
    $bBase = explode(' / ', $b)[0] ?? $b;
    $aPriority = $priority[$aBase] ?? 20;
    $bPriority = $priority[$bBase] ?? 20;

    return $aPriority !== $bPriority
        ? $aPriority <=> $bPriority
        : strnatcasecmp($a, $b);
});

admin_page_start('Radio Program', 'program');
?>
<div class="page-heading schedule-page-heading">
    <div>
        <span>Live schedule</span>
        <h1>Radio Program</h1>
        <p>Διαχειρίσου το πρόγραμμα ανά ημέρα. Αν μια νέα ώρα επικαλύπτεται με υπάρχουσα εκπομπή, η παλιά εγγραφή αντικαθίσταται αυτόματα.</p>
    </div>

    <?php if ($totalProgram > 0): ?>
        <form method="post"
              action="program.php?day=<?= $selectedDay ?>"
              onsubmit="return confirm('ΠΡΟΣΟΧΗ: Να διαγραφεί ΟΛΟ το πρόγραμμα και για τις 7 ημέρες; Η ενέργεια δεν αναιρείται.');">
            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_all">
            <input type="hidden" name="day" value="<?= $selectedDay ?>">
            <button class="button button-danger" type="submit">Delete entire program</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($success): ?><div class="notice notice-success"><?= admin_e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

<nav class="schedule-day-tabs" aria-label="Επιλογή ημέρας">
    <?php foreach ($days as $value => $label): ?>
        <a class="schedule-day-tab <?= $selectedDay === $value ? 'active' : '' ?>"
           href="program.php?day=<?= $value ?>">
            <strong><?= admin_e($dayShort[$value]) ?></strong>
            <span><?= admin_e($label) ?></span>
            <small><?= (int)$dayCounts[$value] ?> <?= (int)$dayCounts[$value] === 1 ? 'show' : 'shows' ?></small>
        </a>
    <?php endforeach; ?>
</nav>

<section class="schedule-overview">
    <div><span>WEEK TOTAL</span><strong><?= $totalProgram ?></strong><small>program slots</small></div>
    <div><span>UNIQUE SHOWS</span><strong><?= $uniqueShowCount ?></strong><small>different shows</small></div>
    <div><span><?= admin_e(strtoupper($dayShort[$selectedDay])) ?></span><strong><?= (int)$dayCounts[$selectedDay] ?></strong><small>slots selected day</small></div>
</section>

<div class="schedule-layout">
    <section class="panel schedule-editor-panel">
        <div class="schedule-panel-head">
            <div>
                <span class="schedule-eyebrow"><?= admin_e($days[$selectedDay]) ?></span>
                <h2><?= $editRecord ? 'Edit show' : 'Add show' ?></h2>
                <p><?= $editRecord ? 'Άλλαξε στοιχεία ή ώρες. Η υπάρχουσα φωτογραφία μένει αν δεν ανεβάσεις νέα.' : 'Επίλεξε μία ή περισσότερες ημέρες για να περάσεις την εκπομπή με μία κίνηση.' ?></p>
            </div>
            <?php if ($editRecord): ?>
                <a class="schedule-cancel-edit" href="program.php?day=<?= $selectedDay ?>">Cancel edit</a>
            <?php endif; ?>
        </div>

        <div class="schedule-rule-note">
            <strong>No duplicates.</strong>
            Ώρες που επικαλύπτονται στην ίδια ημέρα αντικαθίστανται αυτόματα.
        </div>

        <form method="post"
              action="program.php?day=<?= $selectedDay ?>"
              enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="day" value="<?= $selectedDay ?>">
            <input type="hidden" name="edit_id" value="<?= $editRecord ? (int)$editRecord['id'] : '' ?>">

            <div class="form-grid">
                <div class="field full">
                    <label for="dj_name">DJ / Show name</label>
                    <input id="dj_name"
                           type="text"
                           name="dj_name"
                           maxlength="255"
                           value="<?= admin_e($formName) ?>"
                           required>
                </div>

                <div class="field full">
                    <label for="mylive_account_id">DJ Profile / MyLive · optional</label>
                    <select id="mylive_account_id" name="mylive_account_id">
                        <option value="">No public DJ profile · autopilot / generic show</option>
                        <?php foreach ($profileAccounts as $profileAccount): ?>
                            <option value="<?= (int)$profileAccount['id'] ?>" <?= $formMyLiveAccountId === (int)$profileAccount['id'] ? 'selected' : '' ?>>
                                <?= admin_e($profileAccount['artist_name']) ?> · <?= admin_e($profileAccount['email']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="field-help">Αν μείνει κενό, το πρόγραμμα λειτουργεί ακριβώς όπως τώρα και δεν ανοίγει DJ profile modal.</small>
                </div>

                <?php if (!$editRecord): ?>
                    <div class="field full">
                        <span class="field-label">Broadcast days</span>
                        <div class="day-picker schedule-form-days">
                            <?php foreach ($days as $value => $label): ?>
                                <label class="day-option">
                                    <input type="checkbox"
                                           name="days[]"
                                           value="<?= $value ?>"
                                           <?= in_array($value, $formDays, true) ? 'checked' : '' ?>>
                                    <span><?= admin_e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="field full schedule-photo-field">
                    <label for="photo_reference">Cover photo</label>

                    <div class="schedule-photo-source">
                        <div class="schedule-photo-link">
                            <input id="photo_reference"
                                   type="text"
                                   name="photo_reference"
                                   value="<?= admin_e($formPhotoReference) ?>"
                                   placeholder="https://... or /iluma/uploads/...">
                            <button class="button button-secondary schedule-media-browser-button"
                                    id="openMediaBrowser"
                                    type="button">Browse server</button>
                        </div>
                        <small class="field-help">Βάλε image URL, επίλεξε υπάρχουσα εικόνα από τον server ή ανέβασε νέο αρχείο.</small>
                    </div>

                    <div class="schedule-photo-preview <?= $formPhotoReference !== '' ? 'has-image' : '' ?>" id="schedulePhotoPreview">
                        <img id="schedulePhotoPreviewImage"
                             src="<?= $formPhotoReference !== '' ? admin_e($formPhotoReference) : '/assets/img/bg.png' ?>"
                             alt="">
                        <div>
                            <span>SELECTED COVER</span>
                            <strong id="schedulePhotoPreviewName"><?= $formPhotoReference !== '' ? admin_e(basename((string)(parse_url($formPhotoReference, PHP_URL_PATH) ?: $formPhotoReference))) : 'No image selected yet' ?></strong>
                            <small id="schedulePhotoPreviewPath"><?= $formPhotoReference !== '' ? admin_e($formPhotoReference) : 'Choose from server, paste a link or upload a file.' ?></small>
                        </div>
                    </div>

                    <div class="schedule-photo-upload">
                        <span>OR UPLOAD NEW</span>
                        <input class="file-input"
                               id="photo"
                               type="file"
                               name="photo"
                               accept="image/jpeg,image/png,image/webp">
                        <small>JPG, PNG ή WEBP · έως 5MB. Το νέο upload έχει προτεραιότητα από link/browser.</small>
                    </div>
                </div>
            </div>

            <div class="form-grid three schedule-time-grid">
                <div class="toggle-row">
                    <input id="all_day" type="checkbox" name="all_day" <?= $formAllDay ? 'checked' : '' ?>>
                    <label for="all_day">All day / 24h</label>
                </div>

                <div class="field">
                    <label for="start_time">Start time</label>
                    <input id="start_time"
                           type="time"
                           name="start_time"
                           value="<?= admin_e($formStart) ?>"
                           <?= $formAllDay ? '' : 'required' ?>>
                </div>

                <div class="field">
                    <label for="end_time">End time</label>
                    <input id="end_time"
                           type="time"
                           name="end_time"
                           value="<?= admin_e($formEnd) ?>"
                           <?= $formAllDay ? '' : 'required' ?>>
                </div>
            </div>

            <div class="form-actions schedule-form-actions">
                <?php if ($editRecord): ?>
                    <a class="button button-secondary" href="program.php?day=<?= $selectedDay ?>">Cancel</a>
                <?php endif; ?>
                <button class="button button-primary" type="submit">
                    <?= $editRecord ? 'Save changes' : 'Add to selected days' ?>
                </button>
            </div>
        </form>
    </section>

    <section class="panel schedule-day-panel">
        <div class="schedule-panel-head">
            <div>
                <span class="schedule-eyebrow">Day schedule</span>
                <h2><?= admin_e($days[$selectedDay]) ?></h2>
                <p><?= count($program) ?> <?= count($program) === 1 ? 'εκπομπή' : 'εκπομπές' ?> στην ημέρα.</p>
            </div>

            <?php if ($program): ?>
                <form method="post"
                      action="program.php?day=<?= $selectedDay ?>"
                      onsubmit="return confirm('Να διαγραφεί όλο το πρόγραμμα της <?= admin_e($days[$selectedDay]) ?>;');">
                    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_day">
                    <input type="hidden" name="day" value="<?= $selectedDay ?>">
                    <button class="danger-link schedule-clear-day" type="submit">Clear day</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($program): ?>
            <div class="program-list program-day-list">
                <?php foreach ($program as $item): ?>
                    <article class="program-row <?= $editRecord && (int)$editRecord['id'] === (int)$item['id'] ? 'is-editing' : '' ?>">
                        <img src="<?= admin_e($item['photo_path'] ?: '/assets/img/bg.png') ?>" alt="">
                        <?php
                        $showKey = mb_strtolower(trim((string)$item['dj_name']), 'UTF-8');
                        $weeklyOccurrences = (int)($weeklyShowCounts[$showKey] ?? 1);
                        ?>
                        <div class="program-row-copy">
                            <span class="program-time"><?= substr((string)$item['start_time'],0,5) ?> — <?= substr((string)$item['end_time'],0,5) ?></span>
                            <h3><?= admin_e($item['dj_name']) ?></h3>
                            <small>
                                <?= $weeklyOccurrences ?> <?= $weeklyOccurrences === 1 ? 'slot' : 'slots' ?> this week
                                <?php if (!empty($item['mylive_account_id'])): ?> · MyLive profile linked<?php endif; ?>
                            </small>
                        </div>

                        <div class="program-row-actions">
                            <a class="schedule-action schedule-action-edit"
                               href="program.php?day=<?= $selectedDay ?>&edit=<?= (int)$item['id'] ?>">Edit</a>

                            <form method="post"
                                  action="program.php?day=<?= $selectedDay ?>"
                                  onsubmit="return confirm('Να διαγραφεί μόνο αυτό το συγκεκριμένο slot;');">
                                <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="day" value="<?= $selectedDay ?>">
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                <button class="schedule-action schedule-action-delete" type="submit">Delete slot</button>
                            </form>

                            <button
                                class="schedule-action schedule-action-week"
                                type="button"
                                data-delete-week
                                data-show-name="<?= admin_e((string)$item['dj_name']) ?>"
                                data-show-count="<?= $weeklyOccurrences ?>"
                            >Delete all week</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-admin schedule-empty">
                Δεν υπάρχει πρόγραμμα για <?= admin_e($days[$selectedDay]) ?>.<br>
                Πρόσθεσε την πρώτη εκπομπή από τη φόρμα.
            </div>
        <?php endif; ?>
    </section>
</div>

<div class="schedule-media-modal" id="scheduleMediaModal" hidden>
    <div class="schedule-media-backdrop" data-media-close></div>
    <section class="schedule-media-manager" role="dialog" aria-modal="true" aria-labelledby="scheduleMediaTitle">
        <header class="schedule-media-header">
            <div>
                <span>SERVER MEDIA</span>
                <h2 id="scheduleMediaTitle">Choose program cover</h2>
                <p><?= count($mediaLibrary) ?> εικόνες διαθέσιμες από ασφαλείς φακέλους του Deseo Radio.</p>
            </div>
            <button type="button" class="schedule-media-close" data-media-close aria-label="Close">×</button>
        </header>

        <div class="schedule-media-layout">
            <aside class="schedule-media-folders">
                <button type="button" class="is-active" data-media-folder="all">
                    <span>ALL IMAGES</span><b><?= count($mediaLibrary) ?></b>
                </button>
                <?php foreach ($mediaFolders as $folder): ?>
                    <?php
                    $folderCount = count(array_filter(
                        $mediaLibrary,
                        static fn(array $item): bool => (string)$item['folder'] === $folder
                    ));
                    ?>
                    <button type="button" data-media-folder="<?= admin_e($folder) ?>">
                        <span><?= admin_e($folder) ?></span><b><?= $folderCount ?></b>
                    </button>
                <?php endforeach; ?>
            </aside>

            <div class="schedule-media-content">
                <div class="schedule-media-toolbar">
                    <label>
                        <span>SEARCH</span>
                        <input id="scheduleMediaSearch" type="search" placeholder="Filename or folder…" autocomplete="off">
                    </label>
                    <small>Click an image to use it.</small>
                </div>

                <div class="schedule-media-grid" id="scheduleMediaGrid">
                    <?php foreach ($mediaLibrary as $media): ?>
                        <button type="button"
                                class="schedule-media-item"
                                data-media-item
                                data-media-url="<?= admin_e((string)$media['url']) ?>"
                                data-media-name="<?= admin_e((string)$media['name']) ?>"
                                data-media-folder="<?= admin_e((string)$media['folder']) ?>"
                                data-media-search="<?= admin_e(strtolower((string)$media['name'] . ' ' . (string)$media['folder'])) ?>">
                            <span class="schedule-media-thumb">
                                <img src="<?= admin_e((string)$media['url']) ?>" alt="" loading="lazy">
                            </span>
                            <span class="schedule-media-meta">
                                <strong><?= admin_e((string)$media['name']) ?></strong>
                                <small><?= admin_e((string)$media['folder']) ?></small>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="schedule-media-empty" id="scheduleMediaEmpty" <?= $mediaLibrary ? 'hidden' : '' ?>>
                    Δεν βρέθηκαν εικόνες με αυτά τα φίλτρα.
                </div>
            </div>
        </div>
    </section>
</div>

<div class="schedule-modal" id="deleteWeekModal" hidden>
    <div class="schedule-modal-backdrop" data-modal-close></div>
    <section class="schedule-modal-card" role="dialog" aria-modal="true" aria-labelledby="deleteWeekTitle">
        <span class="schedule-modal-kicker">DELETE FROM ENTIRE WEEK</span>
        <h2 id="deleteWeekTitle">Να διαγραφεί όλη η εβδομάδα;</h2>
        <p>
            Θα διαγραφούν όλες οι εμφανίσεις του
            <strong id="deleteWeekShowName"></strong>
            από όλες τις ημέρες της εβδομάδας.
        </p>
        <div class="schedule-modal-count">
            <span>SLOTS TO DELETE</span>
            <strong id="deleteWeekCount">0</strong>
        </div>
        <p class="schedule-modal-warning">Η ενέργεια δεν αναιρείται.</p>

        <div class="schedule-modal-actions">
            <button class="button button-secondary" type="button" data-modal-close>Όχι, κράτησέ τα</button>
            <form method="post" action="program.php?day=<?= $selectedDay ?>">
                <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_show_week">
                <input type="hidden" name="day" value="<?= $selectedDay ?>">
                <input type="hidden" name="show_name" id="deleteWeekShowInput" value="">
                <button class="button button-danger" type="submit">Ναι, διαγραφή όλων</button>
            </form>
        </div>
    </section>
</div>

<script>
(function(){
  var all = document.getElementById('all_day');
  var start = document.getElementById('start_time');
  var end = document.getElementById('end_time');

  function sync(){
    var disabled = all.checked;
    start.disabled = disabled;
    end.disabled = disabled;
    start.required = !disabled;
    end.required = !disabled;
  }

  if (all && start && end) {
    all.addEventListener('change', sync);
    sync();
  }

  var photoReference = document.getElementById('photo_reference');
  var photoUpload = document.getElementById('photo');
  var photoPreview = document.getElementById('schedulePhotoPreview');
  var photoPreviewImage = document.getElementById('schedulePhotoPreviewImage');
  var photoPreviewName = document.getElementById('schedulePhotoPreviewName');
  var photoPreviewPath = document.getElementById('schedulePhotoPreviewPath');

  var mediaModal = document.getElementById('scheduleMediaModal');
  var mediaOpen = document.getElementById('openMediaBrowser');
  var mediaSearch = document.getElementById('scheduleMediaSearch');
  var mediaItems = Array.prototype.slice.call(document.querySelectorAll('[data-media-item]'));
  var mediaFolderButtons = Array.prototype.slice.call(document.querySelectorAll('[data-media-folder]'));
  var mediaEmpty = document.getElementById('scheduleMediaEmpty');
  var activeMediaFolder = 'all';

  function filenameFromUrl(value) {
    if (!value) return 'No image selected yet';
    try {
      var parsed = new URL(value, window.location.origin);
      var parts = parsed.pathname.split('/').filter(Boolean);
      return decodeURIComponent(parts[parts.length - 1] || value);
    } catch (e) {
      return value;
    }
  }

  function updatePhotoPreview(value, name) {
    if (!photoPreview || !photoPreviewImage || !photoPreviewName || !photoPreviewPath) return;

    var clean = (value || '').trim();
    if (!clean) {
      photoPreview.classList.remove('has-image');
      photoPreviewImage.src = '/assets/img/bg.png';
      photoPreviewName.textContent = 'No image selected yet';
      photoPreviewPath.textContent = 'Choose from server, paste a link or upload a file.';
      return;
    }

    photoPreview.classList.add('has-image');
    photoPreviewImage.src = clean;
    photoPreviewName.textContent = name || filenameFromUrl(clean);
    photoPreviewPath.textContent = clean;
  }

  function closeMediaBrowser() {
    if (!mediaModal) return;
    mediaModal.classList.remove('is-open');
    document.body.classList.remove('schedule-media-open');
    window.setTimeout(function () {
      mediaModal.hidden = true;
    }, 160);
  }

  function openMediaBrowser() {
    if (!mediaModal) return;
    mediaModal.hidden = false;
    document.body.classList.add('schedule-media-open');
    window.requestAnimationFrame(function () {
      mediaModal.classList.add('is-open');
      if (mediaSearch) mediaSearch.focus();
    });
  }

  function applyMediaFilters() {
    var query = mediaSearch ? mediaSearch.value.trim().toLowerCase() : '';
    var visible = 0;

    mediaItems.forEach(function (item) {
      var folder = item.getAttribute('data-media-folder') || '';
      var haystack = item.getAttribute('data-media-search') || '';
      var folderMatch = activeMediaFolder === 'all' || folder === activeMediaFolder;
      var searchMatch = !query || haystack.indexOf(query) !== -1;
      var show = folderMatch && searchMatch;
      item.hidden = !show;
      if (show) visible++;
    });

    if (mediaEmpty) mediaEmpty.hidden = visible !== 0;
  }

  if (mediaOpen) mediaOpen.addEventListener('click', openMediaBrowser);

  document.querySelectorAll('[data-media-close]').forEach(function (button) {
    button.addEventListener('click', closeMediaBrowser);
  });

  mediaFolderButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      activeMediaFolder = button.getAttribute('data-media-folder') || 'all';
      mediaFolderButtons.forEach(function (item) {
        item.classList.toggle('is-active', item === button);
      });
      applyMediaFilters();
    });
  });

  if (mediaSearch) mediaSearch.addEventListener('input', applyMediaFilters);

  mediaItems.forEach(function (item) {
    item.addEventListener('click', function () {
      var url = item.getAttribute('data-media-url') || '';
      var name = item.getAttribute('data-media-name') || filenameFromUrl(url);

      if (photoReference) photoReference.value = url;
      if (photoUpload) photoUpload.value = '';
      updatePhotoPreview(url, name);
      closeMediaBrowser();
    });
  });

  if (photoReference) {
    photoReference.addEventListener('input', function () {
      updatePhotoPreview(photoReference.value, '');
    });
  }

  if (photoUpload) {
    photoUpload.addEventListener('change', function () {
      var file = photoUpload.files && photoUpload.files[0];
      if (!file) {
        updatePhotoPreview(photoReference ? photoReference.value : '', '');
        return;
      }

      var objectUrl = URL.createObjectURL(file);
      if (photoPreview) photoPreview.classList.add('has-image');
      if (photoPreviewImage) photoPreviewImage.src = objectUrl;
      if (photoPreviewName) photoPreviewName.textContent = file.name;
      if (photoPreviewPath) photoPreviewPath.textContent = 'New upload · will replace the selected link/server image on save.';
    });
  }

  var modal = document.getElementById('deleteWeekModal');
  var modalName = document.getElementById('deleteWeekShowName');
  var modalCount = document.getElementById('deleteWeekCount');
  var modalInput = document.getElementById('deleteWeekShowInput');

  function closeDeleteWeekModal(){
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('schedule-modal-open');
  }

  document.querySelectorAll('[data-delete-week]').forEach(function(button){
    button.addEventListener('click', function(){
      if (!modal) return;
      var name = button.getAttribute('data-show-name') || '';
      var count = button.getAttribute('data-show-count') || '0';

      if (modalName) modalName.textContent = name;
      if (modalCount) modalCount.textContent = count;
      if (modalInput) modalInput.value = name;

      modal.hidden = false;
      document.body.classList.add('schedule-modal-open');
    });
  });

  document.querySelectorAll('[data-modal-close]').forEach(function(button){
    button.addEventListener('click', closeDeleteWeekModal);
  });

  document.addEventListener('keydown', function(event){
    if (event.key !== 'Escape') return;

    if (mediaModal && !mediaModal.hidden) {
      closeMediaBrowser();
      return;
    }

    if (modal && !modal.hidden) {
      closeDeleteWeekModal();
    }
  });
}());
</script>
<?php admin_page_end(); ?>
