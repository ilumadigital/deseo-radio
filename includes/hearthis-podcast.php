<?php
declare(strict_types=1);

/**
 * One authoritative podcast destination for the station:
 * https://hearthis.at/deseoradio/podcast.xml
 *
 * This is a READ-ONLY, fail-closed verifier. The documented HearThis Premium
 * upload/edit API does NOT specify a parameter for the dashboard checkbox
 * "Show Mix inside Podcast / RSS feed". Do not invent an undocumented write.
 * If the provider default excludes a new track, the MP3 is retained until the
 * checkbox is enabled and the exact episode appears here.
 */
const DESEO_HEARTHIS_PODCAST_RSS = 'https://hearthis.at/deseoradio/podcast.xml';

/**
 * Hostinger's live anonymous API read for Season 6 Spot (track 14703494)
 * confirmed stream_url=https://hearthis.app/... . Restrict to the exact
 * provider-controlled hosts seen/documented for stream/enclosure media,
 * never arbitrary URLs or wildcard subdomains.
 */
function deseo_hearthis_media_host_allowed(string $host): bool {
    return in_array(strtolower($host), [
        'hearthis.at', 'www.hearthis.at', 'download.hearthis.at', 'hearthis.app',
    ], true);
}


function deseo_hearthis_podcast_canonical_track(string $candidate): string {
    $candidate = trim($candidate);
    if (!filter_var($candidate, FILTER_VALIDATE_URL)) return '';
    $parts = parse_url($candidate);
    if (!is_array($parts)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || !in_array(strtolower((string)($parts['host'] ?? '')), ['hearthis.at', 'www.hearthis.at'], true)
        || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) return '';
    $segments = explode('/', trim((string)($parts['path'] ?? ''), '/'));
    if (count($segments) !== 2 || strtolower(rawurldecode($segments[0])) !== 'deseoradio'
        || !preg_match('/^[a-z0-9_-]+$/iD', rawurldecode($segments[1]))) return '';
    return 'https://hearthis.at/deseoradio/' . strtolower(rawurldecode($segments[1])) . '/';
}

/** Require a fully specified, HTTPS podcast audio enclosure; never follow it here. */
function deseo_hearthis_podcast_enclosure_ok(string $url, string $mime, string $length): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || !deseo_hearthis_media_host_allowed((string)($parts['host'] ?? ''))
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['port']) || isset($parts['fragment'])
        || !ctype_digit($length) || (float)$length < 1024) return false;
    $mime = strtolower(trim(explode(';', $mime)[0]));
    return str_starts_with($mime, 'audio/') || $mime === 'application/octet-stream';
}

/**
 * Pure RSS match for local fixture tests. Never match by title alone:
 * require exact canonical track URL in the item link or GUID, the expected
 * title, and a positive audio enclosure. Unknown/duplicate items fail CLOSED.
 */
function deseo_hearthis_podcast_xml_has_track(string $xml, string $trackId, string $trackUrl, string $title): bool {
    if (!ctype_digit($trackId) || (int)$trackId < 1 || $title === ''
        || strlen($xml) < 40 || strlen($xml) > 8388608 || !function_exists('simplexml_load_string')
        || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) return false;
    $expected = deseo_hearthis_podcast_canonical_track($trackUrl);
    if ($expected === '') return false;
    $old = libxml_use_internal_errors(true);
    try {
        $rss = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($old);
    }
    if ($rss === false || $rss->getName() !== 'rss' || !isset($rss->channel)) return false;
    $matches = 0;
    foreach ($rss->channel->item as $item) {
        $itemTitle = trim((string)($item->title ?? ''));
        if ($itemTitle !== $title) continue;
        $itemLink = deseo_hearthis_podcast_canonical_track((string)($item->link ?? ''));
        $guid = deseo_hearthis_podcast_canonical_track((string)($item->guid ?? ''));
        $rawGuid = trim((string)($item->guid ?? ''));
        $ownedMatch = $itemLink === $expected || $guid === $expected
            || ($itemLink === '' && $guid === '' && $rawGuid === $trackId);
        if (!$ownedMatch) continue;
        $enclosure = $item->enclosure ?? null;
        if ($enclosure === null) return false;
        $attrs = $enclosure->attributes();
        if ($attrs === null || !deseo_hearthis_podcast_enclosure_ok(
            trim((string)($attrs['url'] ?? '')),
            trim((string)($attrs['type'] ?? '')),
            trim((string)($attrs['length'] ?? ''))
        )) return false;
        $matches++;
        if ($matches > 1) return false;
    }
    return $matches === 1;
}

/** GET only, HTTPS only, fixed domain/path, no redirects, bounded read. */
function deseo_hearthis_podcast_fetch_xml(): ?string {
    if (!function_exists('curl_init') || !function_exists('simplexml_load_string')) return null;
    $curl = curl_init(DESEO_HEARTHIS_PODCAST_RSS);
    if ($curl === false) return null;
    $buffer = '';
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/xml, text/xml', 'Cache-Control: no-cache'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $bytes) use (&$buffer): int {
            if (strlen($buffer) + strlen($bytes) > 8388608) return 0;
            $buffer .= $bytes;
            return strlen($bytes);
        },
    ]);
    try {
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $mime = strtolower(trim((string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
    } finally {
        curl_close($curl);
    }
    if ($ok === false || $status !== 200 || strlen($buffer) < 40
        || !(str_contains($mime, 'xml') || str_contains($mime, 'rss'))) return null;
    return $buffer;
}

function deseo_hearthis_podcast_contains_track(string $trackId, string $trackUrl, string $title): bool {
    $xml = deseo_hearthis_podcast_fetch_xml();
    return $xml !== null && deseo_hearthis_podcast_xml_has_track($xml, $trackId, $trackUrl, $title);
}

/** Read-only diagnostics; never disclose item titles / owner email / feed XML. */
function deseo_hearthis_podcast_feed_readable(): bool {
    $xml = deseo_hearthis_podcast_fetch_xml();
    if ($xml === null || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) return false;
    $old = libxml_use_internal_errors(true);
    try {
        $rss = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($old);
    }
    return $rss !== false && $rss->getName() === 'rss' && isset($rss->channel);
}
