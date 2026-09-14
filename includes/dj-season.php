<?php
declare(strict_types=1);

const DESEO_DJ_SEASON = 6;
const DESEO_DJ_TERMS_VERSION = 'season-6-2026-09-14-v1';
const DESEO_DJ_PRIVACY_VERSION = '2026-09-14-v1';

function dj_season_strict_slot_definitions(): array {
    $definitions = [];

    $dayStarts = [
        4 => ['20:00:00', '21:00:00', '22:00:00', '23:00:00'],
        5 => ['20:00:00', '21:00:00', '22:00:00', '23:00:00'],
        6 => ['18:00:00', '19:00:00', '20:00:00', '21:00:00', '22:00:00', '23:00:00'],
        7 => ['18:00:00', '19:00:00', '20:00:00', '21:00:00', '22:00:00', '23:00:00'],
    ];

    foreach ($dayStarts as $day => $starts) {
        foreach ($starts as $start) {
            $end = $start === '23:00:00'
                ? '23:59:59'
                : DateTimeImmutable::createFromFormat('!H:i:s', $start)->modify('+1 hour')->format('H:i:s');

            $definitions[] = [
                'day_of_week' => $day,
                'start_time' => $start,
                'end_time' => $end,
            ];
        }
    }

    return $definitions;
}

function dj_season_bootstrap(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_season_slots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        season SMALLINT NOT NULL,
        day_of_week TINYINT NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_season_day_start (season, day_of_week, start_time),
        KEY idx_season_active (season, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_season_bookings (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        season SMALLINT NOT NULL,
        slot_id INT NOT NULL,
        full_name VARCHAR(180) NOT NULL,
        artist_name VARCHAR(180) NOT NULL,
        email VARCHAR(254) NOT NULL,
        instagram VARCHAR(255) DEFAULT '',
        website VARCHAR(255) DEFAULT '',
        bio VARCHAR(1000) NOT NULL,
        photo_path VARCHAR(255) NOT NULL,
        set_type VARCHAR(40) NOT NULL,
        tracklist TEXT,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        rights_confirmed TINYINT(1) NOT NULL DEFAULT 1,
        ai_confirmed TINYINT(1) NOT NULL DEFAULT 1,
        age_confirmed TINYINT(1) NOT NULL DEFAULT 1,
        terms_version VARCHAR(80) NOT NULL,
        terms_accepted_at DATETIME NOT NULL,
        privacy_version VARCHAR(80) NOT NULL,
        privacy_acknowledged_at DATETIME NOT NULL,
        booking_token CHAR(64) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_slot_booking (slot_id),
        UNIQUE KEY uniq_booking_token (booking_token),
        KEY idx_booking_season_status (season, status),
        KEY idx_booking_email (email),
        CONSTRAINT fk_dj_booking_slot FOREIGN KEY (slot_id) REFERENCES dj_season_slots(id)
            ON UPDATE CASCADE ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $definitions = dj_season_strict_slot_definitions();
    $desired = [];

    $upsert = $pdo->prepare(
        "INSERT INTO dj_season_slots (season, day_of_week, start_time, end_time, is_active)
         VALUES (?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE end_time = VALUES(end_time), is_active = 1"
    );

    foreach ($definitions as $definition) {
        $day = (int)$definition['day_of_week'];
        $start = (string)$definition['start_time'];
        $desired[$day . '|' . $start] = true;

        $upsert->execute([
            DESEO_DJ_SEASON,
            $day,
            $start,
            (string)$definition['end_time'],
        ]);
    }

    $stmt = $pdo->prepare(
        "SELECT s.id, s.day_of_week, s.start_time,
                EXISTS(SELECT 1 FROM dj_season_bookings b WHERE b.slot_id = s.id) AS has_booking
         FROM dj_season_slots s
         WHERE s.season = ?"
    );
    $stmt->execute([DESEO_DJ_SEASON]);

    $delete = $pdo->prepare("DELETE FROM dj_season_slots WHERE id = ?");
    $deactivate = $pdo->prepare("UPDATE dj_season_slots SET is_active = 0 WHERE id = ?");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $slot) {
        $key = (int)$slot['day_of_week'] . '|' . (string)$slot['start_time'];
        if (isset($desired[$key])) continue;

        if (!empty($slot['has_booking'])) {
            $deactivate->execute([(int)$slot['id']]);
        } else {
            $delete->execute([(int)$slot['id']]);
        }
    }
}

function dj_season_time_minutes(string $time): int {
    $parts = array_map('intval', explode(':', $time));
    return (($parts[0] ?? 0) * 60) + ($parts[1] ?? 0);
}

function dj_season_ranges_overlap(string $startA, string $endA, string $startB, string $endB): bool {
    $a1 = dj_season_time_minutes($startA);
    $a2 = dj_season_time_minutes($endA);
    $b1 = dj_season_time_minutes($startB);
    $b2 = dj_season_time_minutes($endB);

    if ($a2 <= $a1) $a2 += 1440;
    if ($b2 <= $b1) $b2 += 1440;

    return $a1 < $b2 && $b1 < $a2;
}

function dj_season_slot_conflicts_with_program(PDO $pdo, array $slot): bool {
    $day = (int)($slot['day_of_week'] ?? 0);
    $stmt = $pdo->prepare(
        "SELECT start_time, end_time FROM program WHERE day_of_week = ?"
    );
    $stmt->execute([$day]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $show) {
        if (dj_season_ranges_overlap(
            (string)$slot['start_time'],
            (string)$slot['end_time'],
            (string)$show['start_time'],
            (string)$show['end_time']
        )) {
            return true;
        }
    }

    return false;
}

function dj_season_slots(PDO $pdo, bool $includeInactive = false): array {
    $sql = "SELECT s.id, s.season, s.day_of_week, s.start_time, s.end_time, s.is_active,
                   b.id AS booking_id
            FROM dj_season_slots s
            LEFT JOIN dj_season_bookings b ON b.slot_id = s.id
            WHERE s.season = ?";
    if (!$includeInactive) {
        $sql .= " AND s.is_active = 1";
    }
    $sql .= " ORDER BY s.day_of_week ASC, s.start_time ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([DESEO_DJ_SEASON]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $strictKeys = [];
    foreach (dj_season_strict_slot_definitions() as $definition) {
        $strictKeys[(int)$definition['day_of_week'] . '|' . (string)$definition['start_time']] = true;
    }

    $slots = array_values(array_filter($slots, static function (array $slot) use ($strictKeys): bool {
        $key = (int)$slot['day_of_week'] . '|' . (string)$slot['start_time'];
        return isset($strictKeys[$key]);
    }));

    foreach ($slots as &$slot) {
        $slot['program_conflict'] = false;
        $slot['available'] =
            (int)$slot['is_active'] === 1
            && empty($slot['booking_id']);
    }
    unset($slot);

    return $slots;
}

function dj_season_day_label(int $day): string {
    $labels = [
        1 => 'Δευτέρα',
        2 => 'Τρίτη',
        3 => 'Τετάρτη',
        4 => 'Πέμπτη',
        5 => 'Παρασκευή',
        6 => 'Σάββατο',
        7 => 'Κυριακή',
    ];
    return $labels[$day] ?? '—';
}

function dj_season_format_time(?string $time): string {
    if (!$time) return '--:--';
    return substr($time, 0, 5);
}

function dj_season_clean_url(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . ltrim($value, '/');
    }
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}
