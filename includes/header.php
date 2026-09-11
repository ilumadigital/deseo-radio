<!-- Προσθήκη z-[99999] και isolate για να μην περνάει κανένα overlay από πάνω -->
<header class="gsap-header fixed w-full top-0 z-[99999] isolate py-6 px-8 md:px-16 flex justify-between items-center pointer-events-none">
    
    <!-- Logo: Απόλυτα καθαρό, με ισχυρό drop shadow για αντίθεση με το background -->
    <div class="logo pointer-events-auto">
        <a href="/" class="block">
            <img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio Logo" class="h-8 md:h-10 object-contain drop-shadow-[0_2px_15px_rgba(0,0,0,0.9)] hover:scale-105 transition-transform duration-300">
        </a>
    </div>
    
    <!-- Social Icons: Σε dark glassmorphism νησάκι για να μην αλλοιώνονται -->
    <div class="social-icons flex gap-5 md:gap-6 pointer-events-auto bg-black/30 px-5 py-2.5 rounded-full backdrop-blur-md border border-white/10 shadow-[0_0_20px_rgba(0,0,0,0.5)]">
        <a href="https://www.instagram.com/deseoradio/" class="text-white hover:text-[#E1306C] transition-colors" target="_blank" rel="noopener">
            <i class="fa-brands fa-instagram fa-lg"></i>
        </a>
        <a href="https://www.facebook.com/deseoradiogr/" class="text-white hover:text-[#1877F2] transition-colors" target="_blank" rel="noopener">
            <i class="fa-brands fa-facebook-f fa-lg"></i>
        </a>
    </div>

</header>