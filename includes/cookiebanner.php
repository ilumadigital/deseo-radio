<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}

const consentUpdate = localStorage.getItem('cookieConsent') ? JSON.parse(localStorage.getItem('cookieConsent')) : null;

if (consentUpdate) {
    gtag('consent', 'default', consentUpdate);
} else {
    gtag('consent', 'default', {
        'ad_storage': 'denied',
        'ad_user_data': 'denied',
        'ad_personalization': 'denied',
        'analytics_storage': 'denied',
        'wait_for_update': 500
    });
}

// Custom Function για την ασφαλή ενεργοποίηση του Webpushr κατόπιν συγκατάθεσης
function initWebpushr() {
    if(typeof window.webpushrInitDone !== 'undefined') return;
    window.webpushrInitDone = true;
    
    (function(w,d,s,i) {
        if(typeof(w.webpushr)!=='undefined') return;
        w.webpushr=w.webpushr||function(){(w.webpushr.q=w.webpushr.q||[]).push(arguments)};
        var js, fjs = d.getElementsByTagName(s)[0];
        js = d.createElement(s); js.id = id; js.async=1;
        js.src = "https://cdn.webpushr.com/app.min.js";
        fjs.parentNode.insertBefore(js,fjs);
    }(window,document,'script','webpushr-jssdk'));
    
    webpushr('setup', {'key':'BKgQeRKClX2ZYF6gJkeWih74UwVtgQ0F22w6ARHnINyalkH8KVKUFoGicN0aUEZAIsCc3cghGj3x3Daw85_cw8U' });
}
</script>

<div id="cookie-banner" class="fixed bottom-6 left-6 right-6 md:left-auto md:max-w-md bg-black/60 backdrop-blur-xl border border-white/10 p-6 rounded-[2rem] shadow-2xl z-[9999] transform translate-y-40 opacity-0 transition-all duration-700 pointer-events-none">
    <div class="flex items-start gap-4">
        <div class="text-[#ccff00] text-2xl pt-1">
            <i class="fa-solid fa-cookie-bite"></i>
        </div>
        <div class="space-y-3">
            <h4 class="text-white font-bold tracking-wide">Cookie Preferences</h4>
            <p class="text-zinc-400 text-xs leading-relaxed">
                Χρησιμοποιούμε cookies για να βελτιώσουμε την ακουστική σου εμπειρία και να αναλύουμε την επισκεψιμότητά μας σύμφωνα με το Google Consent Mode v2.
            </p>
            
            <div id="cookie-options" class="hidden space-y-2 pt-2 border-t border-white/5">
                <div class="flex justify-between items-center text-xs">
                    <span class="text-zinc-300">Απαραίτητα (Λειτουργικότητα)</span>
                    <span class="text-[#ccff00] uppercase text-[10px] font-bold">Πάντα Ενεργά</span>
                </div>
                <div class="flex justify-between items-center text-xs">
                    <span class="text-zinc-300">Στατιστικά & Analytics</span>
                    <input type="checkbox" id="consent-analytics" checked class="accent-[#ccff00]">
                </div>
                <div class="flex justify-between items-center text-xs">
                    <span class="text-zinc-300">Διαφήμιση & Προσωποποίηση</span>
                    <input type="checkbox" id="consent-marketing" checked class="accent-[#ccff00]">
                </div>
            </div>

            <div class="flex flex-wrap gap-2 pt-2">
                <button id="btn-accept-all" class="px-4 py-2 bg-[#ccff00] text-black font-bold text-xs rounded-xl hover:bg-white transition-colors uppercase tracking-wider">Αποδοχή Όλων</button>
                <button id="btn-customize" class="px-3 py-2 bg-white/5 border border-white/10 text-white font-medium text-xs rounded-xl hover:bg-white/10 transition-colors uppercase tracking-wider">Ρυθμίσεις</button>
                <button id="btn-reject" class="px-3 py-2 text-zinc-500 hover:text-white transition-colors text-xs uppercase tracking-wider">Απόρριψη</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const banner = document.getElementById('cookie-banner');
    const optDiv = document.getElementById('cookie-options');
    
    // Αν ο χρήστης έχει ήδη αποδεχτεί cookies στο παρελθόν, τρέξε το Webpushr αμέσως
    if (localStorage.getItem('cookieConsent')) {
        const currentConsent = JSON.parse(localStorage.getItem('cookieConsent'));
        if (currentConsent.analytics_storage === 'granted') {
            initWebpushr();
        }
    } else {
        setTimeout(() => {
            banner.classList.remove('translate-y-40', 'opacity-0', 'pointer-events-none');
        }, 2000);
    }

    document.getElementById('btn-customize').addEventListener('click', () => {
        optDiv.classList.toggle('hidden');
    });

    function saveConsent(analytics, marketing) {
        const consentObject = {
            'analytics_storage': analytics ? 'granted' : 'denied',
            'ad_storage': marketing ? 'granted' : 'denied',
            'ad_user_data': marketing ? 'granted' : 'denied',
            'ad_personalization': marketing ? 'granted' : 'denied'
        };
        localStorage.setItem('cookieConsent', JSON.stringify(consentObject));
        gtag('consent', 'update', consentObject);
        banner.classList.add('translate-y-40', 'opacity-0', 'pointer-events-none');
        
        if (analytics) {
            initWebpushr();
        }
    }

    document.getElementById('btn-reject').addEventListener('click', () => saveConsent(false, false));
    
    document.getElementById('btn-accept-all').addEventListener('click', () => {
        if(!optDiv.classList.contains('hidden')) {
            const ana = document.getElementById('consent-analytics').checked;
            const mar = document.getElementById('consent-marketing').checked;
            saveConsent(ana, mar);
        } else {
            saveConsent(true, true);
        }
    });
});
</script>