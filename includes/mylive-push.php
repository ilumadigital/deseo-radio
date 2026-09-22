<?php
declare(strict_types=1);

require_once __DIR__ . '/dj-portal.php';

function deseo_mylive_push_env(string $primary, string $fallback = ''): string {
    $value = getenv($primary);
    if ($value !== false && trim((string)$value) !== '') {
        return trim((string)$value);
    }

    if ($fallback !== '') {
        $fallbackValue = getenv($fallback);
        if ($fallbackValue !== false && trim((string)$fallbackValue) !== '') {
            return trim((string)$fallbackValue);
        }
    }

    return '';
}

function deseo_mylive_push_public_key(): string {
    return deseo_mylive_push_env('MYLIVE_WEBPUSHR_PUBLIC_KEY', 'WEBPUSHR_PUBLIC_KEY');
}

function deseo_mylive_push_api_key(): string {
    return deseo_mylive_push_env('MYLIVE_WEBPUSHR_API_KEY', 'WEBPUSHR_API_KEY');
}

function deseo_mylive_push_auth_token(): string {
    return deseo_mylive_push_env('MYLIVE_WEBPUSHR_AUTH_TOKEN', 'WEBPUSHR_AUTH_TOKEN');
}

function deseo_mylive_push_cron_token(): string {
    return deseo_mylive_push_env('MYLIVE_PUSH_CRON_TOKEN');
}

function deseo_mylive_push_base_url(): string {
    $configured = rtrim(deseo_mylive_push_env('MYLIVE_BASE_URL'), '/');
    return $configured !== '' ? $configured : 'https://deseoradio.com';
}

function deseo_mylive_push_api_configured(): bool {
    return deseo_mylive_push_api_key() !== '' && deseo_mylive_push_auth_token() !== '';
}

function deseo_mylive_push_bootstrap(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_push_notification_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT NOT NULL,
        program_id INT NOT NULL,
        notification_type VARCHAR(40) NOT NULL,
        event_key VARCHAR(160) NOT NULL,
        scheduled_for DATETIME NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NOT NULL DEFAULT '',
        sent_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_push_event (event_key),
        KEY idx_push_account (account_id, scheduled_for),
        KEY idx_push_status (status, scheduled_for)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function deseo_mylive_push_event_sent(PDO $pdo, string $eventKey): bool {
    $stmt = $pdo->prepare(
        "SELECT sent_at
         FROM dj_push_notification_log
         WHERE event_key = ?
         LIMIT 1"
    );
    $stmt->execute([$eventKey]);
    $sentAt = $stmt->fetchColumn();

    return is_string($sentAt) && trim($sentAt) !== '';
}

function deseo_mylive_push_log(
    PDO $pdo,
    int $accountId,
    int $programId,
    string $type,
    string $eventKey,
    DateTimeImmutable $scheduledFor,
    bool $success,
    string $error = ''
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO dj_push_notification_log
         (account_id, program_id, notification_type, event_key, scheduled_for, status, attempts, last_error, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            attempts = attempts + 1,
            last_error = VALUES(last_error),
            sent_at = CASE
                WHEN VALUES(sent_at) IS NOT NULL THEN VALUES(sent_at)
                ELSE sent_at
            END"
    );

    $stmt->execute([
        $accountId,
        $programId,
        $type,
        $eventKey,
        $scheduledFor->format('Y-m-d H:i:s'),
        $success ? 'sent' : 'failed',
        substr($error, 0, 500),
        $success ? (new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->format('Y-m-d H:i:s') : null,
    ]);
}

function deseo_mylive_push_send_to_account(
    int $accountId,
    string $title,
    string $message,
    string $targetPath,
    string $campaignName,
    string $expirePush = '1d'
): array {
    if (!deseo_mylive_push_api_configured()) {
        return ['ok' => false, 'error' => 'Webpushr API credentials are not configured.'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL extension is not available.'];
    }

    $baseUrl = deseo_mylive_push_base_url();
    $targetUrl = $baseUrl . '/' . ltrim($targetPath, '/');

    $payload = [
        'title' => mb_substr($title, 0, 100),
        'message' => mb_substr($message, 0, 255),
        'target_url' => mb_substr($targetUrl, 0, 255),
        'attribute' => [
            'mylive_account_id' => (string)$accountId,
        ],
        'name' => mb_substr($campaignName, 0, 100),
        'icon' => $baseUrl . '/assets/img/favicon.png',
        'expire_push' => $expirePush,
        'auto_hide' => 0,
    ];

    $ch = curl_init('https://api.webpushr.com/v1/notification/send/attribute');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'webpushrKey: ' . deseo_mylive_push_api_key(),
            'webpushrAuthToken: ' . deseo_mylive_push_auth_token(),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        return ['ok' => false, 'error' => $curlError !== '' ? $curlError : 'Unknown Webpushr transport error.'];
    }

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'error' => 'Webpushr HTTP ' . $status . ': ' . substr((string)$body, 0, 350),
        ];
    }

    return ['ok' => true, 'response' => (string)$body];
}

function deseo_mylive_push_next_program_occurrence(array $show, DateTimeImmutable $now): array {
    $tz = new DateTimeZone('Europe/Athens');
    $now = $now->setTimezone($tz);

    $day = max(1, min(7, (int)($show['day_of_week'] ?? 1)));
    $todayDay = (int)$now->format('N');
    $daysAhead = ($day - $todayDay + 7) % 7;

    $baseDate = $now->setTime(0, 0, 0)->modify('+' . $daysAhead . ' days');
    $startTime = substr((string)($show['start_time'] ?? '00:00:00'), 0, 8);
    $endTime = substr((string)($show['end_time'] ?? '00:00:00'), 0, 8);

    $start = new DateTimeImmutable($baseDate->format('Y-m-d') . ' ' . $startTime, $tz);
    $end = new DateTimeImmutable($baseDate->format('Y-m-d') . ' ' . $endTime, $tz);
    if ($end <= $start) {
        $end = $end->modify('+1 day');
    }

    if ($now > $end) {
        $start = $start->modify('+7 days');
        $end = $end->modify('+7 days');
    }

    return [$start, $end];
}

