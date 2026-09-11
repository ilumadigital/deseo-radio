<?php
require_once 'db.php';

$days = [
    1 => 'Δευτέρα', 2 => 'Τρίτη', 3 => 'Τετάρτη', 
    4 => 'Πέμπτη', 5 => 'Παρασκευή', 6 => 'Σάββατο', 7 => 'Κυριακή'
];

// Προσθήκη νέου DJ στο πρόγραμμα (Multi-day Insert)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dj_name = trim($_POST['dj_name']);
    $photo_path = '';

    // Έλεγχος All Day
    if (isset($_POST['all_day'])) {
        $start_time = '00:00:00';
        $end_time = '23:59:59';
    } else {
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
    }

    // Έλεγχος Upload Φωτογραφίας
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
        
        $file_info = pathinfo($_FILES['photo']['name']);
        $ext = strtolower($file_info['extension']);
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (in_array($ext, $allowed)) {
            $new_filename = uniqid('dj_') . '.' . $ext;
            $destination = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
                $photo_path = '/iluma/uploads/' . $new_filename;
            }
        } else {
            $error = "Μη έγκυρος τύπος αρχείου. Μόνο JPG, PNG, WEBP.";
        }
    }

    // Εισαγωγή στη βάση ΜΟΝΟ αν έχουν επιλεγεί ημέρες και δεν υπάρχει error
    if (!isset($error) && !empty($_POST['days'])) {
        $stmt = $pdo->prepare("INSERT INTO program (dj_name, photo_path, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($_POST['days'] as $day_id) {
            $stmt->execute([$dj_name, $photo_path, (int)$day_id, $start_time, $end_time]);
        }
        $success = "Το πρόγραμμα ενημερώθηκε επιτυχώς!";
    } elseif (empty($_POST['days'])) {
        $error = "Παρακαλώ επιλέξτε τουλάχιστον μία ημέρα.";
    }
}

