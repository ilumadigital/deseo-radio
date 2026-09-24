<?php
declare(strict_types=1);

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
}

require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/dj-season.php';

function deseo_e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$meta_title = deseo_lang() === 'en' ? 'DJ Participation Terms · Deseo Radio Season 6' : 'Όροι Συμμετοχής DJ · Deseo Radio Season 6';
$meta_desc = deseo_lang() === 'en'
    ? 'Participation and collaboration terms for DJs and producers in Deseo Radio Season 6.'
    : 'Όροι συμμετοχής και συνεργασίας για DJs και producers στη Season 6 του Deseo Radio.';
$meta_canonical = 'https://deseoradio.com/djterms';
$meta_robots = 'noindex,nofollow,noarchive,nosnippet,noimageindex';
$private_page = true;
$extra_styles = ['/assets/css/djs.css'];

require_once __DIR__ . '/includes/head-meta.php';
require_once __DIR__ . '/includes/header.php';
?>

<?php if (deseo_lang() !== 'en'): ?>
<main id="main-content" class="dj-legal-page">
    <div class="dj-legal-shell">
        <header class="dj-legal-head">
            <span class="kicker">DESEO RADIO · SEASON 6</span>
            <h1 class="metal-title">Όροι Συμμετοχής<br>& Συνεργασίας DJ</h1>
            <p>
                Οι παρόντες όροι διέπουν τη συμμετοχή DJs και producers στο πρόγραμμα “Deseo Radio Season 6 · DJ Sets”.
                Με την ηλεκτρονική υποβολή της φόρμας στο <a href="/djs">/djs</a>, ο συμμετέχων δηλώνει ότι τους διάβασε,
                τους κατανόησε και τους αποδέχεται.
            </p>
            <div class="dj-legal-meta">
                <span>Season 6</span>
                <span>Έκδοση <?= deseo_e(DESEO_DJ_TERMS_VERSION) ?></span>
                <span>15 Σεπτεμβρίου 2026</span>
            </div>
        </header>

        <div class="dj-legal-content">
            <section class="dj-legal-section">
                <h2>1. Διοργάνωση και αντικείμενο</h2>
                <p>
                    Το Deseo Radio είναι digital radio brand της ILUMA Digital Agency. Η Season 6 περιλαμβάνει
                    προγραμματισμένες μεταδόσεις DJ Sets από επιλεγμένους DJs / producers σε εβδομαδιαία slots.
                    Η συμμετοχή είναι μη αποκλειστική και αφορά μόνο το πρόγραμμα, την προώθηση και την αρχειακή
                    παρουσίαση της συγκεκριμένης συνεργασίας.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>2. Χωρίς οικονομική αμοιβή</h2>
                <p>
                    Η συμμετοχή είναι εθελοντική και δεν προβλέπει αμοιβή, μισθό, fee, royalties από το Deseo Radio,
                    κάλυψη εξόδων ή άλλη οικονομική παροχή προς τον DJ, εκτός αν συμφωνηθεί διαφορετικά εγγράφως
                    για συγκεκριμένη περίπτωση.
                </p>
                <p>
                    Η απουσία χρηματικής αμοιβής δεν σημαίνει ότι δεν παρέχονται υπηρεσίες και οφέλη προς τους DJs.
                    Οι μη χρηματικές παροχές του Deseo Radio / ILUMA περιγράφονται αναλυτικά στο Άρθρο 10 και
                    περιλαμβάνουν, μεταξύ άλλων, έκθεση στο ακροατήριο του Deseo Radio, promotional υποστήριξη,
                    χορηγούμενες προωθήσεις και γραφιστικές / δημιουργικές υπηρεσίες για τη συγκεκριμένη συμμετοχή.
                </p>
                <p>
                    Η συμμετοχή δεν δημιουργεί από μόνη της σχέση εξαρτημένης εργασίας, εταιρική σχέση, σχέση
                    αντιπροσώπευσης ή αποκλειστικότητας. Η πραγματική φύση οποιασδήποτε σχέσης κρίνεται πάντοτε
                    σύμφωνα με την εφαρμοστέα νομοθεσία και τα πραγματικά περιστατικά.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>3. Προϋποθέσεις συμμετοχής</h2>
                <ul>
                    <li>Ο συμμετέχων πρέπει να είναι 18 ετών ή άνω.</li>
                    <li>Τα στοιχεία, το artist name, το email, η φωτογραφία και το bio που υποβάλλονται πρέπει να είναι ακριβή και νόμιμα.</li>
                    <li>Η φωτογραφία πρέπει να αφορά τον ίδιο τον DJ ή να χρησιμοποιείται από αυτόν με νόμιμη άδεια.</li>
                    <li>Το bio δεν πρέπει να περιέχει παραπλανητικές, προσβλητικές ή δυσφημιστικές πληροφορίες.</li>
                </ul>
            </section>

            <section class="dj-legal-section">
                <h2>4. Inquiry και προτίμηση ημέρας / ώρας</h2>
                <p>
                    Η φόρμα στο /djs αποτελεί αποκλειστικά inquiry ενδιαφέροντος. Ο DJ δηλώνει το slot που προτιμά,
                    χωρίς η υποβολή να αποτελεί κράτηση, αποδοχή, επιβεβαίωση συμμετοχής ή αυτόματη ένταξη στο
                    ραδιοφωνικό πρόγραμμα του Deseo Radio.
                </p>
                <p>
                    Πολλοί DJs μπορούν να υποβάλουν inquiry για το ίδιο slot. Η ομάδα Deseo / ILUMA αξιολογεί
                    χειροκίνητα τις αιτήσεις και επιλέγει ποιον DJ εγκρίνει. Ένα slot παύει να δέχεται νέα inquiries
                    μόνο όταν έχει δοθεί χειροκίνητα status “Approved” σε έναν DJ για το συγκεκριμένο slot.
                </p>
                <p>
                    Η τελική καταχώρηση στο κανονικό Radio Program δεν γίνεται από τη φόρμα ή το σύστημα inquiries.
                    Πραγματοποιείται ξεχωριστά και χειροκίνητα από τη διαχείριση του Deseo Radio.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>5. Επιτρεπόμενα DJ Sets</h2>
                <p>
                    Το set μπορεί να είναι νέο, παλαιότερο / ήδη ηχογραφημένο ή ειδικά δημιουργημένο για το Deseo Radio.
                    Ο DJ παραμένει ελεύθερος να διαθέτει ή να μεταδίδει το ίδιο set και αλλού, εκτός αν έχει συμφωνηθεί
                    διαφορετικά εγγράφως για συγκεκριμένη παραγωγή.
                </p>
                <p>
                    Οι τεχνικές προδιαγραφές, η μέθοδος αποστολής, η τελική διάρκεια και η προθεσμία παράδοσης
                    μπορούν να κοινοποιούνται ξεχωριστά από την ομάδα ILUMA / Deseo μετά την επιλογή του DJ.
                    Η μη έγκαιρη ή μη συμβατή παράδοση μπορεί να οδηγήσει σε ανάκληση της έγκρισης.
                </p>
            </section>

            <section class="dj-legal-section dj-legal-callout">
                <h2>6. Απόλυτη απαγόρευση AI-generated μουσικής</h2>
                <p>
                    Απαγορεύεται η συμπερίληψη στο DJ Set μουσικών έργων, τραγουδιών ή ηχογραφήσεων που έχουν
                    δημιουργηθεί εξ ολοκλήρου ή εν μέρει με generative artificial intelligence ως προς τη σύνθεση,
                    τους στίχους, τα vocals, τα βασικά μουσικά μέρη ή την κύρια ηχογράφηση.
                </p>
                <p>
                    Εργαλεία που χρησιμοποιούνται αποκλειστικά για τεχνικό mastering, noise reduction, restoration,
                    loudness matching ή παρόμοια post-production λειτουργία δεν θεωρούνται από μόνα τους δημιουργία
                    AI-generated μουσικού έργου, εφόσον δεν παράγουν το ουσιώδες δημιουργικό περιεχόμενο.
                </p>
                <p>
                    Ο DJ δηλώνει ότι, κατά την καλύτερη γνώση του και μετά από εύλογο έλεγχο, το set συμμορφώνεται
                    με την παρούσα πολιτική. Το Deseo Radio μπορεί να ζητήσει αντικατάσταση track ή set αν προκύψει
                    εύλογη ένδειξη παραβίασης.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>7. Πνευματικά και συγγενικά δικαιώματα</h2>
                <p>
                    Ο DJ δηλώνει ότι έχει το δικαίωμα να παραδώσει το συγκεκριμένο αρχείο / DJ mix στο Deseo Radio
                    και ότι δεν θα συμπεριλάβει εν γνώσει του παράνομα bootlegs, leaked recordings, μη εξουσιοδοτημένες
                    κυκλοφορίες ή άλλο υλικό του οποίου η κατοχή ή διάθεση είναι παράνομη.
                </p>
                <p>
                    Η δήλωση αυτή δεν σημαίνει ότι ο DJ μεταβιβάζει στο Deseo Radio δικαιώματα τρίτων που δεν του
                    ανήκουν. Τυχόν άδειες ή αμοιβές που επιβάλλονται στον ραδιοφωνικό φορέα από την εφαρμοστέα
                    νομοθεσία ή από οργανισμούς συλλογικής διαχείρισης αντιμετωπίζονται χωριστά από το Deseo Radio,
                    όπου και στον βαθμό που απαιτούνται.
                </p>
                <p>
                    Για πρωτότυπη μουσική, edits, intros, voiceovers ή άλλο υλικό που δημιουργεί ή ελέγχει ο ίδιος,
                    ο DJ παρέχει στο Deseo Radio μη αποκλειστική άδεια χρήσης μόνο στον βαθμό που απαιτείται για
                    τη μετάδοση, την τεχνική προσαρμογή και την προώθηση της συγκεκριμένης συμμετοχής.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>8. Δείγμα δουλειάς και πληροφορίες set</h2>
                <p>
                    Κατά την υποβολή του inquiry ο DJ παρέχει υποχρεωτικά ένα λειτουργικό link σε αντιπροσωπευτικό
                    DJ set, mix ή radio show. Το link χρησιμοποιείται αποκλειστικά για την αξιολόγηση του inquiry
                    από την ομάδα Deseo / ILUMA και πρέπει να παραμένει προσβάσιμο χωρίς να απαιτείται μη δηλωμένο login.
                </p>
                <p>
                    Tracklist ή πρόσθετες πληροφορίες του τελικού set μπορούν να ζητηθούν αργότερα από την ομάδα
                    Deseo / ILUMA, μετά την επιλογή του DJ και πριν από τη μετάδοση.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>9. Περιεχόμενο που δεν γίνεται δεκτό</h2>
                <p>Το Deseo Radio μπορεί να αρνηθεί ή να διακόψει μετάδοση που περιλαμβάνει, μεταξύ άλλων:</p>
                <ul>
                    <li>παράνομο, δυσφημιστικό ή παραπλανητικό περιεχόμενο,</li>
                    <li>ρητορική μίσους, απειλές ή στοχοποίηση προσώπων / ομάδων,</li>
                    <li>μη εξουσιοδοτημένη εμπορική διαφήμιση, sponsor messages ή paid placement,</li>
                    <li>περιεχόμενο που δημιουργεί σοβαρό νομικό ή reputational risk για το Deseo Radio / ILUMA,</li>
                    <li>υλικό που παραβιάζει τους παρόντες όρους ή την πολιτική AI.</li>
                </ul>
                <p>
                    Τυχόν explicit content πρέπει να γνωστοποιείται πριν από την παράδοση όταν μπορεί να απαιτεί
                    editorial ή χρονικό περιορισμό.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>10. Παροχές προς τους DJs, προώθηση και promotional assets</h2>
                <p>
                    Στο πλαίσιο της Season 6, το Deseo Radio και η ILUMA Digital Agency παρέχουν στους επιλεγμένους DJs
                    μη χρηματικές υπηρεσίες και promotional υποστήριξη που συνδέονται άμεσα με τη συμμετοχή τους στο πρόγραμμα.
                    Οι παροχές αυτές μπορεί να περιλαμβάνουν:
                </p>
                <ul>
                    <li>προγραμματισμένη ραδιοφωνική μετάδοση του DJ set και έκθεση στο υφιστάμενο ακροατήριο / κοινό του Deseo Radio,</li>
                    <li>προβολή της συμμετοχής μέσα από τα επίσημα ψηφιακά κανάλια, social media και λοιπά owned media του Deseo Radio / ILUMA Radios,</li>
                    <li>προβολή μέσω newsletter της ILUMA Digital Agency, όπου αυτό εντάσσεται στο εκάστοτε editorial και promotional πλάνο,</li>
                    <li>χορηγούμενες προωθήσεις (sponsored / paid promotion) επιλεγμένων posts, stories ή λοιπών promotional ενεργειών, στο πλαίσιο της εκάστοτε καμπάνιας και του διαθέσιμου media plan,</li>
                    <li>γραφιστικές και δημιουργικές υπηρεσίες από την ILUMA για posters, stories, social posts, covers και λοιπά promotional assets που αφορούν τη συγκεκριμένη συμμετοχή,</li>
                    <li>editorial και promotional υποστήριξη για την παρουσίαση του DJ, του artist name και του προγραμματισμένου slot.</li>
                </ul>
                <p>
                    Οι παραπάνω παροχές αποτελούν μέρος της συνολικής υποστήριξης της συμμετοχής και δεν συνιστούν
                    εγγύηση συγκεκριμένου αριθμού ακροατών, impressions, clicks, followers ή άλλου μετρήσιμου αποτελέσματος.
                </p>
                <p>
                    Για να είναι δυνατή η παραπάνω προβολή, ο DJ παρέχει στο Deseo Radio και στην ILUMA Digital Agency
                    μη αποκλειστική, χωρίς πρόσθετη αμοιβή άδεια να χρησιμοποιούν το artist name, τη φωτογραφία, το bio
                    και τα στοιχεία του slot για:
                </p>
                <ul>
                    <li>παρουσίαση της συμμετοχής στο deseoradio.com,</li>
                    <li>δημιουργία και διανομή Season 6 posters, stories, posts και λοιπών promotional assets,</li>
                    <li>δημοσίευση, repost και χορηγούμενη προώθηση στα επίσημα social media του Deseo Radio / ILUMA Radios,</li>
                    <li>αρχειακή παρουσίαση της συγκεκριμένης Season 6 συμμετοχής.</li>
                </ul>
                <p>
                    Η άδεια δεν επιτρέπει άσχετη εμπορική εκμετάλλευση της εικόνας του DJ για τρίτα προϊόντα ή
                    καμπάνιες χωρίς ξεχωριστή συμφωνία.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>11. Promotional υλικό από ILUMA</h2>
                <p>
                    Η ILUMA Digital Agency μπορεί να ετοιμάζει promotional assets με το artist name, την ημέρα και
                    την ώρα μετάδοσης. Τα assets παρέχονται για κοινοποίηση / repost από τον DJ. Εκτός αν συμφωνηθεί
                    διαφορετικά, η κοινοποίηση από τον DJ είναι επιθυμητή αλλά δεν αποτελεί αμειβόμενη διαφημιστική
                    υποχρέωση.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>12. Editorial και τεχνική διαχείριση</h2>
                <p>
                    Το Deseo Radio μπορεί να εφαρμόσει τεχνικό normalization, fades, station IDs, metadata ή άλλες
                    εύλογες τεχνικές προσαρμογές που απαιτούνται για ομαλή ραδιοφωνική μετάδοση, χωρίς να αλλοιώνεται
                    ουσιωδώς η καλλιτεχνική ταυτότητα του set.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>13. Ακύρωση και διακοπή συμμετοχής</h2>
                <p>
                    Ο DJ μπορεί να ζητήσει ακύρωση επικοινωνώντας εγκαίρως στο
                    <a href="mailto:radio@iluma.gr">radio@iluma.gr</a>. Το Deseo Radio μπορεί να ακυρώσει ή να
                    αναστείλει συμμετοχή όταν παραβιάζονται οι όροι, δεν παραδίδεται εγκαίρως το set ή υπάρχει
                    ουσιώδες τεχνικό / νομικό πρόβλημα.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>14. Ευθύνη</h2>
                <p>
                    Κάθε μέρος ευθύνεται για τις δικές του πράξεις και υποχρεώσεις. Ο DJ ευθύνεται για ανακριβείς
                    δηλώσεις ή για υλικό που παραδίδει κατά παράβαση των υποχρεώσεών του στους παρόντες όρους.
                    Στον βαθμό που επιτρέπεται από τον νόμο, ο DJ οφείλει να συνεργαστεί εύλογα για την επίλυση
                    claim τρίτου που προκύπτει άμεσα από δική του αποδεδειγμένη παραβίαση.
                </p>
                <p>
                    Καμία διάταξη δεν περιορίζει ευθύνη που δεν επιτρέπεται να περιοριστεί βάσει αναγκαστικού δικαίου.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>15. Προσωπικά δεδομένα</h2>
                <p>
                    Η επεξεργασία των στοιχείων που υποβάλλονται μέσω της φόρμας περιγράφεται στην
                    <a href="/privacy">Πολιτική Απορρήτου</a>. Η αποδοχή των όρων συνεργασίας και η ενημέρωση
                    για την επεξεργασία προσωπικών δεδομένων καταγράφονται με version και timestamp.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>16. Ηλεκτρονική αποδοχή και αποδεικτικά</h2>
                <p>
                    Η επιλογή του σχετικού checkbox και η επιτυχής υποβολή της φόρμας αποτελούν ηλεκτρονική
                    αποδοχή της συγκεκριμένης έκδοσης των όρων. Το σύστημα διατηρεί την έκδοση των όρων και
                    την ημερομηνία / ώρα αποδοχής μαζί με την αίτηση.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>17. Τροποποιήσεις</h2>
                <p>
                    Η ILUMA / Deseo μπορεί να ενημερώνει τους όρους για μελλοντικές αιτήσεις. Ουσιώδης αλλαγή
                    που επηρεάζει ήδη υποβληθείσα συμμετοχή δεν εφαρμόζεται αναδρομικά χωρίς κατάλληλη ενημέρωση
                    και, όπου απαιτείται, νέα αποδοχή.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>18. Εφαρμοστέο δίκαιο</h2>
                <p>
                    Οι παρόντες όροι διέπονται από το ελληνικό δίκαιο. Για διαφορές που δεν επιλύονται φιλικά,
                    αρμόδια είναι τα δικαστήρια που ορίζονται από την εφαρμοστέα ελληνική δικονομική νομοθεσία.
                    Τυχόν αναφορά σε συγκεκριμένη κατά τόπον αρμοδιότητα δεν υπερισχύει υποχρεωτικών κανόνων δικαίου.
                </p>
            </section>

            <section class="dj-legal-section dj-legal-callout">
                <h2>Σημαντική νομική σημείωση</h2>
                <p>
                    Οι όροι έχουν σχεδιαστεί για καθαρό operational framework της Season 6, όμως η τελική νομική
                    συμμόρφωση του ραδιοφωνικού project — ιδίως για μουσικές άδειες, συλλογική διαχείριση,
                    φορολογική / εταιρική ταυτότητα και ειδικές υποχρεώσεις της ILUMA — πρέπει να επαληθεύεται
                    από αρμόδιο επαγγελματία με βάση την πραγματική λειτουργία του σταθμού.
                </p>
            </section>
        </div>
    </div>
