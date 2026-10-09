<?php
declare(strict_types=1);

/**
 * Isolated /mydemo stream metadata reader. No writes to application DB.
 * Never call untrusted URLs: every upstream is an explicit HTTPS endpoint.
 * The stream continues to play independently if metadata/artwork APIs fail.
 */
const MD_STREAM = 'https://ec4.yesstreaming.net:2090/stream';
const MD_SERVER = 'https://ec4.yesstreaming.net:2090';

function md_http(string $url, int $timeoutMs = 2200, array $headers = [], ?array $post = null): ?string {
    if (!extension_loaded('curl')) return null;
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT_MS => 1100,
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: DeseoRadio-MyDemo/1.0'], $headers),
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_ENCODING => '',
    ]);
    if ($post !== null) {
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $data = curl_exec($c);
    $status = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return is_string($data) && $status >= 200 && $status < 300 && strlen($data) < 262144 ? $data : null;
}

function md_normalize(string $text): string {
    $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function md_parse_title(string $title): ?array {
    $title = md_normalize($title);
    if ($title === '' || strlen($title) > 280 ||
        preg_match('/^(?:deseo(?: radio)?|unknown|auto\s*dj|live\s*stream|non[- ]?stop|offline)$/i', $title)) return null;
    $parts = preg_split('/\s+[-–—]\s+/u', $title, 2);
    if (!$parts || count($parts) !== 2) return null;
    $artist = md_normalize($parts[0]);
    $track = md_normalize($parts[1]);
    if (strlen($artist) < 2 || strlen($track) < 2 || preg_match('/^(?:deseo|radio|live|unknown)$/i', $track)) return null;
    return ['artist' => $artist, 'track' => $track, 'title' => $artist . ' — ' . $track];
}

function md_icecast_title(string $body): string {
    $obj = json_decode($body, true);
    if (!is_array($obj)) return '';
    $sources = $obj['icestats']['source'] ?? $obj['source'] ?? [];
    if (isset($sources['title'])) $sources = [$sources];
    if (!is_array($sources)) return '';
    foreach ($sources as $source) {
        if (!is_array($source)) continue;
        $listen = (string)($source['listenurl'] ?? '');
        if ($listen !== '' && !str_contains($listen, '/stream')) continue;
        foreach (['title', 'yp_currently_playing'] as $key) {
            if (!empty($source[$key]) && is_string($source[$key])) return (string)$source[$key];
        }
        if (is_string($source['artist'] ?? null) && is_string($source['song'] ?? null)) {
            return $source['artist'] . ' - ' . $source['song'];
        }
    }
    return '';
}

function md_shoutcast_title(string $body): string {
    $obj = json_decode($body, true);
    if (!is_array($obj)) return '';
    foreach (['songtitle', 'title', 'servertitle'] as $key) {
        if (!empty($obj[$key]) && is_string($obj[$key])) return (string)$obj[$key];
    }
    return '';
}

/**
 * ICY metadata is in-band. Read only until the first StreamTitle is delivered;
 * abort the cURL transfer intentionally so it never downloads an entire stream.
 */
function md_icy_title(): string {
    if (!extension_loaded('curl')) return '';
    $metaint = 0;
    $received = '';
    $title = '';
    $c = curl_init(MD_STREAM);
    curl_setopt_array($c, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT_MS => 1300,
        CURLOPT_TIMEOUT_MS => 3200,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Icy-MetaData: 1', 'User-Agent: DeseoRadio-MyDemo/1.0'],
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$metaint) {
            if (preg_match('/^icy-metaint:\s*(\d+)/i', trim($line), $m)) $metaint = (int)$m[1];
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$metaint, &$received, &$title) {
            if ($metaint <= 0 || $metaint > 262144 || strlen($received) > 263000) return 0;
            $received .= $chunk;
            if (strlen($received) < $metaint + 1) return strlen($chunk);
            $length = ord($received[$metaint]) * 16;
            if ($length > 0 && strlen($received) >= $metaint + 1 + $length) {
                $meta = substr($received, $metaint + 1, $length);
                if (preg_match("/StreamTitle='([^']*)'/", $meta, $m)) $title = (string)$m[1];
                return 0;
            }
            if ($length === 0 || strlen($received) >= $metaint + 1 + $length) return 0;
            return strlen($chunk);
        },
    ]);
    curl_exec($c);
    curl_close($c);
    return $title;
}

function md_current_song(): ?array {
    $ice = md_http(MD_SERVER . '/status-json.xsl');
    $raw = $ice ? md_icecast_title($ice) : '';
    if (!$raw) {
        $stats = md_http(MD_SERVER . '/stats?sid=1&json=1');
        $raw = $stats ? md_shoutcast_title($stats) : '';
    }
    if (!$raw) $raw = md_icy_title();
    return md_parse_title($raw);
}

