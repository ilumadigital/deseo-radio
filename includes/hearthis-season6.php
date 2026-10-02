<?php
declare(strict_types=1);

/**
 * Standalone, credentials-free public Season 6 set lookup / membership reader
 * and documented authenticated set_ajax_add.php POST adapter.
 * Does not import the ILUMA database or enable any MyLive functionality.
 */
// Current canonical URL confirmed from the Deseo account's public playlist API.
// Discovery still validates the current UNIQUE title, ID and permalink live.
const DESEO_HEARTHIS_SEASON6_URL = 'https://hearthis.at/set/561432-10808078/';

function deseo_hearthis_s6_read(string $url): ?array {
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || ($parts['host'] ?? '') !== 'api-v2.hearthis.at'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || isset($parts['fragment']) || !function_exists('curl_init')) return null;
    $curl = curl_init($url);
    if ($curl === false) return null;
    $bytes = '';
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 18,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes): int {
            if (strlen($bytes) + strlen($chunk) > 2097152) return 0;
            $bytes .= $chunk;
            return strlen($chunk);
        },
    ]);
    try {
        $ok = curl_exec($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    } finally {
        curl_close($curl);
    }
    if ($ok === false || $http !== 200) return null;
    $decoded = json_decode($bytes, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * The station account's public playlist listing is authoritative. HearThis's
 * actual public payload uses a numeric composite slug such as "561432-10808078",
 * not the human-facing "season-6" shorthand. Resolve the ONE titled "Season 6"
 * collection, and validate its ID, permalink and canonical URL together.
 */
function deseo_hearthis_s6_playlist(string $username = 'deseoradio'): ?array {
    if ($username !== 'deseoradio') return null;
    $body = deseo_hearthis_s6_read('https://api-v2.hearthis.at/deseoradio/?type=playlists&count=100');
    if ($body === null) return null;
    $collections = array_is_list($body) ? $body : null;
    if ($collections === null) {
        foreach (['playlists', 'sets', 'data', 'items', 'results'] as $key) {
            if (isset($body[$key]) && is_array($body[$key]) && array_is_list($body[$key])) {
                $collections = $body[$key];
                break;
            }
        }
    }
    if ($collections === null) return null;
    $matches = [];
    foreach ($collections as $row) {
        if (!is_array($row) || trim((string)($row['title'] ?? '')) !== 'Season 6') continue;
        $id = trim((string)($row['id'] ?? $row['set_id'] ?? ''));
        $slug = trim((string)($row['permalink'] ?? ''));
        $url = trim((string)($row['permalink_url'] ?? ''));
        if (!ctype_digit($id) || (int)$id < 1
            || preg_match('/^[1-9][0-9]*-[1-9][0-9]*$/D', $slug) !== 1
            || !str_starts_with($slug, $id . '-')
            || $url !== 'https://hearthis.at/set/' . $slug . '/') continue;
        $user = is_array($row['user'] ?? null) ? $row['user'] : [];
        $author = strtolower(trim((string)($user['permalink'] ?? $user['username'] ?? '')));
        if ($author !== '' && $author !== $username) continue;
        $total = $row['track_count'] ?? null;
        $count = is_int($total) || (is_string($total) && ctype_digit($total)) ? (int)$total : null;
        $matches[$id] = [
            'id' => $id, 'slug' => $slug, 'url' => $url, 'track_count' => $count,
        ];
    }
    return count($matches) === 1 ? reset($matches) : null;
}

/** Unique numeric ID for the exact existing Season 6 playlist. */
function deseo_hearthis_s6_playlist_id(string $username = 'deseoradio'): ?string {
    $playlist = deseo_hearthis_s6_playlist($username);
    return $playlist === null ? null : $playlist['id'];
}

/**
 * Read the API's actual canonical numeric slug, and treat an empty root list
 * as empty ONLY when the account playlist metadata also says track_count=0.
 * A partial, paginated, or unrecognized response can never authorize upload
 * duplication, positive membership or deletion.
 */
function deseo_hearthis_s6_track_listing(string $setId): ?array {
    if (!ctype_digit($setId) || (int)$setId < 1) return null;
    $set = deseo_hearthis_s6_playlist('deseoradio');
    if ($set === null || $set['id'] !== $setId) return null;
    $body = deseo_hearthis_s6_read('https://api-v2.hearthis.at/set/' . $set['slug'] . '/');
    if ($body === null) return null;
    if (isset($body['id']) && (string)$body['id'] !== $setId) return null;
    $entries = array_is_list($body) ? $body : null;
    if ($entries === null) {
        foreach (['tracks', 'items', 'entries', 'data'] as $key) {
            if (isset($body[$key]) && is_array($body[$key]) && array_is_list($body[$key])) {
                $entries = $body[$key];
                break;
            }
        }
    }
    if ($entries === null) return null;
    $declaredCount = $set['track_count'];
    // A zero-length result for a non-empty set might mean wrong endpoint,
    // restricted visibility or failed pagination: never treat that as absence.
    if ($declaredCount === null || count($entries) < $declaredCount) return null;
    // A larger count than the authoritative playlist metadata is also suspect.
    if (count($entries) !== $declaredCount) return null;
    return ['playlist' => $set, 'tracks' => $entries];
}

function deseo_hearthis_s6_contains_track(string $trackId, string $setId): ?bool {
    if (!ctype_digit($trackId) || (int)$trackId < 1) return null;
    $listing = deseo_hearthis_s6_track_listing($setId);
    if ($listing === null) return null;
    foreach ($listing['tracks'] as $row) {
        if (!is_array($row)) return null;
        $candidate = is_array($row['track'] ?? null) ? $row['track'] : $row;
        if (isset($candidate['id']) && (string)$candidate['id'] === $trackId) return true;
        if (isset($candidate['track_id']) && (string)$candidate['track_id'] === $trackId) return true;
    }
    return false;
}

/**
 * Adds a track to an EXISTING ID. No implicit set creation; no automatic
 * repeated POST after an uncertain response. Caller writes an 'adding'
 * durable receipt/state BEFORE invoking this method.
 */
function deseo_hearthis_s6_add_track(string $trackId, string $setId, array $config): bool {
    if (!ctype_digit($trackId) || !ctype_digit($setId)
        || (int)$trackId < 1 || (int)$setId < 1
        || !isset($config['key'], $config['secret'])
        || trim((string)$config['key']) === '' || trim((string)$config['secret']) === '') {
        throw new RuntimeException('Missing or invalid Season 6 set / track / authentication values.');
    }
    $curl = curl_init('https://api-v2.hearthis.at/set_ajax_add.php');
    if ($curl === false) throw new RuntimeException('Cannot initialize Season 6 set association.');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'key' => $config['key'], 'secret' => $config['secret'],
            'action' => 'add', 'set' => $setId, 'track_id' => $trackId,
        ],
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    try {
        $body = curl_exec($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    } finally {
        curl_close($curl);
    }
    // The docs do not specify the write response shape. Never infer actual
    // membership from HTTP 200: the separate public read is authoritative.
    return $body !== false && $http >= 200 && $http < 300;
}
