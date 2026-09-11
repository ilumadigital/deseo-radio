<footer class="bg-[#050505] border-t border-white/10 py-16 px-6 relative z-10">
    <div class="max-w-5xl mx-auto flex flex-col items-center text-center space-y-8">
        
        <div>
            <h3 class="text-2xl font-bold mb-2 tracking-wide text-white">Deseo Radio</h3>
            <p class="text-zinc-400 text-lg uppercase tracking-widest text-sm">The Soundtrack of your Life!</p>
        </div>

        <address class="not-italic text-zinc-500 space-y-2 text-sm">
            <p><i class="fa-solid fa-location-dot mr-2 text-[#ccff00]"></i>1st Moschonision st. Egaleo GR 12242</p>
            <p>
                <a href="tel:2103000825" class="hover:text-white transition-colors"><i class="fa-solid fa-phone mr-2 text-[#ccff00]"></i>2103000825</a> | 
                <a href="mailto:radio@iluma.gr" class="hover:text-white transition-colors"><i class="fa-solid fa-envelope mr-2 text-[#ccff00]"></i>radio@iluma.gr</a>
            </p>
        </address>

        <div class="pt-6 border-t border-white/5 w-full max-w-sm">
            <a href="https://iluma.gr/radios/deseo" target="_blank" rel="noopener noreferrer" class="text-xs text-zinc-600 hover:text-[#ccff00] uppercase tracking-widest transition-colors flex items-center justify-center gap-2">
                <i class="fa-solid fa-link"></i> Powered by ILUMA Digital Agency
            </a>
        </div>
    </div>
</footer>

<div id="pwa-install-prompt" class="fixed bottom-6 left-1/2 -translate-x-1/2 bg-[#ccff00] text-black font-bold px-6 py-3 rounded-full shadow-2xl z-50 flex items-center gap-3 cursor-pointer transform translate-y-40 opacity-0 transition-all duration-700 hover:scale-105">
    <i class="fa-solid fa-download"></i>
    <span class="text-xs uppercase tracking-wider">Εγκατάσταση App</span>
</div>

<?php include_once __DIR__ . '/cookiebanner.php'; ?>

<script>
document.addEventListener("DOMContentLoaded", (event) => {
    gsap.registerPlugin(ScrollTrigger);

    const tl = gsap.timeline({ defaults: { ease: "power4.out" } });

    tl.fromTo(".gsap-header", { y: -100, opacity: 0 }, { y: 0, opacity: 1, duration: 1.2 })
    .fromTo(".gsap-hero-left", { x: -100, opacity: 0, rotationY: -15 }, { x: 0, opacity: 1, rotationY: 0, duration: 1.5 }, "-=0.8")
    .fromTo(".gsap-hero-right", { x: 100, opacity: 0, rotationY: 15 }, { x: 0, opacity: 1, rotationY: 0, duration: 1.5 }, "-=1.2")
    .to(".gsap-hero-left, .gsap-hero-right", { y: -10, duration: 3, yoyo: true, repeat: -1, ease: "sine.inOut", stagger: 0.5 });

    gsap.to(".gsap-partners-grid", { scrollTrigger: { trigger: ".gsap-partners-grid", start: "top 85%" }, opacity: 1, duration: 0.1 });
    gsap.fromTo(".gsap-partner-item", { y: 50, opacity: 0 }, { scrollTrigger: { trigger: ".gsap-partners-grid", start: "top 85%" }, y: 0, opacity: 1, duration: 0.8, stagger: 0.1, ease: "back.out(1.7)" });

    // PWA Install Logic
    let deferredPrompt;
    const installPrompt = document.getElementById('pwa-install-prompt');

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        installPrompt.classList.remove('translate-y-40', 'opacity-0');
    });

    installPrompt.addEventListener('click', async () => {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted') {
                installPrompt.classList.add('translate-y-40', 'opacity-0');
            }
            deferredPrompt = null;
        }
    });

    window.addEventListener('appinstalled', () => {
        installPrompt.classList.add('translate-y-40', 'opacity-0');
    });
});
</script>

</body>
</html>