</main>
<?php else: ?>
<main id="main-content" class="dj-legal-page">
    <div class="dj-legal-shell">
        <header class="dj-legal-head">
            <span class="kicker">DESEO RADIO · SEASON 6</span>
            <h1 class="metal-title">DJ Participation<br>& Collaboration Terms</h1>
            <p>
                These terms govern the participation of DJs and producers in “Deseo Radio Season 6 · DJ Sets”.
                By electronically submitting the form at <a href="/dj?lang=en">/dj</a>, the participant confirms that
                they have read, understood and accepted these terms.
            </p>
            <div class="dj-legal-meta">
                <span>Season 6</span>
                <span>Version <?= deseo_e(DESEO_DJ_TERMS_VERSION) ?></span>
                <span>15 September 2026</span>
            </div>
        </header>

        <div class="dj-legal-content">
            <section class="dj-legal-section">
                <h2>1. Organizer and scope</h2>
                <p>Deseo Radio is a digital radio brand of ILUMA Digital Agency. Season 6 includes scheduled broadcasts of DJ Sets by selected DJs / producers in weekly slots. Participation is non-exclusive and relates only to the programming, promotion and archival presentation of this specific collaboration.</p>
            </section>

            <section class="dj-legal-section">
                <h2>2. No monetary compensation</h2>
                <p>Participation is voluntary and does not include salary, fee, royalties from Deseo Radio, reimbursement of expenses or any other monetary payment to the DJ, unless otherwise agreed in writing for a specific case.</p>
                <p>The absence of monetary compensation does not mean that no services or benefits are provided to participating DJs. The non-monetary support provided by Deseo Radio / ILUMA is described in detail in Section 10 and may include exposure to the Deseo Radio audience, promotional support, sponsored promotion and graphic / creative services relating to the participation.</p>
                <p>Participation does not in itself create an employment relationship, partnership, agency relationship or exclusivity. The actual nature of any relationship is always determined by applicable law and the relevant facts.</p>
            </section>

            <section class="dj-legal-section">
                <h2>3. Eligibility requirements</h2>
                <ul>
                    <li>The participant must be 18 years of age or older.</li>
                    <li>The submitted details, artist name, email, photo and bio must be accurate and lawful.</li>
                    <li>The photo must depict the DJ or be lawfully licensed for their use.</li>
                    <li>The bio must not contain misleading, offensive or defamatory information.</li>
                </ul>
            </section>

            <section class="dj-legal-section">
                <h2>4. Inquiry and preferred day / time</h2>
                <p>The form at /dj is solely an expression-of-interest inquiry. The DJ states a preferred slot, but submission does not constitute a booking, acceptance, participation confirmation or automatic placement in the Deseo Radio schedule.</p>
                <p>Multiple DJs may submit an inquiry for the same slot. The Deseo / ILUMA team reviews applications manually and decides which DJ to approve. A slot stops accepting new inquiries only after a DJ has manually been given “Approved” status for that slot.</p>
                <p>Final inclusion in the regular Radio Program is not performed by the inquiry form or inquiry system. It is handled separately and manually by Deseo Radio management.</p>
            </section>

            <section class="dj-legal-section">
                <h2>5. Permitted DJ Sets</h2>
                <p>The set may be new, previously recorded, or created specifically for Deseo Radio. The DJ remains free to distribute or broadcast the same set elsewhere unless otherwise agreed in writing for a specific production.</p>
                <p>Technical specifications, delivery method, final duration and delivery deadline may be communicated separately by the ILUMA / Deseo team after selection. Late or non-compliant delivery may result in withdrawal of approval.</p>
            </section>

            <section class="dj-legal-section dj-legal-callout">
                <h2>6. Strict prohibition of AI-generated music</h2>
                <p>The DJ Set must not include musical works, songs or recordings created wholly or partly using generative artificial intelligence in relation to composition, lyrics, vocals, core musical parts or the principal recording.</p>
                <p>Tools used exclusively for technical mastering, noise reduction, restoration, loudness matching or similar post-production functions are not, by themselves, treated as creating AI-generated music, provided they do not generate the material creative content.</p>
                <p>The DJ confirms that, to the best of their knowledge and after reasonable checking, the set complies with this policy. Deseo Radio may request replacement of a track or set where there is a reasonable indication of non-compliance.</p>
            </section>

            <section class="dj-legal-section">
                <h2>7. Copyright and related rights</h2>
                <p>The DJ confirms that they have the right to deliver the specific file / DJ mix to Deseo Radio and will not knowingly include illegal bootlegs, leaked recordings, unauthorized releases or other material whose possession or distribution is unlawful.</p>
                <p>This statement does not mean that the DJ transfers to Deseo Radio rights belonging to third parties. Any licenses or payments imposed on the radio operator by applicable law or collective management organizations are handled separately by Deseo Radio where and to the extent required.</p>
                <p>For original music, edits, intros, voiceovers or other material created or controlled by the DJ, the DJ grants Deseo Radio a non-exclusive license only to the extent necessary for broadcasting, technical adaptation and promotion of the specific participation.</p>
            </section>

            <section class="dj-legal-section">
                <h2>8. Work sample and set information</h2>
                <p>When submitting an inquiry, the DJ must provide a working link to a representative DJ set, mix or radio show. The link is used solely to evaluate the inquiry by the Deseo / ILUMA team and must remain accessible without requiring an undisclosed login.</p>
                <p>A tracklist or additional information about the final set may be requested later by Deseo / ILUMA after the DJ has been selected and before broadcast.</p>
            </section>

            <section class="dj-legal-section">
                <h2>9. Content that is not accepted</h2>
                <p>Deseo Radio may refuse or stop a broadcast that includes, among other things:</p>
                <ul>
                    <li>illegal, defamatory or misleading content,</li>
                    <li>hate speech, threats or targeting of persons / groups,</li>
                    <li>unauthorized commercial advertising, sponsor messages or paid placement,</li>
                    <li>content creating serious legal or reputational risk for Deseo Radio / ILUMA,</li>
                    <li>material that breaches these terms or the AI policy.</li>
                </ul>
                <p>Any explicit content should be disclosed before delivery where editorial or scheduling restrictions may be required.</p>
            </section>

            <section class="dj-legal-section">
                <h2>10. DJ benefits, promotion and promotional assets</h2>
                <p>As part of Season 6, Deseo Radio and ILUMA Digital Agency provide selected DJs with non-monetary services and promotional support directly connected to their participation. This support may include:</p>
                <ul>
                    <li>scheduled radio broadcast of the DJ set and exposure to Deseo Radio’s existing audience,</li>
                    <li>promotion through the official digital channels, social media and other owned media of Deseo Radio / ILUMA Radios,</li>
                    <li>promotion through the ILUMA Digital Agency newsletter where included in the relevant editorial and promotional plan,</li>
                    <li>sponsored / paid promotion of selected posts, stories or other promotional activity within the applicable campaign and available media plan,</li>
                    <li>graphic design and creative services by ILUMA for posters, stories, social posts, covers and other promotional assets relating to the participation,</li>
                    <li>editorial and promotional support for the presentation of the DJ, artist name and scheduled slot.</li>
                </ul>
                <p>These benefits form part of the overall support for participation and do not guarantee any specific number of listeners, impressions, clicks, followers or other measurable outcome.</p>
                <p>To enable this promotion, the DJ grants Deseo Radio and ILUMA Digital Agency a non-exclusive, royalty-free license to use the artist name, photo, bio and slot information for:</p>
                <ul>
                    <li>presentation of the participation on deseoradio.com,</li>
                    <li>creation and distribution of Season 6 posters, stories, posts and other promotional assets,</li>
                    <li>publication, reposting and sponsored promotion on official Deseo Radio / ILUMA Radios social media,</li>
                    <li>archival presentation of the specific Season 6 participation.</li>
                </ul>
                <p>This license does not permit unrelated commercial exploitation of the DJ’s image for third-party products or campaigns without a separate agreement.</p>
            </section>

            <section class="dj-legal-section">
                <h2>11. Promotional material from ILUMA</h2>
                <p>ILUMA Digital Agency may prepare promotional assets featuring the artist name and broadcast day / time. These assets are supplied for sharing or reposting by the DJ. Unless otherwise agreed, sharing by the DJ is encouraged but does not constitute a paid advertising obligation.</p>
            </section>

            <section class="dj-legal-section">
                <h2>12. Editorial and technical management</h2>
                <p>Deseo Radio may apply technical normalization, fades, station IDs, metadata or other reasonable technical adjustments required for smooth radio broadcasting, provided the artistic identity of the set is not materially altered.</p>
            </section>

            <section class="dj-legal-section">
                <h2>13. Cancellation and termination of participation</h2>
                <p>The DJ may request cancellation by contacting <a href="mailto:radio@iluma.gr">radio@iluma.gr</a> in good time. Deseo Radio may cancel or suspend participation where the terms are breached, the set is not delivered on time, or a material technical / legal issue exists.</p>
            </section>

            <section class="dj-legal-section">
                <h2>14. Liability</h2>
                <p>Each party is responsible for its own acts and obligations. The DJ is responsible for inaccurate statements or for material delivered in breach of their obligations under these terms. To the extent permitted by law, the DJ must reasonably cooperate in resolving any third-party claim arising directly from their proven breach.</p>
                <p>Nothing in these terms limits liability that cannot lawfully be limited under mandatory law.</p>
            </section>

            <section class="dj-legal-section">
                <h2>15. Personal data</h2>
                <p>The processing of information submitted through the form is described in the <a href="/privacy?lang=en">Privacy Policy</a>. Acceptance of the collaboration terms and acknowledgment of personal-data processing are recorded with a version and timestamp.</p>
            </section>

            <section class="dj-legal-section">
                <h2>16. Electronic acceptance and records</h2>
                <p>Selecting the relevant checkbox and successfully submitting the form constitutes electronic acceptance of the specific version of these terms. The system retains the terms version and date / time of acceptance together with the application.</p>
            </section>

            <section class="dj-legal-section">
                <h2>17. Amendments</h2>
                <p>ILUMA / Deseo may update these terms for future applications. A material change affecting an already submitted participation will not be applied retroactively without appropriate notice and, where required, renewed acceptance.</p>
            </section>

            <section class="dj-legal-section">
                <h2>18. Governing law</h2>
                <p>These terms are governed by Greek law. Disputes that cannot be resolved amicably are subject to the courts determined by applicable Greek procedural law. Any reference to a specific local jurisdiction does not override mandatory rules of law.</p>
            </section>

            <section class="dj-legal-section dj-legal-callout">
                <h2>Important legal note</h2>
                <p>These terms are designed to provide a clear operational framework for Season 6. Final legal compliance of the radio project — particularly in relation to music licensing, collective rights management, tax / corporate identity and ILUMA’s specific obligations — should be verified by a qualified professional based on the station’s actual operation.</p>
            </section>
        </div>
    </div>
</main>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
