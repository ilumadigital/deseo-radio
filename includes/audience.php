<?php
declare(strict_types=1);

function deseo_audience_bootstrap(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS deseo_audience_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            monthly_listeners BIGINT UNSIGNED NOT NULL DEFAULT 0,
            audience_month CHAR(7) NOT NULL DEFAULT '',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columnStmt = $pdo->prepare("SHOW COLUMNS FROM deseo_audience_settings LIKE ?");
    $columnStmt->execute(['audience_month']);
    if (!$columnStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE deseo_audience_settings ADD COLUMN audience_month CHAR(7) NOT NULL DEFAULT '' AFTER monthly_listeners");
    }

    $pdo->exec(
        "INSERT IGNORE INTO deseo_audience_settings (id, monthly_listeners, audience_month)
         VALUES (1, 0, '')"
    );

    $currentMonth = date('Y-m');
    $stmt = $pdo->prepare(
        "UPDATE deseo_audience_settings
         SET audience_month = ?
         WHERE id = 1 AND monthly_listeners > 0 AND audience_month = ''"
    );
    $stmt->execute([$currentMonth]);
}

function deseo_audience_current_month_key(): string {
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Athens'));
    return $now->format('Y-m');
}

function deseo_audience_month_label(?string $monthKey = null): string {
    $monthKey = $monthKey ?: deseo_audience_current_month_key();
    [$year, $month] = array_pad(explode('-', $monthKey, 2), 2, '');

    $months = [
        '01' => 'Ιανουάριος',
        '02' => 'Φεβρουάριος',
        '03' => 'Μάρτιος',
        '04' => 'Απρίλιος',
        '05' => 'Μάιος',
        '06' => 'Ιούνιος',
        '07' => 'Ιούλιος',
        '08' => 'Αύγουστος',
        '09' => 'Σεπτέμβριος',
        '10' => 'Οκτώβριος',
        '11' => 'Νοέμβριος',
        '12' => 'Δεκέμβριος',
    ];

    $label = $months[$month] ?? $monthKey;
    return trim($label . ' ' . $year);
}

function deseo_audience_stored_month(PDO $pdo): string {
    deseo_audience_bootstrap($pdo);
    $value = $pdo->query(
        "SELECT audience_month FROM deseo_audience_settings WHERE id = 1 LIMIT 1"
    )->fetchColumn();

    return trim((string)$value);
}

function deseo_audience_monthly_listeners(PDO $pdo): int {
    deseo_audience_bootstrap($pdo);
    $stmt = $pdo->prepare(
        "SELECT monthly_listeners
         FROM deseo_audience_settings
         WHERE id = 1 AND audience_month = ?
         LIMIT 1"
    );
    $stmt->execute([deseo_audience_current_month_key()]);
    $value = $stmt->fetchColumn();

    return max(0, (int)($value ?: 0));
}

function deseo_audience_updated_at(PDO $pdo): ?string {
    deseo_audience_bootstrap($pdo);
    $value = $pdo->query(
        "SELECT updated_at FROM deseo_audience_settings WHERE id = 1 LIMIT 1"
    )->fetchColumn();

    return $value ? (string)$value : null;
}

function deseo_audience_hour_share(int $hour): float {
    return match ($hour) {
        // Main afternoon peak
        15 => 0.145,
        16 => 0.150,
        17 => 0.155,

        // Early evening: slightly stronger than 15:00–18:00
        18 => 0.160,
        19 => 0.165,

        // Strongest peak of the day
        20 => 0.172,
        21 => 0.178,

        // Gradual late-evening decline
        22 => 0.155,
        23 => 0.138,

        14 => 0.115,
        default => 0.070,
    };
}

function deseo_audience_day_modifier(?int $dayOfWeek): float {
    return match ($dayOfWeek) {
        4 => 0.98, // Thursday
        5 => 1.04, // Friday
        6 => 1.07, // Saturday
        7 => 1.01, // Sunday
        default => 1.00,
    };
}

function deseo_audience_time_to_minutes(?string $time): int {
    if (!$time) return 0;
    $parts = explode(':', $time);
    $hour = isset($parts[0]) ? (int)$parts[0] : 0;
    $minute = isset($parts[1]) ? (int)$parts[1] : 0;
    return max(0, min(1440, ($hour * 60) + $minute));
}

