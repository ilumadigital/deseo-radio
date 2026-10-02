<?php
declare(strict_types=1);

/**
 * Standalone, credentials-free public Season 6 set lookup / membership reader
 * and documented authenticated set_ajax_add.php POST adapter.
 * Does not import the ILUMA database or enable any MyLive functionality.
 */
const DESEO_HEARTHIS_SEASON6_URL = 'https://hearthis.at/deseoradio/set/season-6/';

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

/** The logged-in author is not inferred from search results or a similarly named collection. */
function deseo_hearthis_s6_playlist_id(string $username = 'deseoradio'): ?string {
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
    $matched = [];
    foreach ($collections as $row) {
        if (!is_array($row)) continue;
        $id = trim((string)($row['id'] ?? $row['set_id'] ?? ''));
        if (!ctype_digit($id) || (int)$id < 1) continue;
        $user = is_array($row['user'] ?? null) ? $row['user'] : [];
        $author = strtolower((string)($user['permalink'] ?? $row['username'] ?? 'deseoradio'));
        if ($author !== 'deseoradio') continue;
        $url = (string)($row['permalink_url'] ?? $row['url'] ?? '');
        $slug = strtolower((string)($row['permalink'] ?? $row['slug'] ?? ''));
        $urlMatch = $url !== '' && rtrim($url, '/') === rtrim(DESEO_HEARTHIS_SEASON6_URL, '/');
        $slugMatch = $slug === 'season-6';
        if (!$urlMatch && !$slugMatch) continue;
        $matched[$id] = true;
    }
    return count($matched) === 1 ? (string)array_key_first($matched) : null;
}

/**
 * GET /set/season-6/ returns collection metadata and its tracks according to
 * HearThis API docs. Only a proven ID hit inside a recognized track collection
 * may authorize retention; unknown response schemas fail CLOSED.
 */
function deseo_hearthis_s6_contains_track(string $trackId, string $setId): ?bool {
    if (!ctype_digit($trackId) || !ctype_digit($setId)) return null;
    $body = deseo_hearthis_s6_read('https://api-v2.hearthis.at/set/season-6/');
    if ($body === null) return null;
    if (isset($body['id']) && ctype_digit((string)$body['id']) && (string)$body['id'] !== $setId) {
        return null;
    }
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
    foreach ($entries as $row) {
        if (!is_array($row)) continue;
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
