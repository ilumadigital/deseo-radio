<?php 
require_once 'includes/head-meta.php'; 
require_once 'includes/header.php'; 

// =========================================================================
// 1. SECURE DATABASE CONNECTION & DATA GRABBING
// =========================================================================
require_once 'iluma/connection.php';

try {
    // Fetch Airplay Top 10
    $airplay_tracks = $pdo->query("SELECT * FROM airplay ORDER BY position ASC LIMIT 10")->fetchAll();
    
    // Fetch Today's Program & Check Live DJ
    date_default_timezone_set('Europe/Athens');
    $current_day = (int)date('N'); // 1 = Monday, 7 = Sunday
    $current_time = date('H:i:s');
    
    $stmt = $pdo->prepare("SELECT * FROM program WHERE day_of_week = ? ORDER BY start_time ASC");
    $stmt->execute([$current_day]);
    $todays_program = $stmt->fetchAll();
    
    $live_dj = null;
    foreach($todays_program as $dj) {
        if ($current_time >= $dj['start_time'] && $current_time <= $dj['end_time']) {
            $live_dj = $dj;
            break;
        }
    }
} catch(Exception $e) {
    $airplay_tracks = [];
    $todays_program = [];
    $live_dj = null;
}
?>

<!-- Luxury Typography Engine Integration -->
<style>
    body, h1, h2, h3, p, a, span, button {
        font-family: 'Plus Jakarta Sans', 'Product Sans', 'Google Sans', sans-serif !important;
        -webkit-font-smoothing: antialiased;
    }
</style>

<h1 class="sr-only">Deseo Radio: Το Soundtrack της ζωής σου!</h1>

<!-- =========================================================================
     2. MASTER HERO LAYER (Cinematic 3-Column Console)