function deseo_audience_slot_share(?string $startTime, ?string $endTime): float {
    $start = deseo_audience_time_to_minutes($startTime);
    $end = deseo_audience_time_to_minutes($endTime);

    if ($end <= $start) {
        $end = min(1440, $start + 60);
    }

    $weighted = 0.0;
    $duration = max(1, $end - $start);

    for ($minute = $start; $minute < $end; $minute++) {
        $hour = intdiv($minute, 60);
        $weighted += deseo_audience_hour_share($hour);
    }

    return $weighted / $duration;
}

function deseo_audience_seeded_variation(
    int $accountId,
    ?int $dayOfWeek,
    ?string $startTime,
    ?string $endTime,
    ?string $monthKey = null
): float {
    $monthKey = $monthKey ?: date('Y-m');
    $seed = implode('|', [
        'deseo-audience-v1',
        $accountId,
        $dayOfWeek ?? 0,
        $startTime ?? '',
        $endTime ?? '',
        $monthKey,
    ]);

    $hash = sprintf('%u', crc32($seed));
    $normalized = ((int)$hash % 10001) / 10000;

    // Stable monthly variation: -6% to +6%.
    return 0.94 + ($normalized * 0.12);
}

function deseo_audience_estimated_reach(PDO $pdo, array $account): int {
    $monthly = deseo_audience_monthly_listeners($pdo);
    if ($monthly <= 0) return 0;

    $day = isset($account['day_of_week']) ? (int)$account['day_of_week'] : null;
    $start = (string)($account['start_time'] ?? '');
    $end = (string)($account['end_time'] ?? '');

    $dailyAudience = $monthly / 30.4375;
    $slotShare = deseo_audience_slot_share($start, $end);
    $dayModifier = deseo_audience_day_modifier($day);

    $startMinutes = deseo_audience_time_to_minutes($start);
    $endMinutes = deseo_audience_time_to_minutes($end);
    if ($endMinutes <= $startMinutes) $endMinutes = $startMinutes + 60;

    $durationHours = max(0.5, ($endMinutes - $startMinutes) / 60);
    // Longer shows reach more unique listeners, but with overlap between hours.
    $durationModifier = pow($durationHours, 0.62);

    $variation = deseo_audience_seeded_variation(
        (int)($account['id'] ?? 0),
        $day,
        $start,
        $end
    );

    $estimate = $dailyAudience
        * $slotShare
        * $dayModifier
        * $durationModifier
        * $variation;

    return max(0, (int)round($estimate));
}

function deseo_audience_band_baseline(
    int $monthlyListeners,
    int $dayOfWeek,
    string $startTime,
    string $endTime
): int {
    if ($monthlyListeners <= 0) return 0;

    $dailyAudience = $monthlyListeners / 30.4375;
    $slotShare = deseo_audience_slot_share($startTime, $endTime);
    $dayModifier = deseo_audience_day_modifier($dayOfWeek);

    // CMS band previews are normalized to a one-hour equivalent.
    // This makes 15:00–18:00 comparable with one-hour late-night bands.
    return max(0, (int)round(
        $dailyAudience * $slotShare * $dayModifier
    ));
}

function deseo_audience_preview(
    int $monthlyListeners,
    int $dayOfWeek,
    string $startTime,
    string $endTime,
    int $seedId = 1
): int {
    if ($monthlyListeners <= 0) return 0;

    $dailyAudience = $monthlyListeners / 30.4375;
    $slotShare = deseo_audience_slot_share($startTime, $endTime);
    $dayModifier = deseo_audience_day_modifier($dayOfWeek);

    $startMinutes = deseo_audience_time_to_minutes($startTime);
    $endMinutes = deseo_audience_time_to_minutes($endTime);
    if ($endMinutes <= $startMinutes) $endMinutes = $startMinutes + 60;

    $durationHours = max(0.5, ($endMinutes - $startMinutes) / 60);
    $durationModifier = pow($durationHours, 0.62);
    $variation = deseo_audience_seeded_variation(
        $seedId,
        $dayOfWeek,
        $startTime,
        $endTime
    );

    return max(0, (int)round(
        $dailyAudience * $slotShare * $dayModifier * $durationModifier * $variation
    ));
}

function deseo_audience_format(int $value): string {
    return number_format(max(0, $value), 0, ',', '.');
}