function md_key(string $value): string {
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}
function md_match(string $expected, string $actual): bool {
    $a = md_key($expected); $b = md_key($actual);
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    // Extended remixes and versions may have extra suffixes.
    return strlen($a) >= 5 && (str_starts_with($b, $a . ' ') || str_starts_with($a, $b . ' '));
}
function md_artwork_url(string $url): string {
    $u = parse_url($url);
    if (!is_array($u) || strtolower($u['scheme'] ?? '') !== 'https') return '';
    $host = strtolower($u['host'] ?? '');
    return preg_match('/^(?:is\d+-ssl\.mzstatic\.com|i\d+\.scdn\.co|lastfm\.freetls\.fastly\.net|(?:\w+\.)?last\.fm)$/', $host) ? $url : '';
}

function md_spotify_art(array $song): ?array {
    $id = trim((string)(getenv('SPOTIFY_CLIENT_ID') ?: ''));
    $secret = trim((string)(getenv('SPOTIFY_CLIENT_SECRET') ?: ''));
    if ($id === '' || $secret === '') return null;
    $tokenJson = md_http('https://accounts.spotify.com/api/token', 2500,
        ['Authorization: Basic ' . base64_encode($id . ':' . $secret), 'Content-Type: application/x-www-form-urlencoded'],
        ['grant_type' => 'client_credentials']);
    $token = $tokenJson ? (json_decode($tokenJson, true)['access_token'] ?? '') : '';
    if (!is_string($token) || $token === '') return null;
    $url = 'https://api.spotify.com/v1/search?type=track&limit=5&q=' .
        rawurlencode('track:' . $song['track'] . ' artist:' . $song['artist']);
    $body = md_http($url, 2500, ['Authorization: Bearer ' . $token]);
    $rows = $body ? (json_decode($body, true)['tracks']['items'] ?? []) : [];
    if (!is_array($rows)) return null;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $names = array_map(static fn($a) => is_array($a) ? (string)($a['name'] ?? '') : '', $row['artists'] ?? []);
        if (!md_match($song['track'], (string)($row['name'] ?? '')) ||
            !array_filter($names, static fn($a) => md_match($song['artist'], $a))) continue;
        $cover = md_artwork_url((string)($row['album']['images'][0]['url'] ?? ''));
        if ($cover !== '') return ['artwork' => $cover, 'provider' => 'Spotify'];
    }
    return null;
}
function md_apple_art(array $song): ?array {
    $url = 'https://itunes.apple.com/search?media=music&entity=song&limit=10&term=' .
        rawurlencode($song['artist'] . ' ' . $song['track']);
    $body = md_http($url, 3000);
    $rows = $body ? (json_decode($body, true)['results'] ?? []) : [];
    if (!is_array($rows)) return null;
    foreach ($rows as $row) {
        if (!is_array($row) || ($row['kind'] ?? '') !== 'song') continue;
        if (!md_match($song['track'], (string)($row['trackName'] ?? '')) ||
            !md_match($song['artist'], (string)($row['artistName'] ?? ''))) continue;
        $art = (string)($row['artworkUrl100'] ?? '');
        // Apple's HTTPS image URL supports larger sizes via its suffix.
        $art = preg_replace('/100x100(?:bb|-75)\.(jpg|png)/i', '600x600bb.$1', $art) ?? $art;
        $cover = md_artwork_url($art);
        if ($cover !== '') return ['artwork' => $cover, 'provider' => 'Apple Music'];
    }
    return null;
}
function md_lastfm_art(array $song): ?array {
    $key = trim((string)(getenv('LASTFM_API_KEY') ?: ''));
    if ($key === '') return null;
    $url = 'https://ws.audioscrobbler.com/2.0/?format=json&method=track.getInfo&api_key=' .
        rawurlencode($key) . '&artist=' . rawurlencode($song['artist']) . '&track=' . rawurlencode($song['track']);
    $body = md_http($url, 2500);
    $obj = $body ? (json_decode($body, true)['track'] ?? []) : [];
    if (!is_array($obj)) return null;
    if (!md_match($song['track'], (string)($obj['name'] ?? '')) ||
        !md_match($song['artist'], (string)($obj['artist']['name'] ?? ''))) return null;
    $images = $obj['album']['image'] ?? [];
    if (!is_array($images)) return null;
    foreach (array_reverse($images) as $img) {
        if (!is_array($img)) continue;
        $cover = md_artwork_url((string)($img['#text'] ?? ''));
        if ($cover !== '') return ['artwork' => $cover, 'provider' => 'Last.fm'];
    }
    return null;
}

function md_payload(): array {
    $song = md_current_song();
    if (!$song) {
        return ['ok' => false, 'artist' => null, 'track' => null, 'artwork' => null,
            'provider' => null, 'message' => 'No verified stream metadata'];
    }
    $art = md_spotify_art($song) ?? md_apple_art($song) ?? md_lastfm_art($song);
    return ['ok' => true, 'artist' => $song['artist'], 'track' => $song['track'],
        'artwork' => $art['artwork'] ?? null, 'provider' => $art['provider'] ?? null,
        'message' => $art ? 'matched' : 'artwork not verified'];
}