function deseo_mylive_push_delivery_status(PDO $pdo, int $accountId): string {
    $stmt = $pdo->prepare(
        "SELECT status
         FROM dj_portal_sets
         WHERE account_id = ?
           AND status <> 'broadcasted'
           AND file_deleted_at IS NULL
         ORDER BY episode_no DESC
         LIMIT 1"
    );
    $stmt->execute([$accountId]);
    $status = strtolower(trim((string)($stmt->fetchColumn() ?: '')));

    return in_array($status, deseo_mylive_set_statuses(), true) ? $status : '';
}

function deseo_mylive_run_scheduled_pushes(PDO $pdo, ?DateTimeImmutable $now = null): array {
    deseo_mylive_push_bootstrap($pdo);

    $tz = new DateTimeZone('Europe/Athens');
    $now = ($now ?: new DateTimeImmutable('now', $tz))->setTimezone($tz);

    $summary = [
        'configured' => deseo_mylive_push_api_configured(),
        'checked' => 0,
        'sent' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    if (!$summary['configured']) {
        return $summary;
    }

    $stmt = $pdo->query(
        "SELECT p.id AS program_id,
                p.dj_name,
                p.day_of_week,
                p.start_time,
                p.end_time,
                a.id AS account_id,
                a.artist_name
         FROM program p
         INNER JOIN dj_portal_accounts a ON a.id = p.mylive_account_id
         WHERE p.mylive_account_id IS NOT NULL
           AND a.is_active = 1
           AND a.account_status = 'active'
         ORDER BY p.day_of_week ASC, p.start_time ASC"
    );

    $shows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($shows as $show) {
        $summary['checked']++;

        $accountId = (int)$show['account_id'];
        $programId = (int)$show['program_id'];
        $artistName = trim((string)($show['artist_name'] ?? ''));
        [$showStart, $showEnd] = deseo_mylive_push_next_program_occurrence($show, $now);

        // 1. Reminder two days before the weekly broadcast.
        $reminderAt = $showStart->modify('-2 days');
        if (
            $now->format('Y-m-d') === $reminderAt->format('Y-m-d')
            && $now >= $reminderAt
        ) {
            $eventKey = 'upload-reminder:' . $programId . ':' . $showStart->format('Y-m-d');

            if (!deseo_mylive_push_event_sent($pdo, $eventKey)) {
                $deliveryStatus = deseo_mylive_push_delivery_status($pdo, $accountId);

                if ($deliveryStatus === '' || $deliveryStatus === 'needs_changes') {
                    $needsChanges = $deliveryStatus === 'needs_changes';
                    $title = $needsChanges
                        ? 'MyLive · Action required'
                        : 'MyLive · DJ Set reminder';
                    $message = $needsChanges
                        ? 'Το DJ Set σου χρειάζεται αλλαγές πριν από τη μετάδοση σε 2 ημέρες. Μπες σήμερα στο MyLive για να προλάβουμε τον έλεγχο.'
                        : 'Το show σου παίζει σε 2 ημέρες. Ανέβασε σήμερα το DJ Set σου στο MyLive για να προλάβουμε έγκαιρα τον έλεγχο και τον προγραμματισμό.';

                    $result = deseo_mylive_push_send_to_account(
                        $accountId,
                        $title,
                        $message,
                        '/mylive/#sets',
                        'MyLive · 2-day reminder · ' . ($artistName !== '' ? $artistName : ('DJ ' . $accountId)),
                        '2d'
                    );

                    deseo_mylive_push_log(
                        $pdo,
                        $accountId,
                        $programId,
                        'upload_reminder',
                        $eventKey,
                        $reminderAt,
                        !empty($result['ok']),
                        (string)($result['error'] ?? '')
                    );

                    if (!empty($result['ok'])) {
                        $summary['sent']++;
                    } else {
                        $summary['failed']++;
                    }
                } else {
                    $summary['skipped']++;
                }
            } else {
                $summary['skipped']++;
            }
        }

        // 2. Notify once when the DJ's actual Program slot is on air.
        if ($now >= $showStart && $now < $showEnd) {
            $eventKey = 'on-air:' . $programId . ':' . $showStart->format('Y-m-d');

            if (!deseo_mylive_push_event_sent($pdo, $eventKey)) {
                $result = deseo_mylive_push_send_to_account(
                    $accountId,
                    'Deseo Radio · You are on air',
                    ($artistName !== '' ? $artistName . ', ' : '') . 'το DJ Set σου παίζει τώρα στον Deseo Radio. Άκου live μέσα από το MyLive.',
                    '/mylive/#live',
                    'MyLive · On Air · ' . ($artistName !== '' ? $artistName : ('DJ ' . $accountId)),
                    '2h'
                );

                deseo_mylive_push_log(
                    $pdo,
                    $accountId,
                    $programId,
                    'on_air',
                    $eventKey,
                    $showStart,
                    !empty($result['ok']),
                    (string)($result['error'] ?? '')
                );

                if (!empty($result['ok'])) {
                    $summary['sent']++;
                } else {
                    $summary['failed']++;
                }
            } else {
                $summary['skipped']++;
            }
        }
    }

    return $summary;
}
