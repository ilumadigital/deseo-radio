<?php
declare(strict_types=1);

require_once __DIR__ . '/dj-portal.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mylive-communications.php';

function deseo_mylive_email_scheduler_bootstrap(PDO $pdo): void {
    deseo_mylive_communications_bootstrap($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_email_automation_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT NOT NULL,
        program_id INT NOT NULL,
        episode_no INT NOT NULL,
        event_type VARCHAR(32) NOT NULL,
        event_key VARCHAR(190) NOT NULL,
        show_start DATETIME NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        last_error VARCHAR(500) NOT NULL DEFAULT '',
        sent_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_dj_email_event (event_key),
        KEY idx_dj_email_account (account_id, show_start),
        KEY idx_dj_email_status (status, show_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_email_automation_state (
        id TINYINT NOT NULL PRIMARY KEY,
        last_checked_at DATETIME NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec(
        "INSERT INTO dj_email_automation_state (id, last_checked_at)
         VALUES (1, NULL)
         ON DUPLICATE KEY UPDATE id = VALUES(id)"
    );
}

function deseo_mylive_email_event_sent(PDO $pdo, string $eventKey): bool {
    $stmt = $pdo->prepare(
        "SELECT sent_at
         FROM dj_email_automation_log
         WHERE event_key = ?
         LIMIT 1"
    );
    $stmt->execute([$eventKey]);
    $sentAt = $stmt->fetchColumn();

    return is_string($sentAt) && trim($sentAt) !== '';
}

function deseo_mylive_email_event_log(
    PDO $pdo,
    int $accountId,
    int $programId,
    int $episodeNo,
    string $eventType,
    string $eventKey,
    DateTimeImmutable $showStart,
    bool $success,
    string $error = ''
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO dj_email_automation_log
         (account_id, program_id, episode_no, event_type, event_key, show_start, status, last_error, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            last_error = VALUES(last_error),
            sent_at = CASE
                WHEN VALUES(sent_at) IS NOT NULL THEN VALUES(sent_at)
                ELSE sent_at
            END"
    );

    $sentAt = $success
        ? (new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->format('Y-m-d H:i:s')
        : null;

    $stmt->execute([
        $accountId,
        $programId,
        $episodeNo,
        $eventType,
        $eventKey,
        $showStart->format('Y-m-d H:i:s'),
        $success ? 'sent' : 'failed',
        substr($error, 0, 500),
        $sentAt,
    ]);
}

function deseo_mylive_email_program_occurrence(array $show, DateTimeImmutable $now): array {
    $tz = new DateTimeZone('Europe/Athens');
    $now = $now->setTimezone($tz);

    $day = max(1, min(7, (int)($show['day_of_week'] ?? 1)));
    $daysAhead = ($day - (int)$now->format('N') + 7) % 7;
    $date = $now->setTime(0, 0)->modify('+' . $daysAhead . ' days');

    $startRaw = substr((string)($show['start_time'] ?? '00:00:00'), 0, 8);
    $endRaw = substr((string)($show['end_time'] ?? '00:00:00'), 0, 8);

    $start = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $startRaw, $tz);
    $end = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $endRaw, $tz);

    if ($end <= $start) {
        $end = $end->modify('+1 day');
    }

    if ($now >= $end) {
        $start = $start->modify('+7 days');
        $end = $end->modify('+7 days');
    }

    return [$start, $end];
}

function deseo_mylive_email_pending_set(PDO $pdo, int $accountId): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, episode_no, status, uploaded_at
         FROM dj_portal_sets
         WHERE account_id = ?
           AND status <> 'broadcasted'
           AND file_deleted_at IS NULL
         ORDER BY episode_no DESC
         LIMIT 1"
    );
    $stmt->execute([$accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function deseo_mylive_email_latest_episode(PDO $pdo, int $accountId): int {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(episode_no), 0)
         FROM dj_portal_sets
         WHERE account_id = ?"
    );
    $stmt->execute([$accountId]);

    return max(0, (int)$stmt->fetchColumn());
}

function deseo_mylive_email_scheduler_should_check(PDO $pdo, DateTimeImmutable $now, int $minimumSeconds = 45): bool {
    $stmt = $pdo->query(
        "SELECT last_checked_at
         FROM dj_email_automation_state
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

function deseo_mylive_run_email_scheduler(PDO $pdo, bool $force = false): array {
    $tz = new DateTimeZone('Europe/Athens');
    $now = new DateTimeImmutable('now', $tz);
    $summary = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'throttled' => false];

    deseo_mylive_email_scheduler_bootstrap($pdo);

    if (!$force && !deseo_mylive_email_scheduler_should_check($pdo, $now)) {
        $summary['throttled'] = true;
        return $summary;
    }

    $lockStmt = $pdo->query("SELECT GET_LOCK('deseo_mylive_email_scheduler', 0)");
    $lockAcquired = $lockStmt && (int)$lockStmt->fetchColumn() === 1;
    if (!$lockAcquired) {
        $summary['throttled'] = true;
        return $summary;
    }

    try {
        if (!$force && !deseo_mylive_email_scheduler_should_check($pdo, $now)) {
            $summary['throttled'] = true;
            return $summary;
        }

        $pdo->prepare(
            "UPDATE dj_email_automation_state
             SET last_checked_at = ?
             WHERE id = 1"
        )->execute([$now->format('Y-m-d H:i:s')]);

        $stmt = $pdo->query(
            "SELECT a.id AS program_id,
                    a.day_of_week,
                    a.start_time,
                    a.end_time,
                    a.id AS account_id,
                    a.artist_name,
                    a.full_name,
                    a.email
             FROM dj_portal_accounts a
             WHERE a.is_active = 1
               AND a.account_status = 'active'
               AND a.email <> ''
               AND a.day_of_week BETWEEN 1 AND 7
               AND a.start_time IS NOT NULL
               AND a.start_time <> ''
             ORDER BY a.day_of_week ASC, a.start_time ASC"
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

            // Reminder on the calendar day exactly three days before the next broadcast.
            $reminderDay = $showStart->modify('-3 days')->format('Y-m-d');
            if ($now->format('Y-m-d') === $reminderDay) {
                $eventKey = 'set-due:' . $programId . ':' . $showStart->format('Y-m-d') . ':ep' . $nextEpisode;

                if ($pendingSet) {
                    $summary['skipped']++;
                } elseif (!deseo_mylive_communication_allows($pdo, $accountId, 'set_reminder', 'email')) {
                    $summary['skipped']++;
                } elseif (deseo_mylive_email_event_sent($pdo, $eventKey)) {
                    $summary['skipped']++;
                } else {
                    $mail = deseo_mylive_set_due_email($show, [
                        'episode' => $nextEpisode,
                        'show_date' => $showStart->format('d.m.Y'),
                        'show_time' => $showStart->format('H:i'),
                    ]);

                    try {
                        deseo_send_smtp_mail(
                            (string)$show['email'],
                            (string)$show['artist_name'],
                            (string)$mail['subject'],
                            (string)$mail['html'],
                            (string)$mail['text'],
                            true
                        );

                        deseo_mylive_email_event_log(
                            $pdo,
                            $accountId,
                            $programId,
                            $nextEpisode,
                            'set_due',
                            $eventKey,
                            $showStart,
                            true
                        );
                        $summary['sent']++;
                    } catch (Throwable $mailError) {
                        deseo_mylive_email_event_log(
                            $pdo,
                            $accountId,
                            $programId,
                            $nextEpisode,
                            'set_due',
                            $eventKey,
                            $showStart,
                            false,
                            $mailError->getMessage()
                        );
                        error_log('MyLive DJ set reminder failed for account ' . $accountId . ': ' . $mailError->getMessage());
                        $summary['failed']++;
                    }
                }
            }

            // Social reminder once during the actual live slot.
            if ($now >= $showStart && $now < $showEnd) {
                $liveEpisode = $pendingSet
                    ? (int)$pendingSet['episode_no']
                    : max(1, $latestEpisode);

                $eventKey = 'on-air-social:' . $programId . ':' . $showStart->format('Y-m-d');

                if (!deseo_mylive_communication_allows($pdo, $accountId, 'on_air', 'email')) {
                    $summary['skipped']++;
                } elseif (deseo_mylive_email_event_sent($pdo, $eventKey)) {
                    $summary['skipped']++;
                } else {
                    $mail = deseo_mylive_on_air_social_email($show, [
                        'episode' => $liveEpisode,
                        'show_time' => $showStart->format('H:i'),
                    ]);

                    try {
                        deseo_send_smtp_mail(
                            (string)$show['email'],
                            (string)$show['artist_name'],
                            (string)$mail['subject'],
                            (string)$mail['html'],
                            (string)$mail['text'],
                            true
                        );

                        deseo_mylive_email_event_log(
                            $pdo,
                            $accountId,
                            $programId,
                            $liveEpisode,
                            'on_air_social',
                            $eventKey,
                            $showStart,
                            true
                        );
                        $summary['sent']++;
                    } catch (Throwable $mailError) {
                        deseo_mylive_email_event_log(
                            $pdo,
                            $accountId,
                            $programId,
                            $liveEpisode,
                            'on_air_social',
                            $eventKey,
                            $showStart,
                            false,
                            $mailError->getMessage()
                        );
                        error_log('MyLive on-air social email failed for account ' . $accountId . ': ' . $mailError->getMessage());
                        $summary['failed']++;
                    }
                }
            }
        }

        return $summary;
    } finally {
        try {
            $pdo->query("SELECT RELEASE_LOCK('deseo_mylive_email_scheduler')");
        } catch (Throwable $unlockError) {
            error_log('MyLive email scheduler lock release failed: ' . $unlockError->getMessage());
        }
    }
}

function deseo_mylive_maybe_run_email_scheduler(PDO $pdo): void {
    try {
        deseo_mylive_run_email_scheduler($pdo, false);
    } catch (Throwable $e) {
        error_log('MyLive email scheduler failed: ' . $e->getMessage());
    }
}