========================================================================= -->
<main class="relative w-full min-h-screen flex flex-col items-center justify-center pt-36 pb-24 overflow-hidden">
    
    <!-- Background Gradient Setup -->
    <div class="absolute inset-0 z-[-1] pointer-events-none select-none">
        <img src="/assets/img/bg.png" alt="Deseo Radio Deep Sunset Cover" class="w-full h-full object-cover filter brightness-[0.6] contrast-[1.05]">
        <div class="absolute inset-0 bg-black/40"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-black/80 via-transparent to-black"></div>
    </div>

    <!-- 3-Column Pure Grid (Enforced Max Width at 1600px for Cinematic Desktops) -->
    <section class="w-full max-w-[1600px] mx-auto px-6 md:px-12 grid grid-cols-1 md:grid-cols-3 gap-8 lg:gap-12 items-start mt-6">
        
        <!-- Deck 01: NOW PLAYING (Native Iframe Player) -->
        <div class="gsap-hero-left w-full flex flex-col items-center">
            <div class="mb-5 flex items-center gap-2.5 opacity-80 tracking-[0.4em] text-[10px] font-bold text-white uppercase self-start">
                <span class="w-1.5 h-1.5 rounded-full bg-[#ccff00] shadow-[0_0_8px_#ccff00] animate-pulse"></span>
                # NOW PLAYING
            </div>
            
            <div class="relative w-full aspect-square rounded-[2.5rem] overflow-hidden shadow-[0_30px_60px_-15px_rgba(0,0,0,0.9)] border border-white/5 bg-zinc-950">
                <!-- Native Iframe Player - Always Visible, Zero Hover Interference -->
                <iframe src="https://play.iradios.gr/widget/deseo-radio?autoplay=true" width="100%" height="100%" frameborder="0" allow="autoplay; encrypted-media; clipboard-write;" class="w-full h-full object-cover block"></iframe>
            </div>
        </div>

        <!-- Deck 02: NOW ON AIR (Dedicated DJ Module) -->
        <div class="gsap-hero-left w-full flex flex-col items-center" style="animation-delay: 150ms;">
            <div class="mb-5 flex items-center gap-2.5 opacity-80 tracking-[0.4em] text-[10px] font-bold text-white uppercase self-start">
                <span class="w-1.5 h-1.5 rounded-full <?= $live_dj ? 'bg-[#ccff00] shadow-[0_0_8px_#ccff00]' : 'bg-zinc-600' ?>"></span>
                # NOW ON AIR
            </div>
            
            <div class="relative w-full aspect-square rounded-[2.5rem] overflow-hidden shadow-[0_30px_60px_-15px_rgba(0,0,0,0.9)] border border-white/5 bg-zinc-950 flex flex-col justify-end">
                <?php if($live_dj): ?>
                    <!-- Live DJ Photo -->
                    <img src="<?= htmlspecialchars($live_dj['photo_path']) ?>" alt="<?= htmlspecialchars($live_dj['dj_name']) ?>" class="absolute inset-0 w-full h-full object-cover">
                    <!-- Elegant Bottom Slate Info -->
                    <div class="relative z-10 p-7 bg-gradient-to-t from-black via-black/70 to-transparent w-full">
                        <span class="text-[#ccff00] text-[9px] uppercase tracking-[0.3em] font-bold block mb-1">Live Broadcast</span>
                        <h2 class="text-2xl lg:text-3xl font-bold text-white tracking-tight truncate"><?= htmlspecialchars($live_dj['dj_name']) ?></h2>
                    </div>
                <?php else: ?>
                    <!-- Fallback / Auto Mix Graphics Layout -->
                    <img src="/assets/img/bg.png" alt="Deseo Radio Auto DJ" class="absolute inset-0 w-full h-full object-cover filter brightness-50">
                    <div class="relative z-10 p-7 bg-gradient-to-t from-black via-black/80 to-transparent w-full">
                        <span class="text-zinc-500 text-[9px] uppercase tracking-[0.3em] font-bold block mb-1">Non-Stop Mix</span>
                        <h2 class="text-2xl lg:text-3xl font-bold text-white tracking-tight">DESEO AUTO DJ</h2>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Deck 03: SPONSOR (Bespoke Agency Banner) -->
        <div class="gsap-hero-right w-full flex flex-col items-center">
            <div class="mb-5 flex items-center gap-2.5 opacity-40 tracking-[0.4em] text-[10px] font-bold text-white uppercase self-start">
                # SPONSOR
            </div>
            
            <a href="https://iluma.gr" target="_blank" rel="noopener" class="block w-full aspect-square rounded-[2.5rem] overflow-hidden shadow-[0_30px_60px_-15px_rgba(0,0,0,0.9)] border border-white/5 transition-all duration-500 hover:scale-[1.015] hover:border-white/10 bg-zinc-950">
                <img src="/assets/img/iluma-digital-agency-banner.jpg" alt="Iluma Digital Agency - Bespoke Production" class="w-full h-full object-cover">
            </a>
        </div>

    </section>

    <!-- Invisible Scroll Cue -->
    <div class="gsap-scroll-indicator absolute bottom-6 left-1/2 -translate-x-1/2 opacity-20 pointer-events-none">
        <i class="fa-solid fa-chevron-down text-sm animate-bounce text-white"></i>
    </div>
</main>


<!-- =========================================================================
     3. PARTNERS SECTION
