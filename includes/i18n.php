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
    if (!in_array($language, DESEO_LANGUAGES, true)) {
        $language = 'el';
    }

    $params = $_GET;
    $params['lang'] = $language;

    return '/?' . http_build_query($params);
}

function deseo_canonical_url(?string $language = null): string {
    $language = $language ?: deseo_lang();
    return $language === 'en'
        ? 'https://deseoradio.com/?lang=en'
        : 'https://deseoradio.com/';
}

function deseo_t(string $key): string {
    static $translations = [
        'el' => [
            'skip.content' => 'Μετάβαση στο κύριο περιεχόμενο',
            'header.home' => 'Αρχική Deseo Radio',
            'header.live' => 'LIVE',
            'header.live_aria' => 'Μετάβαση στο live player',
            'header.instagram' => 'Deseo Radio στο Instagram',
            'header.facebook' => 'Deseo Radio στο Facebook',
            'lang.el' => 'ΕΛ',
            'lang.en' => 'EN',
            'lang.switch_to_el' => 'Ελληνικά',
            'lang.switch_to_en' => 'English',

            'meta.title' => 'Deseo Radio | Live House Music 24/7',
            'meta.description' => 'Άκου live Deseo Radio 24/7: House, Afro House, Deep House και electronic music. Δες ποιος DJ είναι on air, το σημερινό πρόγραμμα και το weekly airplay.',
            'meta.keywords' => 'Deseo Radio, house music, afro house, deep house, live radio, Ελλάδα',
            'schema.description' => 'Online ραδιοφωνικός σταθμός με House, Afro House και electronic music, 24/7.',

            'hero.sr_title' => 'Deseo Radio: Το soundtrack της ζωής σου',
            'hero.now_playing' => 'ΤΩΡΑ ΠΑΙΖΕΙ',
            'hero.now_on_air' => 'ΣΤΟΝ ΑΕΡΑ',
            'hero.sponsor' => 'ΧΟΡΗΓΟΣ',
            'hero.live_broadcast' => 'Ζωντανή εκπομπή',
            'hero.non_stop_mix' => 'Non-stop mix',
            'hero.auto_dj' => 'DESEO AUTO DJ',
            'hero.auto_dj_alt' => 'Deseo Radio Auto DJ',

            'program.eyebrow' => 'ΣΗΜΕΡΙΝΟ ΠΡΟΓΡΑΜΜΑ',
            'program.heading' => 'Το πρόγραμμα',
            'program.heading_em' => 'σήμερα.',
            'program.description' => 'Όλες οι ώρες εμφανίζονται σε ώρα Ελλάδας. Τα sets που περνούν τα μεσάνυχτα υποστηρίζονται αυτόματα.',
            'program.on_air_now' => 'ΣΤΟΝ ΑΕΡΑ ΤΩΡΑ',
            'program.live_set' => 'LIVE SET',
            'program.empty_title' => 'Non-stop Deseo mix',
            'program.empty_fallback' => 'Το live πρόγραμμα ενημερώνεται. Το stream παραμένει διαθέσιμο κανονικά.',
            'program.empty_none' => 'Δεν υπάρχει προγραμματισμένο live set σήμερα. Το Deseo συνεχίζει non-stop.',
            'program.next_live' => 'Επόμενο live',
            'day.1' => 'Δευτέρα',
            'day.2' => 'Τρίτη',
            'day.3' => 'Τετάρτη',
            'day.4' => 'Πέμπτη',
            'day.5' => 'Παρασκευή',
            'day.6' => 'Σάββατο',
            'day.7' => 'Κυριακή',

            'airplay.eyebrow' => 'WEEKLY ROTATION',
            'airplay.heading' => 'Deseo',
            'airplay.heading_em' => 'Airplay.',
            'airplay.description' => 'Τα tracks που ξεχωρίζουν αυτή την εβδομάδα στο Deseo Radio.',
            'airplay.selection' => 'Deseo Radio Selection',
            'airplay.spotify_aria' => 'Άνοιγμα στο Spotify',
            'airplay.empty_title' => 'Το νέο chart ετοιμάζεται',
            'airplay.empty_text' => 'Το stream λειτουργεί κανονικά. Η λίστα Airplay θα εμφανιστεί μόλις ανανεωθεί από το studio.',

            'partners.eyebrow' => 'ΑΚΟΥ ΠΑΝΤΟΥ',
            'partners.heading' => 'Βρες το Deseo',
            'partners.heading_em' => 'παντού.',
            'partners.description' => 'Άκου Deseo από το site ή από τις μεγαλύτερες radio platforms.',

            'advertise.eyebrow' => 'ΔΙΑΦΗΜΙΣΟΥ ΣΤΟ DESEO',
            'advertise.heading' => 'Βάλε το brand σου',
            'advertise.heading_em' => 'μέσα στον ήχο.',
            'advertise.text' => 'Σύνδεσε το brand σου με ένα focused κοινό που αγαπά House και electronic music, μέσα από tailor-made radio campaigns της ILUMA.',
            'advertise.cta' => 'Ξεκίνα καμπάνια ↗',

            'footer.tagline' => 'Το soundtrack της ζωής σου.',
            'footer.subtagline' => 'House music, ζωντανά από την Αθήνα.',
            'footer.contact' => 'Επικοινωνία',
            'footer.follow' => 'Ακολούθησέ μας',
            'footer.listen' => 'Άκου',
            'footer.live_player' => 'Live player',
            'footer.today_program' => 'Σημερινό πρόγραμμα',
            'footer.weekly_airplay' => 'Weekly airplay',
            'footer.powered' => 'Powered by',
            'install.title' => 'Εγκατάσταση Deseo App',
            'install.subtitle' => 'Γρήγορη πρόσβαση · Αρχική οθόνη',

            'cookie.title' => 'Το απόρρητό σου, η επιλογή σου.',
            'cookie.text' => 'Χρησιμοποιούμε απαραίτητο local storage για τη λειτουργία του site και, μόνο με τη συγκατάθεσή σου, analytics, push και marketing υπηρεσίες.',
            'cookie.analytics' => 'Analytics & push υπηρεσίες',
            'cookie.marketing' => 'Marketing & εξατομίκευση',
            'cookie.accept' => 'Αποδοχή',
            'cookie.customize' => 'Ρυθμίσεις',
            'cookie.reject' => 'Απόρριψη προαιρετικών',
        ],
        'en' => [
            'skip.content' => 'Skip to main content',
            'header.home' => 'Deseo Radio home',
            'header.live' => 'LIVE',
            'header.live_aria' => 'Go to the live player',
            'header.instagram' => 'Deseo Radio on Instagram',
            'header.facebook' => 'Deseo Radio on Facebook',
            'lang.el' => 'EL',
            'lang.en' => 'EN',
            'lang.switch_to_el' => 'Greek',
            'lang.switch_to_en' => 'English',

            'meta.title' => 'Deseo Radio | Live House Music 24/7',
            'meta.description' => 'Listen to Deseo Radio live 24/7: House, Afro House, Deep House and electronic music. See who is on air, today’s schedule and the weekly airplay chart.',
            'meta.keywords' => 'Deseo Radio, house music, afro house, deep house, live radio, Greece',
            'schema.description' => 'Online radio station broadcasting House, Afro House and electronic music 24/7.',

            'hero.sr_title' => 'Deseo Radio: The soundtrack of your life',
            'hero.now_playing' => 'NOW PLAYING',
            'hero.now_on_air' => 'NOW ON AIR',
            'hero.sponsor' => 'SPONSOR',
            'hero.live_broadcast' => 'Live broadcast',
            'hero.non_stop_mix' => 'Non-stop mix',
            'hero.auto_dj' => 'DESEO AUTO DJ',
            'hero.auto_dj_alt' => 'Deseo Radio Auto DJ',

            'program.eyebrow' => 'TODAY’S BROADCAST',
            'program.heading' => 'Today’s',
            'program.heading_em' => 'program.',
            'program.description' => 'All times are shown in Greece time. Overnight sets are handled automatically.',
            'program.on_air_now' => 'ON AIR NOW',
            'program.live_set' => 'LIVE SET',
            'program.empty_title' => 'Non-stop Deseo mix',
            'program.empty_fallback' => 'The live schedule is being updated. The stream remains available as normal.',
            'program.empty_none' => 'There is no scheduled live set today. Deseo continues non-stop.',
            'program.next_live' => 'Next live',
            'day.1' => 'Monday',
            'day.2' => 'Tuesday',
            'day.3' => 'Wednesday',
            'day.4' => 'Thursday',
            'day.5' => 'Friday',
            'day.6' => 'Saturday',
            'day.7' => 'Sunday',

            'airplay.eyebrow' => 'WEEKLY ROTATION',
            'airplay.heading' => 'Deseo',
            'airplay.heading_em' => 'Airplay.',
            'airplay.description' => 'The tracks standing out this week on Deseo Radio.',
            'airplay.selection' => 'Deseo Radio Selection',
            'airplay.spotify_aria' => 'Open on Spotify',
            'airplay.empty_title' => 'The new chart is on the way',
            'airplay.empty_text' => 'The stream is running normally. The Airplay chart will appear as soon as the studio updates it.',

            'partners.eyebrow' => 'LISTEN EVERYWHERE',
            'partners.heading' => 'Find Deseo',
            'partners.heading_em' => 'everywhere.',
            'partners.description' => 'Listen to Deseo on our website or through leading radio platforms.',

            'advertise.eyebrow' => 'ADVERTISE ON DESEO',
            'advertise.heading' => 'Put your brand',
            'advertise.heading_em' => 'inside the sound.',
            'advertise.text' => 'Connect your brand with a focused audience that loves House and electronic music through tailor-made radio campaigns by ILUMA.',
            'advertise.cta' => 'Start a campaign ↗',

            'footer.tagline' => 'The soundtrack of your life.',
            'footer.subtagline' => 'House music, live from Athens.',
            'footer.contact' => 'Contact',
            'footer.follow' => 'Follow',
            'footer.listen' => 'Listen',
            'footer.live_player' => 'Live player',
            'footer.today_program' => 'Today’s program',
            'footer.weekly_airplay' => 'Weekly airplay',
            'footer.powered' => 'Powered by',
            'install.title' => 'Install Deseo App',
            'install.subtitle' => 'Faster access · Home screen',

            'cookie.title' => 'Your privacy, your choice.',
            'cookie.text' => 'We use essential local storage to operate the site and, only with your consent, analytics, push and marketing services.',
            'cookie.analytics' => 'Analytics & push services',
            'cookie.marketing' => 'Marketing & personalization',
            'cookie.accept' => 'Accept',
            'cookie.customize' => 'Customize',
            'cookie.reject' => 'Reject optional',
        ],
    ];

    $language = deseo_lang();
    return $translations[$language][$key]
        ?? $translations['el'][$key]
        ?? $key;
}

function deseo_t_day(int $day): string {
    return deseo_t('day.' . max(1, min(7, $day)));
}
