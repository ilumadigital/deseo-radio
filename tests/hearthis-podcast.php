<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/hearthis-podcast.php';

if (!function_exists('simplexml_load_string')) {
    fwrite(STDERR, "FAIL: PHP SimpleXML is required for podcast RSS safety verification.\n");
    exit(1);
}
$id = '14709876';
$url = 'https://hearthis.at/deseoradio/deseoradio-season-6-spot/';
$title = 'DeseoRadio - Season 6 Spot';
$fixture = static function (string $episodeTitle, string $episodeUrl, string $audioUrl, string $length, string $type, string $extra = ''): string {
    return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Deseo RadioShows</title><item>'
        . '<title>' . htmlspecialchars($episodeTitle, ENT_XML1) . '</title>'
        . '<link>' . htmlspecialchars($episodeUrl, ENT_XML1) . '</link>'
        . '<guid>' . htmlspecialchars($episodeUrl, ENT_XML1) . '</guid>'
        . '<enclosure url="' . htmlspecialchars($audioUrl, ENT_XML1) . '" length="'
        . htmlspecialchars($length, ENT_XML1) . '" type="' . htmlspecialchars($type, ENT_XML1) . '"/>'
        . '</item>' . $extra . '</channel></rss>';
};
$good = $fixture($title, $url, 'https://hearthis.at/deseoradio/deseoradio-season-6-spot/download/', '694648', 'audio/mpeg');

// The actual public Season 6 Spot API returns stream_url on hearthis.app.
// Keep an exact provider host allowlist; NEVER allow lookalike subdomains.
$trustedHosts = [
    'hearthis.at' => true,
    'www.hearthis.at' => true,
    'download.hearthis.at' => true,
    'hearthis.app' => true,
    'api.hearthis.app' => false,
    'hearthis.app.evil.example' => false,
    'evil-hearthis.app' => false,
    'example.com' => false,
];
foreach ($trustedHosts as $host => $expectedTrusted) {
    if (deseo_hearthis_media_host_allowed($host) !== $expectedTrusted) {
        fwrite(STDERR, "FAIL: media host allowlist for {$host}\n");
        exit(1);
    }
}

$cases = [
    ['valid exact track', $good, true],
    ['hearthis.app HTTPS audio enclosure', $fixture($title, $url, 'https://hearthis.app/stream/test.mp3', '694648', 'audio/mpeg'), true],
    ['lookalike enclosure host rejected', $fixture($title, $url, 'https://hearthis.app.evil.example/test.mp3', '694648', 'audio/mpeg'), false],
    ['wrong track title', $fixture('Another mix', $url, 'https://hearthis.at/test.mp3', '694648', 'audio/mpeg'), false],
    ['another track URL with same title', $fixture($title, 'https://hearthis.at/deseoradio/other-mix/', 'https://hearthis.at/test.mp3', '694648', 'audio/mpeg'), false],
    ['no audio enclosure', $fixture($title, $url, '', '694648', 'audio/mpeg'), false],
    ['non-audio enclosure', $fixture($title, $url, 'https://hearthis.at/file.html', '694648', 'text/html'), false],
    ['zero audio size', $fixture($title, $url, 'https://hearthis.at/file.mp3', '0', 'audio/mpeg'), false],
    ['insecure audio URL', $fixture($title, $url, 'http://hearthis.at/file.mp3', '694648', 'audio/mpeg'), false],
    ['duplicate item', $fixture($title, $url, 'https://hearthis.at/file.mp3', '694648', 'audio/mpeg',
        '<item><title>' . $title . '</title><link>' . $url
        . '</link><enclosure url="https://hearthis.at/file.mp3" length="694648" type="audio/mpeg"/></item>'), false],
    ['entity declarations rejected', '<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $good, false],
    ['unrelated GUID alone', $fixture($title, 'https://hearthis.at/other/other-track/', 'https://hearthis.at/file.mp3', '694648', 'audio/mpeg'), false],
];
foreach ($cases as [$name, $xml, $expected]) {
    $actual = deseo_hearthis_podcast_xml_has_track($xml, $id, $url, $title);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}
echo 'PASS: ' . count($cases) . ' podcast RSS exact-match / deletion-gate cases and '
    . count($trustedHosts) . " exact provider media host tests.\n";