// Διαγραφή DJ
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("SELECT photo_path FROM program WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    $dj = $stmt->fetch();
    
    // Επειδή η φωτογραφία μπορεί να χρησιμοποιείται και σε άλλες μέρες, ΔΕΝ τη διαγράφουμε από τον δίσκο αν 
    // υπάρχει άλλη εγγραφή με το ίδιο path.
    if ($dj && $dj['photo_path']) {
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM program WHERE photo_path = ? AND id != ?");
        $check_stmt->execute([$dj['photo_path'], $_GET['delete']]);
        if ($check_stmt->fetchColumn() == 0) {
            $file_to_delete = $_SERVER['DOCUMENT_ROOT'] . $dj['photo_path'];
            if (file_exists($file_to_delete)) { unlink($file_to_delete); }
        }
    }
    
    $stmt = $pdo->prepare("DELETE FROM program WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header("Location: program.php");
    exit;
}

// Λήψη του προγράμματος
$program = $pdo->query("SELECT * FROM program ORDER BY day_of_week ASC, start_time ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Πρόγραμμα | ILUMA CMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #050505; color: white; }</style>
    
    <script>
        // Script για να απενεργοποιεί τα πεδία ώρας όταν τσεκάρεται το All Day
        function toggleTimeFields() {
            const isAllDay = document.getElementById('all_day').checked;
            const startTime = document.getElementById('start_time');
            const endTime = document.getElementById('end_time');
            
            if (isAllDay) {
                startTime.disabled = true;
                endTime.disabled = true;
                startTime.classList.add('opacity-50', 'cursor-not-allowed');
                endTime.classList.add('opacity-50', 'cursor-not-allowed');
                // Καθαρίζουμε το required αν είναι all day
                startTime.required = false;
                endTime.required = false;
            } else {
                startTime.disabled = false;
                endTime.disabled = false;
                startTime.classList.remove('opacity-50', 'cursor-not-allowed');
                endTime.classList.remove('opacity-50', 'cursor-not-allowed');
                startTime.required = true;
                endTime.required = true;
            }
        }
    </script>
</head>
<body>
    <div class="flex h-screen bg-[#050505]">
        <!-- Sidebar -->
        <aside class="w-64 bg-zinc-950 border-r border-white/5 flex flex-col shrink-0">
            <div class="p-8"><img src="/assets/img/deseoradio-logo.png" class="h-8"></div>
            <nav class="flex-1 px-4 space-y-2">
                <a href="index.php" class="flex items-center gap-3 text-zinc-400 hover:text-white hover:bg-white/5 px-4 py-3 rounded-xl transition-colors"><i class="fa-solid fa-chart-line w-5"></i> Dashboard</a>
                <a href="airplay.php" class="flex items-center gap-3 text-zinc-400 hover:text-white hover:bg-white/5 px-4 py-3 rounded-xl transition-colors"><i class="fa-solid fa-music w-5"></i> Airplay (Top 10)</a>
                <a href="program.php" class="flex items-center gap-3 bg-[#ccff00]/10 text-[#ccff00] px-4 py-3 rounded-xl font-bold"><i class="fa-solid fa-calendar-days w-5"></i> Program</a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-10 overflow-y-auto">
            <h1 class="text-3xl font-bold mb-2">Ροή Προγράμματος</h1>
            <p class="text-zinc-400 mb-8">Ορίστε τους DJs/Παραγωγούς. Μπορείτε να επιλέξετε πολλαπλές ημέρες!</p>

            <!-- Φόρμα Προσθήκης -->
            <div class="bg-zinc-900 border border-white/5 rounded-3xl p-8 mb-10">
                <?php if (isset($success)): ?><div class="bg-green-500/20 text-green-400 px-4 py-3 rounded-xl mb-4"><i class="fa-solid fa-check mr-2"></i> <?= $success ?></div><?php endif; ?>
                <?php if (isset($error)): ?><div class="bg-red-500/20 text-red-400 px-4 py-3 rounded-xl mb-4"><i class="fa-solid fa-triangle-exclamation mr-2"></i> <?= $error ?></div><?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" class="space-y-6">
                    
                    <!-- Row 1: Όνομα & Cover -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-zinc-500 uppercase tracking-widest mb-2 font-bold">Όνομα DJ / Εκπομπής</label>
                            <input type="text" name="dj_name" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-[#ccff00]" required>
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-500 uppercase tracking-widest mb-2 font-bold">Cover Photo</label>
                            <input type="file" name="photo" accept="image/*" class="w-full bg-black border border-white/10 rounded-xl px-2 py-2.5 text-white text-sm file:mr-4 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:bg-[#ccff00] file:text-black hover:file:bg-white" required>
                        </div>
                    </div>

                    <!-- Row 2: Ημέρες (Checkboxes) -->
                    <div>
                        <label class="block text-xs text-zinc-500 uppercase tracking-widest mb-3 font-bold">Ημέρες Εκπομπής</label>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach($days as $val => $day): ?>
                                <label class="cursor-pointer">
                                    <input type="checkbox" name="days[]" value="<?= $val ?>" class="peer sr-only">
                                    <div class="px-4 py-2 rounded-xl border border-white/10 bg-black text-zinc-400 text-sm font-medium peer-checked:bg-[#ccff00] peer-checked:text-black peer-checked:border-[#ccff00] transition-colors">
                                        <?= $day ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Row 3: Ώρες & All Day -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end bg-black/30 p-4 rounded-2xl border border-white/5">
                        
                        <div class="md:col-span-1 flex items-center h-full pb-3">
                            <label class="cursor-pointer flex items-center gap-3">
                                <input type="checkbox" name="all_day" id="all_day" class="w-5 h-5 accent-[#ccff00]" onchange="toggleTimeFields()">
                                <span class="text-white font-bold uppercase tracking-wider text-sm">ΟΛΗ ΜΕΡΑ (24h)</span>
                            </label>
                        </div>

                        <div class="md:col-span-1">
                            <label class="block text-xs text-zinc-500 uppercase tracking-widest mb-2 font-bold">Έναρξη (HH:MM)</label>
                            <input type="time" name="start_time" id="start_time" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-[#ccff00] transition-opacity" required>
                        </div>

                        <div class="md:col-span-1">
                            <label class="block text-xs text-zinc-500 uppercase tracking-widest mb-2 font-bold">Λήξη (HH:MM)</label>
                            <input type="time" name="end_time" id="end_time" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-[#ccff00] transition-opacity" required>
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="pt-2">
                        <button type="submit" class="w-full md:w-auto bg-[#ccff00] text-black font-bold rounded-xl px-8 py-3 hover:bg-white transition-colors"><i class="fa-solid fa-plus mr-2"></i> Αποθήκευση στο Πρόγραμμα</button>
                    </div>

                </form>
            </div>

            <!-- Λίστα -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($program as $item): ?>
                <div class="bg-zinc-900 border border-white/5 rounded-2xl p-5 flex items-center gap-5 relative group">
                    <div class="w-16 h-16 rounded-full overflow-hidden shrink-0 bg-black shadow-lg">
                        <img src="<?= htmlspecialchars($item['photo_path']) ?>" class="w-full h-full object-cover">
                    </div>
                    <div class="flex-1">
                        <h4 class="font-bold text-white text-lg"><?= htmlspecialchars($item['dj_name']) ?></h4>
                        <p class="text-[#ccff00] text-xs font-bold uppercase tracking-wider mb-1"><?= $days[$item['day_of_week']] ?></p>
                        <p class="text-zinc-400 text-sm">
                            <i class="fa-regular fa-clock mr-1"></i> 
                            <?php if ($item['start_time'] == '00:00:00' && $item['end_time'] == '23:59:59'): ?>
                                <span class="text-white">All Day</span>
                            <?php else: ?>
                                <?= date('H:i', strtotime($item['start_time'])) ?> - <?= date('H:i', strtotime($item['end_time'])) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="?delete=<?= $item['id'] ?>" class="absolute top-1/2 right-4 -translate-y-1/2 bg-red-500/20 text-red-500 w-10 h-10 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all hover:bg-red-500 hover:text-white" onclick="return confirm('Σίγουρα διαγραφή;');"><i class="fa-solid fa-trash"></i></a>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($program)): ?>
                    <div class="col-span-full text-center py-12 text-zinc-500 border border-dashed border-white/10 rounded-3xl">Δεν έχουν οριστεί παραγωγοί.</div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</body>
</html>