<?php
require_once 'db.php';

// Spotify Grab & Save (Με θέση)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['spotify_url'])) {
    $url = trim($_POST['spotify_url']);
    $position = (int)$_POST['position']; // Παίρνουμε τη θέση από τη φόρμα
    
    // oEmbed Call
    $oembed_url = "https://open.spotify.com/oembed?url=" . urlencode($url);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $oembed_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $json = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($json, true);
    
    if ($data && isset($data['title'])) {
        $track_name = $data['title'];
        $artwork = $data['thumbnail_url'];
        $artist = "Διάφοροι / Μη διαθέσιμο";
        
        // Έλεγχος αν υπάρχει ήδη τραγούδι σε αυτή τη θέση και διαγραφή του (για να το αντικαταστήσουμε)
        $stmt_check = $pdo->prepare("DELETE FROM airplay WHERE position = ?");
        $stmt_check->execute([$position]);

        $stmt = $pdo->prepare("INSERT INTO airplay (spotify_url, track_name, artist_name, artwork_url, position) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$url, $track_name, $artist, $artwork, $position]);
        $success = "Το τραγούδι προστέθηκε επιτυχώς στη Θέση " . $position . "!";
    } else {
        $error = "Δεν βρέθηκαν πληροφορίες. Βεβαιωθείτε ότι είναι σωστό Spotify Link.";
    }
}

// Διαγραφή
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM airplay WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header("Location: airplay.php");
    exit;
}

// Λήψη όλων των Tracks ΤΑΞΙΝΟΜΗΜΕΝΑ με βάση τη θέση (1 έως 10)
$tracks = $pdo->query("SELECT * FROM airplay ORDER BY position ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Airplay | ILUMA CMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #050505; color: white; }</style>
</head>
<body>
    <div class="flex h-screen bg-[#050505]">
        <aside class="w-64 bg-zinc-950 border-r border-white/5 flex flex-col shrink-0">
            <div class="p-8"><img src="/assets/img/deseoradio-logo.png" class="h-8"></div>
            <nav class="flex-1 px-4 space-y-2">
                <a href="index.php" class="flex items-center gap-3 text-zinc-400 hover:text-white hover:bg-white/5 px-4 py-3 rounded-xl transition-colors"><i class="fa-solid fa-chart-line w-5"></i> Dashboard</a>
                <a href="airplay.php" class="flex items-center gap-3 bg-[#ccff00]/10 text-[#ccff00] px-4 py-3 rounded-xl font-bold"><i class="fa-solid fa-music w-5"></i> Airplay (Top 10)</a>
                <a href="program.php" class="flex items-center gap-3 text-zinc-400 hover:text-white hover:bg-white/5 px-4 py-3 rounded-xl transition-colors"><i class="fa-solid fa-calendar-days w-5"></i> Program</a>
            </nav>
        </aside>

        <main class="flex-1 p-10 overflow-y-auto">
            <h1 class="text-3xl font-bold mb-2">Top 10 Airplay</h1>
            <p class="text-zinc-400 mb-8">Εισάγετε ένα Spotify Link και επιλέξτε τη θέση του στο Top 10.</p>

            <div class="bg-zinc-900 border border-white/5 rounded-3xl p-8 mb-10">
                <?php if (isset($success)): ?><div class="bg-green-500/20 text-green-400 px-4 py-3 rounded-xl mb-4"><i class="fa-solid fa-check mr-2"></i> <?= $success ?></div><?php endif; ?>
                <?php if (isset($error)): ?><div class="bg-red-500/20 text-red-400 px-4 py-3 rounded-xl mb-4"><i class="fa-solid fa-triangle-exclamation mr-2"></i> <?= $error ?></div><?php endif; ?>
                
                <form method="POST" class="flex flex-col md:flex-row gap-4 items-end">
                    <div class="flex-1 w-full">
                        <label class="block text-xs text-zinc-500 uppercase tracking-widest mb-2 font-bold">Spotify Track URL</label>
                        <input type="url" name="spotify_url" placeholder="https://open.spotify.com/track/..." class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-[#ccff00]" required>
                    </div>
                    
                    <div class="w-full md:w-32 shrink-0">
                        <label class="block text-xs text-zinc-500 uppercase tracking-widest mb-2 font-bold">Θέση (1-10)</label>
                        <select name="position" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-[#ccff00]" required>
                            <?php for($i=1; $i<=10; $i++): ?>
                                <option value="<?= $i ?>">#<?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <button type="submit" class="w-full md:w-auto bg-[#ccff00] text-black font-bold rounded-xl px-8 py-3 hover:bg-white transition-colors flex items-center justify-center gap-2">
                        <i class="fa-brands fa-spotify"></i> Αποθήκευση
                    </button>
                </form>
            </div>

            <!-- Grid (Ταξινομημένο 1-10) -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6">
                <?php foreach ($tracks as $track): ?>
                <div class="bg-zinc-900 border border-white/5 rounded-2xl overflow-hidden relative group">
                    <div class="aspect-square w-full bg-black relative">
                        <img src="<?= htmlspecialchars($track['artwork_url']) ?>" class="w-full h-full object-cover">
                        <!-- Badge Θέσης -->
                        <div class="absolute top-3 left-3 bg-[#ccff00] text-black font-black w-8 h-8 rounded-full flex items-center justify-center shadow-lg">
                            <?= $track['position'] ?>
                        </div>
                        <a href="?delete=<?= $track['id'] ?>" class="absolute top-3 right-3 bg-red-500/90 text-white w-8 h-8 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-600" onclick="return confirm('Σίγουρα διαγραφή;');"><i class="fa-solid fa-trash text-sm"></i></a>
                    </div>
                    <div class="p-4">
                        <h4 class="font-bold text-white truncate text-sm" title="<?= htmlspecialchars($track['track_name']) ?>"><?= htmlspecialchars($track['track_name']) ?></h4>
                        <p class="text-zinc-500 text-xs truncate mt-1"><i class="fa-brands fa-spotify text-green-500 mr-1"></i> Spotify Linked</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>
</body>
</html>