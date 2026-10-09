<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/i18n.php';

function deseo_e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function deseo_time(?string $value): string {
    if (!$value) return '--:--';
    $ts = strtotime($value);
    return $ts === false ? '--:--' : date('H:i', $ts);
}

$airplay_tracks = [];
$program = [];
$todays_program = [];
$playlists = [];
$live_dj = null;
$next_dj = null;
$dataStatus = 'live';

$tz = new DateTimeZone('Europe/Athens');
$now = new DateTimeImmutable('now', $tz);

// Season 6 premiere: 14 October 2026, 20:00 Athens time.
$lineupStart = new DateTimeImmutable('2026-10-14 20:00:00', $tz);
$lineupRemainingSeconds = max(0, $lineupStart->getTimestamp() - $now->getTimestamp());
$lineupRemainingMinutes = (int)ceil($lineupRemainingSeconds / 60);
$lineupDays = intdiv($lineupRemainingMinutes, 1440);
$lineupHours = intdiv($lineupRemainingMinutes % 1440, 60);
$lineupMinutes = $lineupRemainingMinutes % 60;
$lineupOnAir = $lineupRemainingSeconds === 0;

try {
    require_once dirname(__DIR__) . '/iluma/connection.php';
    require_once dirname(__DIR__) . '/includes/mylive-email-reminders.php';
    deseo_mylive_maybe_run_email_scheduler($pdo);

    $airplay_tracks = $pdo->query(
        "SELECT id, spotify_url, track_name, artist_name, artwork_url, position
         FROM airplay
         WHERE position BETWEEN 1 AND 10
         ORDER BY position ASC
         LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    try {
        $program = $pdo->query(
            "SELECT id, dj_name, photo_path, mylive_account_id, day_of_week, start_time, end_time
             FROM program
             WHERE day_of_week BETWEEN 1 AND 7
             ORDER BY day_of_week ASC, start_time ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $programProfileColumnError) {
        $program = $pdo->query(
            "SELECT id, dj_name, photo_path, NULL AS mylive_account_id, day_of_week, start_time, end_time
             FROM program
             WHERE day_of_week BETWEEN 1 AND 7
             ORDER BY day_of_week ASC, start_time ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $publishedProfiles = [];
    $profileAccountIds = array_values(array_unique(array_filter(array_map(
        static fn(array $show): int => (int)($show['mylive_account_id'] ?? 0),
        $program
    ))));

    if ($profileAccountIds) {
        try {
            $profilePlaceholders = implode(',', array_fill(0, count($profileAccountIds), '?'));
            $profileStmt = $pdo->prepare(
                "SELECT a.id AS account_id,
                        a.artist_name,
                        p.published_bio AS bio,
                        p.published_instagram AS instagram,
                        p.published_tiktok AS tiktok,
                        p.published_soundcloud AS soundcloud,
                        p.published_spotify AS spotify,
                        p.published_website AS website
                 FROM dj_portal_accounts a
                 INNER JOIN dj_public_profiles p ON p.account_id = a.id
                 WHERE a.id IN ($profilePlaceholders)
                   AND a.is_active = 1
                   AND a.account_status = 'active'
                   AND a.public_profile_enabled = 1
                   AND p.is_published = 1"
            );
            $profileStmt->execute($profileAccountIds);

            foreach ($profileStmt->fetchAll(PDO::FETCH_ASSOC) as $profileRow) {
                $publishedProfiles[(int)$profileRow['account_id']] = [
                    'artist_name' => (string)$profileRow['artist_name'],
                    'bio' => (string)$profileRow['bio'],
                    'instagram' => (string)$profileRow['instagram'],
                    'tiktok' => (string)$profileRow['tiktok'],
                    'soundcloud' => (string)$profileRow['soundcloud'],
                    'spotify' => (string)$profileRow['spotify'],
                    'website' => (string)$profileRow['website'],
                ];
            }
        } catch (Throwable $profileError) {
            error_log('Deseo public DJ profiles unavailable: ' . $profileError->getMessage());
            $publishedProfiles = [];
        }
    }

    foreach ($program as &$programShow) {
        $profileId = (int)($programShow['mylive_account_id'] ?? 0);
        $programShow['public_profile'] = $profileId > 0 && isset($publishedProfiles[$profileId])
            ? $publishedProfiles[$profileId]
            : null;
    }
    unset($programShow);

    try {
        $playlists = $pdo->query(
            "SELECT id, spotify_url, title, artwork_url, position
             FROM playlists
             ORDER BY position ASC, id DESC
             LIMIT 12"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $playlistError) {
        error_log('Deseo playlists unavailable: ' . $playlistError->getMessage());
        $playlists = [];
    }

    $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);
    $occurrences = [];

    foreach ($program as $show) {
        $day = max(1, min(7, (int)($show['day_of_week'] ?? 1)));

        foreach ([-7, 0, 7] as $weekOffset) {
            $baseDate = $weekStart->modify(($weekOffset + $day - 1) . ' days');

            $startParts = array_map('intval', explode(':', (string)($show['start_time'] ?? '00:00:00')));
            $endParts = array_map('intval', explode(':', (string)($show['end_time'] ?? '23:59:59')));

            $start = $baseDate->setTime($startParts[0] ?? 0, $startParts[1] ?? 0, $startParts[2] ?? 0);
            $end = $baseDate->setTime($endParts[0] ?? 23, $endParts[1] ?? 59, $endParts[2] ?? 59);

            if ($end <= $start) $end = $end->modify('+1 day');

            $occurrences[] = [
                'show' => $show,
                'start' => $start,
                'end' => $end,
            ];
        }
    }

    usort($occurrences, static fn($a, $b) => $a['start'] <=> $b['start']);

    foreach ($occurrences as $occurrence) {
        if ($now >= $occurrence['start'] && $now < $occurrence['end']) {
            $live_dj = $occurrence['show'];
            $live_dj['_start'] = $occurrence['start'];
            $live_dj['_end'] = $occurrence['end'];
            break;
        }
    }

    foreach ($occurrences as $occurrence) {
        if ($occurrence['start'] > $now) {
            $next_dj = $occurrence['show'];
            $next_dj['_start'] = $occurrence['start'];
            $next_dj['_end'] = $occurrence['end'];
            break;
        }
    }

    $todayIso = $now->format('Y-m-d');
    foreach ($occurrences as $occurrence) {
        if ($occurrence['start']->format('Y-m-d') !== $todayIso) continue;

        $row = $occurrence['show'];
        $row['_start'] = $occurrence['start'];
        $row['_end'] = $occurrence['end'];
        $todays_program[] = $row;
    }

    $unique = [];
    $todays_program = array_values(array_filter($todays_program, static function ($row) use (&$unique) {
        $key = $row['id'] . '-' . $row['_start']->format('c');
        if (isset($unique[$key])) return false;
        $unique[$key] = true;
        return true;
    }));
} catch (Throwable $e) {
    $dataStatus = 'fallback';
    error_log('Deseo Radio data unavailable: ' . $e->getMessage());
}

if (($_GET['program_feed'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $serializeShow = static function (?array $show): ?array {
        if (!$show) return null;

        return [
            'id' => (int)($show['id'] ?? 0),
            'dj_name' => (string)($show['dj_name'] ?? ''),
            'mylive_account_id' => (int)($show['mylive_account_id'] ?? 0),
            'photo_path' => (string)($show['photo_path'] ?? ''),
            'start_time' => (string)($show['start_time'] ?? ''),
            'end_time' => (string)($show['end_time'] ?? ''),
            'profile' => is_array($show['public_profile'] ?? null) ? $show['public_profile'] : null,
        ];
    };

    $todayFeed = array_map(
        static function (array $show) use ($serializeShow, $live_dj): array {
            $row = $serializeShow($show) ?? [];
            $row['is_live'] = $live_dj && (int)$live_dj['id'] === (int)($show['id'] ?? 0);
            return $row;
        },
        $todays_program
    );

    echo json_encode([
        'ok' => $dataStatus === 'live',
        'generated_at' => $now->format(DATE_ATOM),
        'live' => $serializeShow($live_dj),
        'next' => $serializeShow($next_dj),
        'today' => $todayFeed,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// Defensive: only the legacy JSON endpoint may enter this compatibility module.
http_response_code(404);
exit;
