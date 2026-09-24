<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/dj-season.php';

function deseo_e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$meta_title = deseo_lang() === 'en' ? 'Privacy Policy · Deseo Radio' : 'Πολιτική Απορρήτου · Deseo Radio';
$meta_desc = deseo_lang() === 'en'
    ? 'Deseo Radio privacy policy and information about personal data for the Season 6 DJ application.'
    : 'Πολιτική απορρήτου του Deseo Radio και ενημέρωση για τα προσωπικά δεδομένα της Season 6 DJ application.';
$meta_canonical = 'https://deseoradio.com/privacy';
$extra_styles = ['/assets/css/djs.css'];

require_once __DIR__ . '/includes/head-meta.php';
require_once __DIR__ . '/includes/header.php';
?>

<?php if (deseo_lang() !== 'en'): ?>
<main id="main-content" class="dj-legal-page">
    <div class="dj-legal-shell">
        <header class="dj-legal-head">
            <span class="kicker">DESEO RADIO · PRIVACY</span>
            <h1 class="metal-title">Πολιτική<br>Απορρήτου</h1>
            <p>
                Η παρούσα ενημέρωση εξηγεί πώς το Deseo Radio / ILUMA Digital Agency επεξεργάζεται προσωπικά
                δεδομένα μέσω του site και ειδικά μέσω της φόρμας “Season 6 · DJ Sets”.
            </p>
            <div class="dj-legal-meta">
                <span>Έκδοση <?= deseo_e(DESEO_DJ_PRIVACY_VERSION) ?></span>
                <span>14 Σεπτεμβρίου 2026</span>
            </div>
        </header>

        <div class="dj-legal-content">
            <section class="dj-legal-section">
                <h2>1. Υπεύθυνος επεξεργασίας και επικοινωνία</h2>
                <p>
                    Για τη λειτουργία του Deseo Radio και της Season 6, υπεύθυνος για την επεξεργασία των σχετικών
                    δεδομένων είναι η ILUMA Digital Agency ως φορέας λειτουργίας του Deseo Radio.
                </p>
                <ul>
                    <li>Email επικοινωνίας: <a href="mailto:radio@iluma.gr">radio@iluma.gr</a></li>
                    <li>Τηλέφωνο: <a href="tel:+302103000825">+30 210 300 0825</a></li>
                    <li>Διεύθυνση που εμφανίζεται στο site: 1st Moschonision st., Egaleo, 12242, Greece</li>
                </ul>
            </section>

            <section class="dj-legal-section">
                <h2>2. Δεδομένα που συλλέγονται από DJs / producers</h2>
                <p>Μέσω της φόρμας Season 6 μπορεί να συλλέγονται:</p>
                <ul>
                    <li>ονοματεπώνυμο, DJ / artist name και email,</li>
                    <li>Instagram / social link και προαιρετικό website, SoundCloud ή Mixcloud,</li>
                    <li>φωτογραφία και σύντομο βιογραφικό έως 1.000 χαρακτήρες,</li>
                    <li>επιλεγμένο slot, τύπος DJ set και υποχρεωτικό link σε δείγμα δουλειάς (DJ set, mix ή radio show),</li>
                    <li>καταγραφή της έκδοσης και του χρόνου αποδοχής των όρων και της παρούσας ενημέρωσης.</li>
                </ul>
                <p>
                    Δεν ζητάμε ειδικές κατηγορίες προσωπικών δεδομένων και παρακαλούμε να μην περιλαμβάνονται
                    τέτοια δεδομένα στο bio ή σε ελεύθερα πεδία.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>3. Σκοποί επεξεργασίας</h2>
                <p>Τα στοιχεία χρησιμοποιούνται για:</p>
                <ul>
                    <li>διαχείριση του inquiry και της προτίμησης Season 6 slot,</li>
                    <li>επικοινωνία για παράδοση set, τεχνικές οδηγίες, αλλαγές προγράμματος ή ζητήματα συμμόρφωσης,</li>
                    <li>δημιουργία και δημοσίευση profile / promotional assets της συμμετοχής,</li>
                    <li>αξιολόγηση του δείγματος δουλειάς, rights reporting και διαχείριση πιθανών claims,</li>
                    <li>ασφάλεια, καταγραφή της χειροκίνητης επιλογής / έγκρισης και απόδειξη της αποδοχής των όρων.</li>
                </ul>
            </section>

            <section class="dj-legal-section">
                <h2>4. Νομικές βάσεις</h2>
                <p>
                    Ανάλογα με την επεξεργασία, η νομική βάση μπορεί να είναι η λήψη μέτρων κατόπιν αιτήματος του
                    συμμετέχοντος και η εκτέλεση της συμφωνίας συμμετοχής, η συμμόρφωση με νομικές υποχρεώσεις,
                    καθώς και το έννομο συμφέρον για ασφαλή λειτουργία, τεκμηρίωση και υπεράσπιση νομικών αξιώσεων.
                </p>
                <p>
                    Όπου απαιτείται συγκατάθεση για ξεχωριστό σκοπό — για παράδειγμα μελλοντικό marketing που
                    δεν είναι αναγκαίο για τη Season 6 — αυτή θα ζητείται χωριστά και προαιρετικά.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>5. Δημοσίευση φωτογραφίας, artist name και bio</h2>
                <p>
                    Για DJs που συμμετέχουν στη Season 6, το artist name, η φωτογραφία, το bio και το πρόγραμμα
                    μπορούν να χρησιμοποιηθούν στο deseoradio.com και στα επίσημα social media / promotional
                    assets του Deseo Radio και της ILUMA, σύμφωνα με τους
                    <a href="/djterms">Όρους Συμμετοχής</a>.
                </p>
                <p>
                    Το προσωπικό email και το πλήρες ονοματεπώνυμο δεν δημοσιεύονται ως μέρος του public slot picker,
                    εκτός αν ο ίδιος ο DJ τα έχει ήδη καταστήσει δημόσια ως επαγγελματικά στοιχεία και υπάρχει
                    συγκεκριμένος λόγος να χρησιμοποιηθούν.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>6. Ποιοι έχουν πρόσβαση</h2>
                <p>
                    Πρόσβαση στα δεδομένα Season 6 έχουν εξουσιοδοτημένα μέλη της ILUMA / Deseo που χρειάζονται
                    τις πληροφορίες για παραγωγή, προγραμματισμό, επικοινωνία, design assets ή τεχνική υποστήριξη.
                    Τα δεδομένα μπορεί επίσης να υποβάλλονται σε επεξεργασία από τεχνικούς παρόχους hosting,
                    email, analytics ή άλλες υποδομές, μόνο στον βαθμό που είναι απαραίτητο και σύμφωνα με τις
                    αντίστοιχες συμβατικές / νομικές υποχρεώσεις.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>7. Χρόνος διατήρησης</h2>
                <p>
                    Τα στοιχεία των Season 6 αιτήσεων διατηρούνται όσο είναι αναγκαία για την οργάνωση και
                    ολοκλήρωση της σεζόν και, κατά κανόνα, έως 24 μήνες μετά το τέλος της για operational records,
                    τυχόν claims και τεκμηρίωση της συνεργασίας.
                </p>
                <p>
                    Δημόσια promotional posts ή αρχειακές αναφορές μπορούν να παραμείνουν περισσότερο ως ιστορικό
                    της συγκεκριμένης ραδιοφωνικής σεζόν, εφόσον αυτό είναι εύλογο και νόμιμο. Αιτήματα αφαίρεσης
                    εξετάζονται κατά περίπτωση, λαμβάνοντας υπόψη υποχρεωτικές νομικές ή αρχειακές ανάγκες.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>8. Ασφάλεια και slot privacy</h2>
                <p>
                    Το public inquiry interface εμφανίζει μόνο αν ένα slot είναι ανοικτό για inquiries ή έχει
                    κλείσει μετά από χειροκίνητη έγκριση DJ. Pending inquiries δεν δημοσιοποιούνται και δεν
                    κλειδώνουν slot. Το κανονικό Radio Program είναι ξεχωριστό σύστημα και δεν ενημερώνεται
                    αυτόματα από τη φόρμα Season 6.
                </p>
                <p>
                    Η φόρμα Season 6 προστατεύεται επίσης με Cloudflare Turnstile για τον περιορισμό automated
                    spam και κακόβουλων submissions. Η επαλήθευση του Turnstile γίνεται και server-side πριν
                    γίνει αποδεκτό το inquiry.
                </p>
                <p>
                    Η πρόσβαση στις αιτήσεις γίνεται μέσω του προστατευμένου ILUMA CMS. Παρότι εφαρμόζονται
                    κατάλληλα τεχνικά και οργανωτικά μέτρα, καμία διαδικτυακή υπηρεσία δεν μπορεί να εγγυηθεί
                    απόλυτη ασφάλεια.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>9. Cookies, analytics και μέτρηση επισκεψιμότητας</h2>
                <p>
                    Το site χρησιμοποιεί απαραίτητη τοπική αποθήκευση / cookies για βασικές λειτουργίες.
                    Για συγκεντρωτική μέτρηση επισκεψιμότητας και τεχνικής απόδοσης χρησιμοποιείται Cloudflare
                    Web Analytics. Η υπηρεσία λειτουργεί ως privacy-first web analytics και χρησιμοποιείται για
                    στοιχεία όπως page views, visits και performance metrics, χωρίς να χρησιμοποιείται από το
                    Deseo Radio για δημιουργία διαφημιστικού προφίλ επισκεπτών.
                </p>
                <p>
                    Τυχόν πρόσθετα analytics, push ή marketing εργαλεία υπόκεινται στις επιλογές που παρέχονται
                    στο cookie / privacy interface του site, όπου αυτό απαιτείται.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>10. Διεθνείς διαβιβάσεις</h2>
                <p>
                    Αν τεχνικός πάροχος επεξεργάζεται δεδομένα εκτός Ευρωπαϊκού Οικονομικού Χώρου, η ILUMA
                    επιδιώκει να χρησιμοποιούνται οι κατάλληλοι μηχανισμοί που προβλέπει η εφαρμοστέα νομοθεσία,
                    όπου απαιτείται.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>11. Δικαιώματα</h2>
                <p>
                    Ανάλογα με την περίπτωση, μπορείς να ζητήσεις πρόσβαση, διόρθωση, διαγραφή, περιορισμό
                    επεξεργασίας, φορητότητα ή να αντιταχθείς σε συγκεκριμένη επεξεργασία. Όπου η επεξεργασία
                    βασίζεται σε συγκατάθεση, μπορείς να την ανακαλέσεις για το μέλλον.
                </p>
                <p>
                    Για αίτημα σχετικό με τα προσωπικά σου δεδομένα, επικοινώνησε στο
                    <a href="mailto:radio@iluma.gr">radio@iluma.gr</a>. Για την ασφαλή διεκπεραίωση μπορεί να
                    ζητηθεί εύλογη επιβεβαίωση ταυτότητας.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>12. Καταγγελία σε εποπτική αρχή</h2>
                <p>
                    Έχεις δικαίωμα να απευθυνθείς στην Αρχή Προστασίας Δεδομένων Προσωπικού Χαρακτήρα,
                    εφόσον θεωρείς ότι η επεξεργασία προσωπικών δεδομένων παραβιάζει την εφαρμοστέα νομοθεσία.
                </p>
            </section>

            <section class="dj-legal-section">
                <h2>13. Αλλαγές στην πολιτική</h2>
                <p>
                    Η πολιτική μπορεί να ενημερώνεται όταν αλλάζει η λειτουργία του site, η Season 6 ή το
                    νομικό / τεχνικό πλαίσιο. Η τρέχουσα έκδοση και ημερομηνία εμφανίζονται στην κορυφή της σελίδας.
                    Για τη Season 6 η έκδοση που γνωστοποιήθηκε κατά την υποβολή αποθηκεύεται μαζί με την αίτηση.
                </p>
            </section>
        </div>
    </div>
