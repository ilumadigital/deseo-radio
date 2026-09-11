<?php
require_once 'db.php';

// Χειρισμός Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['iluma_admin'] = true;
        header("Location: index.php");
        exit;
    } else {
        $error = "Λάθος κωδικός!";
    }
}

// Χειρισμός Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ILUMA CMS | Deseo Radio</title>
    <!-- Φορτώνουμε Tailwind μέσω CDN για το CMS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #050505; color: white; }
    </style>
</head>
<body>

<?php if (!isset($_SESSION['iluma_admin'])): ?>
    <!-- LOGIN SCREEN -->
    <div class="min-h-screen flex items-center justify-center">
        <div class="bg-zinc-900 p-10 rounded-3xl shadow-2xl border border-white/10 w-full max-w-md text-center">
            <img src="/assets/img/deseoradio-logo.png" class="h-10 mx-auto mb-8 filter drop-shadow-[0_0_10px_rgba(255,255,255,0.2)]">
            <h2 class="text-2xl font-bold mb-6">CMS Access</h2>
            <?php if (isset($error)): ?><p class="text-red-500 mb-4 text-sm"><?= $error ?></p><?php endif; ?>
            <form method="POST" class="space-y-4">
                <input type="password" name="password" placeholder="Master Password" class="w-full bg-black border border-white/20 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-[#ccff00]" required>
                <button type="submit" class="w-full bg-[#ccff00] text-black font-bold rounded-xl px-4 py-3 hover:bg-white transition-colors">ΕΙΣΟΔΟΣ</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <!-- DASHBOARD LAYOUT -->
    <div class="flex h-screen bg-[#050505]">
        
        <!-- Sidebar -->
        <aside class="w-64 bg-zinc-950 border-r border-white/5 flex flex-col">
            <div class="p-8">
                <img src="/assets/img/deseoradio-logo.png" class="h-8">
            </div>
            <nav class="flex-1 px-4 space-y-2">
                <a href="index.php" class="flex items-center gap-3 bg-[#ccff00]/10 text-[#ccff00] px-4 py-3 rounded-xl font-bold"><i class="fa-solid fa-chart-line w-5"></i> Dashboard</a>
                <a href="airplay.php" class="flex items-center gap-3 text-zinc-400 hover:text-white hover:bg-white/5 px-4 py-3 rounded-xl transition-colors"><i class="fa-solid fa-music w-5"></i> Airplay (Top 10)</a>
                <a href="program.php" class="flex items-center gap-3 text-zinc-400 hover:text-white hover:bg-white/5 px-4 py-3 rounded-xl transition-colors"><i class="fa-solid fa-calendar-days w-5"></i> Program</a>
            </nav>
            <div class="p-4 border-t border-white/5">
                <a href="?logout=1" class="text-zinc-500 hover:text-red-400 text-sm flex items-center gap-2"><i class="fa-solid fa-right-from-bracket"></i> Αποσύνδεση</a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-10 overflow-y-auto">
            <h1 class="text-4xl font-bold mb-2">Welcome Back!</h1>
            <p class="text-zinc-400 mb-10">Διαχειριστείτε το ραδιόφωνο του Deseo.</p>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="bg-zinc-900 border border-white/5 rounded-3xl p-8">
                    <h3 class="text-[#ccff00] font-bold mb-2 uppercase tracking-widest text-sm">Top 10 Airplay</h3>
                    <p class="text-zinc-400 mb-6">Ανανεώστε τα καλύτερα House tracks της εβδομάδας.</p>
                    <a href="airplay.php" class="bg-white/10 hover:bg-white/20 text-white px-6 py-2 rounded-full text-sm font-bold transition-colors">Διαχείριση</a>
                </div>
                <div class="bg-zinc-900 border border-white/5 rounded-3xl p-8">
                    <h3 class="text-[#ccff00] font-bold mb-2 uppercase tracking-widest text-sm">Live Program</h3>
                    <p class="text-zinc-400 mb-6">Ρυθμίστε ποιος DJ παίζει live ανά ώρα και ημέρα.</p>
                    <a href="program.php" class="bg-white/10 hover:bg-white/20 text-white px-6 py-2 rounded-full text-sm font-bold transition-colors">Πρόγραμμα</a>
                </div>
            </div>
        </main>
    </div>
<?php endif; ?>
</body>
</html>