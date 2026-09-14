<?php
declare(strict_types=1);

const DESEO_LANGUAGES = ['el', 'en'];

function deseo_detect_language(): string {
    $requested = strtolower(trim((string)($_GET['lang'] ?? '')));
    if (in_array($requested, DESEO_LANGUAGES, true)) {
        if (!headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

            setcookie('deseo_lang', $requested, [
                'expires' => time() + 31536000,
                'path' => '/',
                'secure' => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        return $requested;
    }

    $saved = strtolower(trim((string)($_COOKIE['deseo_lang'] ?? '')));
    return in_array($saved, DESEO_LANGUAGES, true) ? $saved : 'el';
}

$GLOBALS['deseo_lang'] = deseo_detect_language();

function deseo_lang(): string {
    return (string)($GLOBALS['deseo_lang'] ?? 'el');
}

function deseo_lang_url(string $language): string {
    if (!in_array($language, DESEO_LANGUAGES, true)) $language = 'el';
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') $path = '/';
    return $path . '?lang=' . rawurlencode($language);
}

function deseo_t(string $key): string {
    static $t = [
        'el' => [
            'skip.content' => 'Μετάβαση στο κύριο περιεχόμενο',
            'header.home' => 'Αρχική Deseo Radio',
            'header.live' => 'LIVE',
            'header.live_aria' => 'Μετάβαση στο live player',
            'header.instagram' => 'Deseo Radio στο Instagram',
            'header.facebook' => 'Deseo Radio στο Facebook',
            'lang.switch_to_el' => 'Ελληνικά',
            'lang.switch_to_en' => 'English',

            'hero.kicker' => 'DESEO RADIO · HOUSE MUSIC · ATHENS',
            'hero.title' => 'Το Soundtrack της ζωής σου.',
            'hero.text' => 'House, Afro House, Organic House και επιλεγμένη electronic μουσική σε συνεχή 24/7 ροή.',
            'hero.listen' => 'Άκου live',
            'hero.network' => 'ILUMA Radios',
            'hero.now_on_air' => 'NOW ON AIR',
            'hero.non_stop' => 'DESEO AUTO DJ',
            'hero.live_broadcast' => 'Live broadcast',

            'discover.kicker' => 'DESEO MUSIC · ON AIR',
            'discover.title' => 'Δες τι παίζει.',
            'discover.text' => 'Hot tracks, σημερινό πρόγραμμα και επιλεγμένες playlists του Deseo Radio.',
            'program.panel_title' => 'ΣΗΜΕΡΑ',

            'discover.kicker' => 'DESEO MUSIC · ON AIR',
            'discover.title' => "See what's playing.",
            'discover.text' => 'Hot tracks, today’s schedule and selected Deseo Radio playlists.',
            'program.panel_title' => 'TODAY',

            'airplay.kicker' => 'HOT TRACKS',
            'airplay.title' => 'Αυτά παίζουν τώρα.',
            'airplay.text' => 'Οι επιλογές που ξεχωρίζουν αυτή την εβδομάδα στο Deseo.',
            'airplay.empty' => 'Το νέο airplay chart ετοιμάζεται.',
            'airplay.spotify' => 'Άνοιγμα στο Spotify',

            'program.kicker' => 'ΣΗΜΕΡΑ · DESEO RADIO',
            'program.title' => 'Στον αέρα σήμερα.',
            'program.text' => 'Το σημερινό πρόγραμμα σε ώρα Ελλάδας.',
            'program.on_air' => 'ON AIR NOW',
            'program.live_set' => 'LIVE SET',
            'program.empty' => 'Σήμερα το Deseo συνεχίζει με non-stop μουσική.',
            'program.next' => 'Επόμενο live',

            'playlists.kicker' => 'PLAYLISTS · DESEO CURATION',
            'playlists.title' => 'Selections για κάθε στιγμή.',
            'playlists.text' => 'Επιλεγμένες Spotify playlists από το Deseo Radio.',
            'playlists.open' => 'Άνοιγμα στο Spotify ↗',
            'playlists.empty' => 'Οι playlists ετοιμάζονται.',

            'about.kicker' => 'ABOUT DESEO',
            'about.title' => 'House music. Χωρίς περιττό θόρυβο.',
            'about.text' => 'Το Deseo Radio είναι digital radio brand της ILUMA Digital Agency και μέρος του ILUMA Radios network. Παίζει House, Afro House, Organic House και επιλεγμένη electronic μουσική 24/7.',
            'about.iluma' => 'Δες το Deseo στο ILUMA Radios ↗',

            'partners.kicker' => 'LISTEN EVERYWHERE',
            'partners.title' => 'Άκου Deseo παντού.',
            'partners.text' => 'Στο site, στο iRadios και σε επιλεγμένες radio platforms.',

            'faq.kicker' => 'DESEO RADIO · FAQ',
            'faq.title' => 'Ό,τι αξίζει να ξέρεις.',
            'faq.text' => 'Σύντομα και καθαρά: τι είναι το Deseo, τι παίζει και πού το ακούς.',
            'faq.q1' => 'Τι είναι το Deseo Radio;',
            'faq.a1' => 'Το Deseo Radio είναι ένα 24/7 digital radio με έδρα την Αθήνα και μουσική ταυτότητα προσανατολισμένη στη σύγχρονη House σκηνή.',
            'faq.q2' => 'Τι μουσική παίζει το Deseo Radio;',
            'faq.a2' => 'Παίζει House, Afro House, Organic House και επιλεγμένη electronic μουσική, με συνεχή radio ροή.',
            'faq.q3' => 'Παίζει το Deseo Radio όλο το 24ωρο;',
            'faq.a3' => 'Ναι. Το Deseo Radio μεταδίδει live streaming 24/7.',
            'faq.q4' => 'Πού μπορώ να ακούσω Deseo Radio;',
            'faq.a4' => 'Μπορείς να ακούσεις από το deseoradio.com, μέσω iRadios και από επιλεγμένες διεθνείς radio platforms.',
            'faq.q5' => 'Σε ποιον ανήκει το Deseo Radio;',
            'faq.a5' => 'Το Deseo Radio είναι ιδιόκτητο digital radio brand της ILUMA Digital Agency και αποτελεί μέρος του ILUMA Radios network.',

            'advertise.kicker' => 'FOR BRANDS',
            'advertise.title' => 'Το brand σου. Στον σωστό ήχο.',
            'advertise.text' => 'Radio advertising, sponsorships και branded audio με δημιουργική επιμέλεια από την ILUMA.',
            'advertise.cta' => 'Επικοινωνία για διαφήμιση ↗',

            'footer.tagline' => 'Το Soundtrack της ζωής σου',
            'footer.contact' => 'Επικοινωνία',
            'footer.follow' => 'Ακολούθησέ μας',
            'footer.listen' => 'Άκου',
            'footer.live_player' => 'Live player',
            'footer.today_program' => 'Σημερινό πρόγραμμα',
            'footer.playlists' => 'Playlists',
            'footer.faq' => 'FAQ',
            'footer.powered' => 'Handcrafted by',

            'install.title' => 'Deseo Radio App',
            'install.subtitle' => 'Εγκατάσταση στη συσκευή',
            'cookie.title' => 'Το απόρρητό σου, η επιλογή σου.',
            'cookie.text' => 'Χρησιμοποιούμε απαραίτητο local storage και, μόνο με τη συγκατάθεσή σου, analytics, push και marketing υπηρεσίες.',
            'cookie.analytics' => 'Analytics & push υπηρεσίες',
            'cookie.marketing' => 'Marketing & εξατομίκευση',
            'cookie.accept' => 'Αποδοχή',
            'cookie.customize' => 'Ρυθμίσεις',
            'cookie.reject' => 'Απόρριψη προαιρετικών',

            'day.1' => 'Δευτέρα',
            'day.2' => 'Τρίτη',
            'day.3' => 'Τετάρτη',
            'day.4' => 'Πέμπτη',
            'day.5' => 'Παρασκευή',
            'day.6' => 'Σάββατο',
            'day.7' => 'Κυριακή',
        ],
        'en' => [
            'skip.content' => 'Skip to main content',
            'header.home' => 'Deseo Radio home',
            'header.live' => 'LIVE',
            'header.live_aria' => 'Go to the live player',
            'header.instagram' => 'Deseo Radio on Instagram',
            'header.facebook' => 'Deseo Radio on Facebook',
            'lang.switch_to_el' => 'Greek',
            'lang.switch_to_en' => 'English',

            'hero.kicker' => 'DESEO RADIO · HOUSE MUSIC · ATHENS',
            'hero.title' => 'The Soundtrack of your life.',
            'hero.text' => 'House, Afro House, Organic House and selected electronic music in a continuous 24/7 flow.',
            'hero.listen' => 'Listen live',
            'hero.network' => 'ILUMA Radios',
            'hero.now_on_air' => 'NOW ON AIR',
            'hero.non_stop' => 'DESEO AUTO DJ',
            'hero.live_broadcast' => 'Live broadcast',

            'airplay.kicker' => 'HOT TRACKS',
            'airplay.title' => 'What is playing now.',
            'airplay.text' => 'The selections standing out this week on Deseo.',
            'airplay.empty' => 'The new airplay chart is on the way.',
            'airplay.spotify' => 'Open on Spotify',

            'program.kicker' => 'TODAY · DESEO RADIO',
            'program.title' => 'On air today.',
            'program.text' => 'Today’s schedule in Greece time.',
            'program.on_air' => 'ON AIR NOW',
            'program.live_set' => 'LIVE SET',
            'program.empty' => 'Deseo continues today with non-stop music.',
            'program.next' => 'Next live',

            'playlists.kicker' => 'PLAYLISTS · DESEO CURATION',
            'playlists.title' => 'Selections for every moment.',
            'playlists.text' => 'Selected Spotify playlists from Deseo Radio.',
            'playlists.open' => 'Open on Spotify ↗',
            'playlists.empty' => 'Playlists are on the way.',

            'about.kicker' => 'ABOUT DESEO',
            'about.title' => 'House music. No unnecessary noise.',
            'about.text' => 'Deseo Radio is a digital radio brand owned by ILUMA Digital Agency and part of the ILUMA Radios network. It plays House, Afro House, Organic House and selected electronic music 24/7.',
            'about.iluma' => 'View Deseo on ILUMA Radios ↗',

            'partners.kicker' => 'LISTEN EVERYWHERE',
            'partners.title' => 'Listen to Deseo everywhere.',
            'partners.text' => 'On this website, iRadios and selected radio platforms.',

            'faq.kicker' => 'DESEO RADIO · FAQ',
            'faq.title' => 'Everything worth knowing.',
            'faq.text' => 'Short and clear: what Deseo is, what it plays and where to listen.',
            'faq.q1' => 'What is Deseo Radio?',
            'faq.a1' => 'Deseo Radio is a 24/7 digital radio based in Athens with a music identity focused on the contemporary House scene.',
            'faq.q2' => 'What music does Deseo Radio play?',
            'faq.a2' => 'It plays House, Afro House, Organic House and selected electronic music in a continuous radio flow.',
            'faq.q3' => 'Does Deseo Radio broadcast 24/7?',
            'faq.a3' => 'Yes. Deseo Radio provides live streaming 24/7.',
            'faq.q4' => 'Where can I listen to Deseo Radio?',
            'faq.a4' => 'Listen on deseoradio.com, through iRadios and selected international radio platforms.',
            'faq.q5' => 'Who owns Deseo Radio?',
            'faq.a5' => 'Deseo Radio is an owned digital radio brand of ILUMA Digital Agency and part of the ILUMA Radios network.',

            'advertise.kicker' => 'FOR BRANDS',
            'advertise.title' => 'Your brand. In the right sound.',
            'advertise.text' => 'Radio advertising, sponsorships and branded audio with creative direction by ILUMA.',
            'advertise.cta' => 'Advertising enquiries ↗',

            'footer.tagline' => 'The Soundtrack of your life',
            'footer.contact' => 'Contact',
            'footer.follow' => 'Follow',
            'footer.listen' => 'Listen',
            'footer.live_player' => 'Live player',
            'footer.today_program' => 'Today’s program',
            'footer.playlists' => 'Playlists',
            'footer.faq' => 'FAQ',
            'footer.powered' => 'Handcrafted by',

            'install.title' => 'Deseo Radio App',
            'install.subtitle' => 'Install on your device',
            'cookie.title' => 'Your privacy, your choice.',
            'cookie.text' => 'We use essential local storage and, only with your consent, analytics, push and marketing services.',
            'cookie.analytics' => 'Analytics & push services',
            'cookie.marketing' => 'Marketing & personalization',
            'cookie.accept' => 'Accept',
            'cookie.customize' => 'Customize',
            'cookie.reject' => 'Reject optional',

            'day.1' => 'Monday',
            'day.2' => 'Tuesday',
            'day.3' => 'Wednesday',
            'day.4' => 'Thursday',
            'day.5' => 'Friday',
            'day.6' => 'Saturday',
            'day.7' => 'Sunday',
        ],
    ];

    $language = deseo_lang();
    return $t[$language][$key] ?? $t['el'][$key] ?? $key;
}

function deseo_t_day(int $day): string {
    return deseo_t('day.' . max(1, min(7, $day)));
}