========================================================================= -->
<section class="w-full bg-black py-24 md:py-32 relative z-10 border-t border-white/[0.02]">
    <div class="max-w-7xl mx-auto px-8 md:px-12">
        <div class="text-center mb-20">
            <span class="text-[#ccff00] text-[10px] font-bold uppercase tracking-[0.5em] block mb-4">Global Network</span>
            <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold text-white tracking-tight uppercase tracking-[0.05em]">
                Our <span class="text-zinc-600 font-light italic">Partners</span>
            </h2>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-12 md:gap-24 items-center justify-items-center opacity-0 gsap-partners-grid">
            <?php 
            $partners = [
                1 => ['url' => 'https://play.iradios.gr/station/deseo-radio', 'name' => 'iRadios GR App'],
                2 => ['url' => 'https://onlineradiobox.com/gr/deseo/', 'name' => 'Online Radio Box'],
                3 => ['url' => 'https://www.getmeradio.com/stations/deseoradiogr-4835/', 'name' => 'Get Me Radio'],
                4 => ['url' => 'https://isavior.gr/', 'name' => 'iSavior'],
                5 => ['url' => 'https://tunein.com/radio/Deseo-Radio-s258242/', 'name' => 'TuneIn Radio'],
                6 => ['url' => 'https://vradio.app/play?id=20739', 'name' => 'VRadio App'],
                7 => ['url' => 'https://streema.com/radios/Deseo_Radio', 'name' => 'Streema Radio'],
                8 => ['url' => 'https://iluma.gr/radios/deseo', 'name' => 'ILUMA Radios Network']
            ];
            foreach ($partners as $id => $partner): ?>
                <a href="<?php echo $partner['url']; ?>" target="_blank" rel="noopener noreferrer" class="w-full max-w-[150px] aspect-video flex items-center justify-center grayscale opacity-25 hover:grayscale-0 hover:opacity-100 transition-all duration-700 block">
                    <img src="/assets/img/partner-<?php echo $id; ?>.png" alt="Deseo Radio Live on <?php echo $partner['name']; ?>" class="max-w-full max-h-full object-contain active:scale-98 transition-transform duration-300">
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>


<!-- =========================================================================
     4. THE SOUNDSCAPE (Airplay Top 10 & Daily Schedule Widgets)
========================================================================= -->
<section class="w-full bg-[#050505] py-32 relative z-10 border-t border-white/[0.02]">
    <div class="max-w-7xl mx-auto px-8 md:px-12 grid grid-cols-1 lg:grid-cols-2 gap-20 lg:gap-32">
        
        <!-- WIDGET 1: Top 10 Airplay -->
        <div class="flex flex-col opacity-0 gsap-partner-item">
            <div class="mb-10">
                <span class="text-[#ccff00] text-[10px] font-bold uppercase tracking-[0.5em] block mb-3">Trending Now</span>
                <h3 class="text-3xl md:text-4xl font-bold text-white tracking-tight">Top 5 <span class="text-zinc-600 font-light italic">Airplay</span></h3>
            </div>
            
            <div class="flex flex-col gap-2">
                <?php if(!empty($airplay_tracks)): foreach($airplay_tracks as $track): ?>
                    <a href="<?= htmlspecialchars($track['spotify_url']) ?>" target="_blank" rel="noopener" class="flex items-center gap-6 group p-4 rounded-3xl bg-white/[0.01] hover:bg-white/[0.03] transition-all duration-300 border border-transparent hover:border-white/5">
                        <span class="text-3xl font-bold text-zinc-800 group-hover:text-[#ccff00] transition-colors w-10 text-center font-serif italic"><?= $track['position'] ?></span>
                        <img src="<?= htmlspecialchars($track['artwork_url']) ?>" class="w-16 h-16 rounded-[1rem] shadow-lg object-cover group-hover:scale-105 transition-transform duration-500">
                        <div class="flex-1 min-w-0">
                            <h4 class="text-white font-bold text-lg md:text-xl truncate tracking-tight"><?= htmlspecialchars($track['track_name']) ?></h4>
                            <p class="text-zinc-500 text-xs uppercase tracking-widest mt-1"><i class="fa-brands fa-spotify text-green-500 mr-1"></i> Listen on Spotify</p>
                        </div>
                    </a>
                <?php endforeach; else: ?>
                    <p class="text-zinc-600 font-light">Τα charts ανανεώνονται...</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- WIDGET 2: Today's Lineup -->
        <div class="flex flex-col opacity-0 gsap-partner-item">
            <div class="mb-10">
                <span class="text-[#ccff00] text-[10px] font-bold uppercase tracking-[0.5em] block mb-3">Today's Broadcast</span>
                <h3 class="text-3xl md:text-4xl font-bold text-white tracking-tight">The <span class="text-zinc-600 font-light italic">Lineup</span></h3>
            </div>
            
            <div class="flex flex-col gap-4 relative">
                <!-- Timeline Connecting Line -->
                <div class="absolute left-[88px] top-8 bottom-8 w-[1px] bg-white/5 z-0 hidden md:block"></div>

                <?php if(!empty($todays_program)): foreach($todays_program as $dj): 
                    $is_live = ($live_dj && $live_dj['id'] === $dj['id']);
                ?>
                    <div class="relative z-10 flex items-center gap-6 p-5 rounded-3xl transition-all duration-500 <?= $is_live ? 'bg-[#ccff00]/5 border border-[#ccff00]/20 shadow-[0_0_40px_rgba(204,255,0,0.05)]' : 'bg-white/[0.01] border border-transparent hover:bg-white/[0.03]' ?>">
                        <!-- Time -->
                        <div class="w-20 text-right shrink-0">
                            <span class="text-white font-bold text-lg tracking-tight"><?= date('H:i', strtotime($dj['start_time'])) ?></span>
                            <p class="text-zinc-500 text-[10px] uppercase tracking-widest"><?= date('H:i', strtotime($dj['end_time'])) ?></p>
                        </div>
                        
                        <!-- Photo -->
                        <div class="w-16 h-16 rounded-full overflow-hidden shrink-0 bg-black shadow-2xl border-2 <?= $is_live ? 'border-[#ccff00] p-[2px]' : 'border-transparent' ?>">
                            <img src="<?= htmlspecialchars($dj['photo_path']) ?>" class="w-full h-full object-cover rounded-full">
                        </div>
                        
                        <!-- Info -->
                        <div class="flex-1 min-w-0">
                            <h4 class="text-white font-bold text-xl truncate"><?= htmlspecialchars($dj['dj_name']) ?></h4>
                            <?php if($is_live): ?>
                                <span class="text-[#ccff00] text-[10px] uppercase tracking-widest font-bold flex items-center gap-2 mt-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#ccff00] animate-pulse"></span> On Air Now
                                </span>
                            <?php else: ?>
                                <span class="text-zinc-600 text-[10px] uppercase tracking-widest mt-1 block">Live Set</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <p class="text-zinc-600 font-light">Non-stop Auto DJ Mix today.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>