</main>
<?php else: ?>
<main id="main-content" class="dj-legal-page">
    <div class="dj-legal-shell">
        <header class="dj-legal-head">
            <span class="kicker">DESEO RADIO · PRIVACY</span>
            <h1 class="metal-title">Privacy<br>Policy</h1>
            <p>
                This notice explains how Deseo Radio / ILUMA Digital Agency processes personal data through the website,
                and specifically through the “Season 6 · DJ Sets” application form.
            </p>
            <div class="dj-legal-meta">
                <span>Version <?= deseo_e(DESEO_DJ_PRIVACY_VERSION) ?></span>
                <span>14 September 2026</span>
            </div>
        </header>

        <div class="dj-legal-content">
            <section class="dj-legal-section">
                <h2>1. Data controller and contact details</h2>
                <p>For the operation of Deseo Radio and Season 6, ILUMA Digital Agency acts as the controller of the relevant personal data as the operator of Deseo Radio.</p>
                <ul>
                    <li>Contact email: <a href="mailto:radio@iluma.gr">radio@iluma.gr</a></li>
                    <li>Telephone: <a href="tel:+302103000825">+30 210 300 0825</a></li>
                    <li>Address shown on the website: 1st Moschonision st., Egaleo, 12242, Greece</li>
                </ul>
            </section>

            <section class="dj-legal-section">
                <h2>2. Data collected from DJs / producers</h2>
                <p>The Season 6 form may collect:</p>
                <ul>
                    <li>full name, DJ / artist name and email,</li>
                    <li>Instagram / social link and optional website, SoundCloud or Mixcloud,</li>
                    <li>photo and a short biography of up to 1,000 characters,</li>
                    <li>selected slot, DJ set type and a required link to a work sample (DJ set, mix or radio show),</li>
                    <li>the version and timestamp of acceptance of the terms and this privacy notice.</li>
                </ul>
                <p>We do not request special categories of personal data. Please do not include such information in your bio or other free-text fields.</p>
            </section>

            <section class="dj-legal-section">
                <h2>3. Purposes of processing</h2>
                <p>The information is used to:</p>
                <ul>
                    <li>manage the inquiry and preferred Season 6 slot,</li>
                    <li>communicate about set delivery, technical instructions, schedule changes or compliance issues,</li>
                    <li>create and publish profile / promotional assets relating to participation,</li>
                    <li>review work samples, handle rights reporting and manage potential claims,</li>
                    <li>support security, record manual selection / approval and document acceptance of the terms.</li>
                </ul>
            </section>

            <section class="dj-legal-section">
                <h2>4. Legal bases</h2>
                <p>Depending on the processing activity, the legal basis may include taking steps at the participant’s request and performing the participation agreement, compliance with legal obligations, and legitimate interests in secure operation, record-keeping and the establishment or defence of legal claims.</p>
                <p>Where consent is required for a separate purpose — for example future marketing that is not necessary for Season 6 — it will be requested separately and on an optional basis.</p>
            </section>

            <section class="dj-legal-section">
                <h2>5. Publication of photo, artist name and bio</h2>
                <p>For DJs participating in Season 6, the artist name, photo, bio and schedule may be used on deseoradio.com and in official social media / promotional assets of Deseo Radio and ILUMA, in accordance with the <a href="/djterms?lang=en">Participation Terms</a>.</p>
                <p>Personal email addresses and full legal names are not published as part of the public slot interface, unless the DJ has already made them public as professional contact information and there is a specific reason to use them.</p>
            </section>

            <section class="dj-legal-section">
                <h2>6. Who has access</h2>
                <p>Season 6 data may be accessed by authorized ILUMA / Deseo team members who need the information for production, scheduling, communication, design assets or technical support. Data may also be processed by technical providers for hosting, email, analytics or other infrastructure, only as necessary and subject to the applicable contractual and legal obligations.</p>
            </section>

            <section class="dj-legal-section">
                <h2>7. Retention period</h2>
                <p>Season 6 application data is retained for as long as necessary to organize and complete the season and, as a general rule, for up to 24 months after the end of the season for operational records, potential claims and documentation of the collaboration.</p>
                <p>Public promotional posts or archival references may remain available for longer as part of the historical record of the radio season where this is reasonable and lawful. Removal requests are reviewed case by case, taking into account any mandatory legal or archival requirements.</p>
            </section>

            <section class="dj-legal-section">
                <h2>8. Security and slot privacy</h2>
                <p>The public inquiry interface only indicates whether a slot is open for inquiries or has been closed following manual DJ approval. Pending inquiries are not published and do not lock a slot. The regular Radio Program is a separate system and is not updated automatically from the Season 6 form.</p>
                <p>The Season 6 form is also protected by Cloudflare Turnstile to reduce automated spam and malicious submissions. Turnstile verification is checked server-side before an inquiry is accepted.</p>
                <p>Applications are accessed through the protected ILUMA CMS. Although appropriate technical and organizational measures are used, no online service can guarantee absolute security.</p>
            </section>

            <section class="dj-legal-section">
                <h2>9. Cookies, analytics and traffic measurement</h2>
                <p>The website uses essential local storage / cookies for core functionality. Cloudflare Web Analytics is used for aggregated traffic and technical performance measurement. It is used as a privacy-focused analytics service for data such as page views, visits and performance metrics and is not used by Deseo Radio to create advertising profiles of visitors.</p>
                <p>Any additional analytics, push or marketing tools are subject to the choices provided in the website’s cookie / privacy interface where required.</p>
            </section>

            <section class="dj-legal-section">
                <h2>10. International transfers</h2>
                <p>If a technical provider processes data outside the European Economic Area, ILUMA seeks to use the appropriate safeguards provided by applicable law where required.</p>
            </section>

            <section class="dj-legal-section">
                <h2>11. Your rights</h2>
                <p>Depending on the circumstances, you may request access, correction, deletion, restriction of processing, portability, or object to certain processing. Where processing is based on consent, you may withdraw that consent for the future.</p>
                <p>For requests relating to your personal data, contact <a href="mailto:radio@iluma.gr">radio@iluma.gr</a>. Reasonable identity verification may be requested in order to handle the request securely.</p>
            </section>

            <section class="dj-legal-section">
                <h2>12. Complaint to a supervisory authority</h2>
                <p>You have the right to contact the Hellenic Data Protection Authority if you believe that the processing of your personal data infringes applicable data protection law.</p>
            </section>

            <section class="dj-legal-section">
                <h2>13. Changes to this policy</h2>
                <p>This policy may be updated when the website, Season 6, or the legal / technical framework changes. The current version and date are shown at the top of this page. For Season 6, the version disclosed at the time of submission is stored with the application.</p>
            </section>
        </div>
    </div>
</main>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
