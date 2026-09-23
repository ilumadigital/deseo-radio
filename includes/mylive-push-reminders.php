<?php
declare(strict_types=1);

require_once __DIR__ . '/mylive-email-reminders.php';
require_once __DIR__ . '/mylive-push.php';
require_once __DIR__ . '/mylive-communications.php';

function deseo_mylive_push_scheduler_bootstrap(PDO $pdo): void {
    deseo_mylive_communications_bootstrap($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dj_push_automation_state (
            id TINYINT NOT NULL PRIMARY KEY,
            last_checked_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "INSERT INTO dj_push_automation_state (id, last_checked_at)
         VALUES (1, NULL)
         ON DUPLICATE KEY UPDATE id = VALUES(id)"
    );
}

function deseo_mylive_push_scheduler_should_check(
    PDO $pdo,
    DateTimeImmutable $now,
    int $minimumSeconds = 45
): bool {
    $stmt = $pdo->query(
        "SELECT last_checked_at
         FROM dj_push_automation_state
         WHERE id = 1
         LIMIT 1"
    );
    $last = $stmt ? $stmt->fetchColumn() : false;

    if (!is_string($last) || trim($last) === '') {
        return true;
    }

    try {
        $lastAt = new DateTimeImmutable($last, new DateTimeZone('Europe/Athens'));
    } catch (Throwable $e) {
        return true;
    }

    return ($now->getTimestamp() - $lastAt->getTimestamp()) >= $minimumSeconds;
}

function deseo_mylive_run_push_scheduler(PDO $pdo, bool $force = false): array {
    $tz = new DateTimeZone('Europe/Athens');
    $now = new DateTimeImmutable('now', $tz);
    $summary = [
        'checked' => 0,
        'sent' => 0,
        'skipped' => 0,
        'failed' => 0,
        'throttled' => false,
        'configured' => deseo_mylive_push_configured(),
    ];

    deseo_mylive_push_scheduler_bootstrap($pdo);

    if (!$summary['configured']) {
        return $summary;
    }

    if (!$force && !deseo_mylive_push_scheduler_should_check($pdo, $now)) {
        $summary['throttled'] = true;
        return $summary;
    }

    $lockStmt = $pdo->query("SELECT GET_LOCK('deseo_mylive_push_scheduler', 0)");
    $lockAcquired = $lockStmt && (int)$lockStmt->fetchColumn() === 1;
    if (!$lockAcquired) {
        $summary['throttled'] = true;
        return $summary;
    }

    try {
        if (!$force && !deseo_mylive_push_scheduler_should_check($pdo, $now)) {
            $summary['throttled'] = true;
            return $summary;
        }

        $pdo->prepare(
            "UPDATE dj_push_automation_state
             SET last_checked_at = ?
             WHERE id = 1"
        )->execute([$now->format('Y-m-d H:i:s')]);

        $stmt = $pdo->query(
            "SELECT p.id AS program_id,
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
               AND a.push_notifications_enabled = 1
             ORDER BY p.day_of_week ASC, p.start_time ASC"
        );

        $shows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($shows as $show) {
            $summary['checked']++;

            $accountId = (int)$show['account_id'];
            $programId = (int)$show['program_id'];
            [$showStart, $showEnd] = deseo_mylive_email_program_occurrence($show, $now);

            $pendingSet = deseo_mylive_email_pending_set($pdo, $accountId);
            $latestEpisode = deseo_mylive_email_latest_episode($pdo, $accountId);
            $nextEpisode = $pendingSet
                ? (int)$pendingSet['episode_no']
                : ($latestEpisode + 1);

            $reminderDay = $showStart->modify('-3 days')->format('Y-m-d');
            if ($now->format('Y-m-d') === $reminderDay && !$pendingSet) {
                $eventKey = 'set-due:' . $programId . ':' . $showStart->format('Y-m-d') . ':ep' . $nextEpisode;

                if (!deseo_mylive_communication_allows($pdo, $accountId, 'set_reminder', 'push')) {
                    $summary['skipped']++;
                } elseif (deseo_mylive_push_event_sent($pdo, $eventKey)) {
                    $summary['skipped']++;
                } else {
                    $artistName = trim((string)($show['artist_name'] ?? ''));
                    $message = ($artistName !== '' ? $artistName . ', ' : '')
                        . 'σε 3 ημέρες είναι το επόμενο show σου. Ανέβασε το EP'
                        . str_pad((string)$nextEpisode, 3, '0', STR_PAD_LEFT)
                        . ' στο MyLive.';

                    try {
                        $result = deseo_mylive_push_send_to_account(
                            $pdo,
                            $accountId,
                            'DJ Set Reminder · Deseo Radio',
                            $message,
                            'https://deseoradio.com/mylive/#sets',
                            [
                                'name' => 'Set Reminder · ' . $artistName,
                                'expire_push' => '3d',
                                'auto_hide' => 1,
                            ]
                        );

                        deseo_mylive_push_event_log(
                            $pdo,
                            $accountId,
                            $programId,
                            $nextEpisode,
                            'set_due',
                            $eventKey,
                            $showStart,
                            true,
                            '',
                            (string)($result['ID'] ?? '')
                        );
                        $summary['sent']++;
                    } catch (Throwable $pushError) {
                        deseo_mylive_push_event_log(
                            $pdo,
                            $accountId,
                            $programId,
                            $nextEpisode,
                            'set_due',
                            $eventKey,
                            $showStart,
                            false,
                            $pushError->getMessage()
                        );
                        error_log('MyLive DJ set reminder push failed for account ' . $accountId . ': ' . $pushError->getMessage());
                        $summary['failed']++;
                    }
                }
            }

            if ($now >= $showStart && $now < $showEnd) {
                $liveEpisode = $pendingSet
                    ? (int)$pendingSet['episode_no']
                    : max(1, $latestEpisode);

                $eventKey = 'on-air-social:' . $programId . ':' . $showStart->format('Y-m-d');

                if (!deseo_mylive_communication_allows($pdo, $accountId, 'on_air', 'push')) {
                    $summary['skipped']++;
                } elseif (deseo_mylive_push_event_sent($pdo, $eventKey)) {
                    $summary['skipped']++;
                } else {
                    $artistName = trim((string)($show['artist_name'] ?? ''));
                    $message = ($artistName !== '' ? $artistName . ', ' : '')
                        . 'το show σου είναι live τώρα. Ανέβασε το δημιουργικό σου στα social media και κάνε tag το Deseo Radio.';

                    try {
                        $result = deseo_mylive_push_send_to_account(
                            $pdo,
                            $accountId,
                            'Είσαι τώρα στον αέρα · Deseo Radio',
                            $message,
                            'https://deseoradio.com',
                            [
                                'name' => 'On Air · ' . $artistName,
                                'expire_push' => '2h',
                                'auto_hide' => 0,
                            ]
                        );

                        deseo_mylive_push_event_log(
                            $pdo,
                            $accountId,
                            $programId,
                            $liveEpisode,
                            'on_air_social',
                            $eventKey,
                            $showStart,
                            true,
                            '',
                            (string)($result['ID'] ?? '')
                        );
                        $summary['sent']++;
                    } catch (Throwable $pushError) {
                        deseo_mylive_push_event_log(
                            $pdo,
                            $accountId,
                            $programId,
                            $liveEpisode,
                            'on_air_social',
                            $eventKey,
                            $showStart,
                            false,
                            $pushError->getMessage()
                        );
                        error_log('MyLive on-air push failed for account ' . $accountId . ': ' . $pushError->getMessage());
                        $summary['failed']++;
                    }
                }
            }
        }

        return $summary;
    } finally {
        try {
            $pdo->query("SELECT RELEASE_LOCK('deseo_mylive_push_scheduler')");
        } catch (Throwable $unlockError) {
            error_log('MyLive push scheduler lock release failed: ' . $unlockError->getMessage());
        }
    }
}

function deseo_mylive_maybe_run_push_scheduler(PDO $pdo): void {
    try {
        deseo_mylive_run_push_scheduler($pdo, false);
    } catch (Throwable $e) {
        error_log('MyLive push scheduler failed: ' . $e->getMessage());
    }
}