</section>


<!-- =========================================================================
     5. THE SIGNATURE ILUMA B2B ADVERTISING BROADCAST CORE (SEXY CTA)
========================================================================= -->
<section class="w-full bg-black py-40 md:py-56 relative z-10 border-t border-white/[0.02]">
    <div class="flex flex-col items-center text-center space-y-12 max-w-4xl mx-auto opacity-0 gsap-partner-item px-4">
        <h3 class="text-4xl md:text-6xl lg:text-7xl font-bold text-white tracking-tight leading-[1.05] max-w-3xl">
            Συνδέστε το brand σας <br class="hidden sm:block">με την <span class="text-[#ccff00] font-medium">elite</span> της House.
        </h3>
        <p class="text-zinc-400 font-light text-base md:text-xl leading-relaxed max-w-2xl">
            Αποκτήστε άμεση πρόσβαση στο exclusive, παγκόσμιο κοινό του Deseo Radio. Επικοινωνήστε με την ομάδα της <span class="text-white font-medium">ILUMA Digital Agency</span> και επιλέξτε την υπηρεσία «Ραδιοφωνική Διαφήμιση».
        </p>
        <div class="pt-4">
            <a href="https://iluma.gr/contact/" target="_blank" rel="noopener" class="inline-block px-16 py-6.5 bg-[#ccff00] hover:bg-white text-black font-bold text-sm md:text-base tracking-[0.25em] uppercase rounded-full transition-all duration-500 shadow-[0_25px_60px_-10px_rgba(204,255,0,0.25)] hover:shadow-[0_30px_70px_rgba(255,255,255,0.4)] hover:-translate-y-2 transform whitespace-nowrap">
                Διαφημιστειτε Εδω
            </a>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>