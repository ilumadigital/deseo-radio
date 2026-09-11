<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-ui.php';

$days = [1=>'Δευτέρα',2=>'Τρίτη',3=>'Τετάρτη',4=>'Πέμπτη',5=>'Παρασκευή',6=>'Σάββατο',7=>'Κυριακή'];
$success = null;
$error = null;

function valid_time(string $time): bool {
    $d = DateTime::createFromFormat('H:i', $time);
    return $d && $d->format('H:i') === $time;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Η συνεδρία έληξε. Ανανεώστε τη σελίδα.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id) {
                $stmt = $pdo->prepare("SELECT photo_path FROM program WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                $pdo->prepare("DELETE FROM program WHERE id = ?")->execute([$id]);

                if ($row && !empty($row['photo_path'])) {
                    $check = $pdo->prepare("SELECT COUNT(*) FROM program WHERE photo_path = ?");
                    $check->execute([$row['photo_path']]);
                    if ((int)$check->fetchColumn() === 0) {
                        $basename = basename((string)$row['photo_path']);
                        $file = __DIR__ . '/uploads/' . $basename;
                        if (is_file($file)) @unlink($file);
                    }
                }
                $success = 'Η εγγραφή διαγράφηκε.';
            }
        }

        if ($action === 'save') {
            $djName = trim((string) ($_POST['dj_name'] ?? ''));
            $selectedDays = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['days'] ?? [])), static function($d){ return $d >= 1 && $d <= 7; })));
            $allDay = !empty($_POST['all_day']);
            $startTime = $allDay ? '00:00' : trim((string)($_POST['start_time'] ?? ''));
            $endTime = $allDay ? '23:59' : trim((string)($_POST['end_time'] ?? ''));
            $photoPath = '';
            $uploadedFile = null;

            if ($djName === '' || mb_strlen($djName) > 255) {
                $error = 'Συμπληρώστε έγκυρο όνομα DJ / εκπομπής.';
            } elseif (!$selectedDays) {
                $error = 'Επιλέξτε τουλάχιστον μία ημέρα.';
            } elseif (!$allDay && (!valid_time($startTime) || !valid_time($endTime))) {
                $error = 'Συμπληρώστε έγκυρες ώρες έναρξης και λήξης.';
            } elseif (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Επιλέξτε φωτογραφία για τον DJ / την εκπομπή.';
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

            if ($error === null) {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO program (dj_name, photo_path, day_of_week, start_time, end_time)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    foreach ($selectedDays as $day) {
                        $stmt->execute([$djName, $photoPath, $day, $startTime . ':00', $endTime . ':00']);
                    }
                    $pdo->commit();
                    $success = 'Το πρόγραμμα ενημερώθηκε για ' . count($selectedDays) . ' ημέρ' . (count($selectedDays) === 1 ? 'α.' : 'ες.');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ($uploadedFile && is_file($uploadedFile)) @unlink($uploadedFile);
                    error_log('Program save failed: ' . $e->getMessage());
                    $error = 'Δεν ήταν δυνατή η αποθήκευση του προγράμματος.';
                }
            }
        }
    }
}

$program = $pdo->query("SELECT * FROM program ORDER BY day_of_week ASC, start_time ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

admin_page_start('Radio Program', 'program');
?>
<div class="page-heading">
    <div><span>Live schedule</span><h1>Radio Program</h1><p>Πρόσθεσε μία εκπομπή σε μία ή περισσότερες ημέρες. Υποστηρίζονται και sets που περνούν τα μεσάνυχτα.</p></div>
</div>

<?php if ($success): ?><div class="notice notice-success"><?= admin_e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

<section class="panel">
<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="save">

    <div class="form-grid">
        <div class="field">
            <label for="dj_name">DJ / Show name</label>
            <input id="dj_name" type="text" name="dj_name" maxlength="255" required>
        </div>
        <div class="field">
            <label for="photo">Cover photo · max 5MB</label>
            <input class="file-input" id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
        </div>

        <div class="field full">
            <span class="field-label">Broadcast days</span>
            <div class="day-picker">
                <?php foreach($days as $value=>$label): ?>
                    <label class="day-option"><input type="checkbox" name="days[]" value="<?= $value ?>"><span><?= admin_e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="form-grid three" style="margin-top:16px">
        <div class="toggle-row">
            <input id="all_day" type="checkbox" name="all_day">
            <label for="all_day">All day / 24h</label>
        </div>
        <div class="field">
            <label for="start_time">Start time</label>
            <input id="start_time" type="time" name="start_time" required>
        </div>
        <div class="field">
            <label for="end_time">End time</label>
            <input id="end_time" type="time" name="end_time" required>
        </div>
    </div>

    <div class="form-actions"><button class="button button-primary" type="submit">Add to program</button></div>
</form>
</section>

<?php if ($program): ?>
<div class="program-list">
<?php foreach ($program as $item): ?>
    <article class="program-row">
        <img src="<?= admin_e($item['photo_path'] ?: '/assets/img/bg.png') ?>" alt="">
        <div>
            <span class="day"><?= admin_e($days[(int)$item['day_of_week']] ?? '—') ?></span>
            <h3><?= admin_e($item['dj_name']) ?></h3>
            <p><?= substr((string)$item['start_time'],0,5) ?> — <?= substr((string)$item['end_time'],0,5) ?></p>
        </div>
        <form method="post" onsubmit="return confirm('Να διαγραφεί αυτή η εγγραφή;')">
            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
            <button class="danger-link" type="submit">Delete</button>
        </form>
    </article>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty-admin">Δεν υπάρχει πρόγραμμα ακόμη.</div>
<?php endif; ?>

<script>
(function(){
  var all=document.getElementById('all_day');
  var start=document.getElementById('start_time');
  var end=document.getElementById('end_time');
  function sync(){
    var disabled=all.checked;
    start.disabled=disabled;end.disabled=disabled;
    start.required=!disabled;end.required=!disabled;
  }
  all.addEventListener('change',sync);sync();
}());
</script>
<?php admin_page_end(); ?>