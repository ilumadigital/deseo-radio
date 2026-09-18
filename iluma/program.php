<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-ui.php';
require_once __DIR__ . '/../includes/uploads.php';

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

function program_cleanup_photos(PDO $pdo, array $paths): void {
    foreach (array_values(array_unique(array_filter($paths))) as $path) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM program WHERE photo_path = ?");
        $check->execute([$path]);
        if ((int)$check->fetchColumn() !== 0) continue;

        deseo_upload_delete_stored((string)$path);
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
            $photoPath = '';
            $oldEditPhoto = '';

            if ($djName === '' || mb_strlen($djName) > 255) {
                $error = 'Συμπληρώστε έγκυρο όνομα DJ / εκπομπής.';
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
                        try {
                            $uploadDir = deseo_upload_ensure_scope('program');
                            $filename = 'dj_' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
                            $uploadedFile = $uploadDir . '/' . $filename;

                            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadedFile)) {
                                $error = 'Η φωτογραφία δεν αποθηκεύτηκε στο persistent storage.';
                            } else {
                                @chmod($uploadedFile, 0664);
                                $photoPath = deseo_upload_public_url('program', $filename);
                            }
                        } catch (Throwable $uploadError) {
                            error_log('Persistent program upload failed: ' . $uploadError->getMessage());
                            $error = 'Δεν ήταν δυνατή η αποθήκευση της φωτογραφίας.';
                        }
                    }
                }
            } elseif ($error === null && !$editRow) {
                $error = 'Επιλέξτε φωτογραφία για τη νέα εκπομπή.';
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
                             SET dj_name = ?, photo_path = ?, start_time = ?, end_time = ?
                             WHERE id = ? AND day_of_week = ?"
                        );
                        $stmt->execute([
                            $djName,
                            $photoPath,
                            $startTime . ':00',
                            $endTime . ':00',
                            (int)$editId,
                            $selectedDay
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO program (dj_name, photo_path, day_of_week, start_time, end_time)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        foreach ($targetDays as $targetDay) {
                            $stmt->execute([
                                $djName,
                                $photoPath,
                                $targetDay,
                                $startTime . ':00',
                                $endTime . ':00'
                            ]);
                        }
                    }

                    $pdo->commit();

                    $photosToClean = $conflictPhotos;
                    if ($editRow && $hasPhotoUpload && $oldEditPhoto !== '' && $oldEditPhoto !== $photoPath) {
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
$formStart = $editRecord ? substr((string)$editRecord['start_time'], 0, 5) : '';
$formEnd = $editRecord ? substr((string)$editRecord['end_time'], 0, 5) : '';
$formAllDay = $editRecord
    && $formStart === '00:00'
    && in_array($formEnd, ['23:59', '23:59:59'], true);
$formDays = $editRecord ? [$selectedDay] : [$selectedDay];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save' && $error !== null) {
    $formName = trim((string)($_POST['dj_name'] ?? ''));
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

                <div class="field full">
                    <label for="photo">Cover photo · max 5MB<?= $editRecord ? ' · optional when editing' : '' ?></label>
                    <input class="file-input"
                           id="photo"
                           type="file"
                           name="photo"
                           accept="image/jpeg,image/png,image/webp"
                           <?= $editRecord ? '' : 'required' ?>>
                    <?php if ($editRecord && !empty($editRecord['photo_path'])): ?>
                        <div class="schedule-current-photo">
                            <img src="<?= admin_e(deseo_upload_url_from_stored((string)$editRecord['photo_path'])) ?>" alt="">
                            <span>Current cover — ανέβασε νέα μόνο αν θέλεις αλλαγή.</span>
                        </div>
                    <?php endif; ?>
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
                        <img src="<?= admin_e($item['photo_path'] ? deseo_upload_url_from_stored((string)$item['photo_path']) : '/assets/img/bg.png') ?>" alt="">
                        <div class="program-row-copy">
                            <span class="program-time"><?= substr((string)$item['start_time'],0,5) ?> — <?= substr((string)$item['end_time'],0,5) ?></span>
                            <h3><?= admin_e($item['dj_name']) ?></h3>
                        </div>

                        <div class="program-row-actions">
                            <a class="schedule-edit-link"
                               href="program.php?day=<?= $selectedDay ?>&edit=<?= (int)$item['id'] ?>">Edit</a>
                            <form method="post"
                                  action="program.php?day=<?= $selectedDay ?>"
                                  onsubmit="return confirm('Να διαγραφεί αυτή η εκπομπή;');">
                                <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="day" value="<?= $selectedDay ?>">
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                <button class="danger-link" type="submit">Delete</button>
                            </form>
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

  all.addEventListener('change', sync);
  sync();
}());
</script>
<?php admin_page_end(); ?>
