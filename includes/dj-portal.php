<?php
declare(strict_types=1);

require_once __DIR__ . '/../iluma/connection.php';
require_once __DIR__ . '/dj-season.php';

const DESEO_MYLive_MAX_BYTES = 1073741824; // 1 GB
const DESEO_MYLive_ASSET_MAX_BYTES = 268435456; // 256 MB

function deseo_mylive_column_exists(PDO $pdo, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: '';
    $stmt = $pdo->prepare("SHOW COLUMNS FROM " . $table . " LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function deseo_mylive_bootstrap(PDO $pdo): void {
    dj_season_bootstrap($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_portal_accounts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        booking_id BIGINT NULL,
        artist_name VARCHAR(180) NOT NULL DEFAULT '',
        full_name VARCHAR(180) NOT NULL DEFAULT '',
        email VARCHAR(254) NOT NULL,
        day_of_week TINYINT NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        password_hash VARCHAR(255) NOT NULL,
        must_change_password TINYINT(1) NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        account_status VARCHAR(20) NOT NULL DEFAULT 'active',
        show_audience_stats TINYINT(1) NOT NULL DEFAULT 0,
        public_profile_enabled TINYINT(1) NOT NULL DEFAULT 0,
        onboarding_email_sent_at DATETIME NULL,
        access_email_sent_at DATETIME NULL,
        last_login_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_portal_booking (booking_id),
        UNIQUE KEY uniq_portal_email (email),
        CONSTRAINT fk_portal_booking FOREIGN KEY (booking_id) REFERENCES dj_season_bookings(id)
            ON UPDATE CASCADE ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'artist_name' => "VARCHAR(180) NOT NULL DEFAULT '' AFTER booking_id",
        'full_name' => "VARCHAR(180) NOT NULL DEFAULT '' AFTER artist_name",
        'day_of_week' => "TINYINT NULL AFTER email",
        'start_time' => "TIME NULL AFTER day_of_week",
        'end_time' => "TIME NULL AFTER start_time",
        'must_change_password' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash",
        'account_status' => "VARCHAR(20) NOT NULL DEFAULT 'active' AFTER is_active",
        'show_audience_stats' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER account_status",
        'public_profile_enabled' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER show_audience_stats",
        'onboarding_email_sent_at' => "DATETIME NULL AFTER public_profile_enabled",
        'access_email_sent_at' => "DATETIME NULL AFTER onboarding_email_sent_at"
    ];
    foreach ($columns as $name => $definition) {
        if (!deseo_mylive_column_exists($pdo, 'dj_portal_accounts', $name)) {
            $pdo->exec("ALTER TABLE dj_portal_accounts ADD COLUMN " . $name . " " . $definition);
        }
    }

    // New MyLive accounts always start with audience statistics hidden.
    // This changes only the database default; existing DJ visibility choices are preserved.
    try {
        $pdo->exec(
            "ALTER TABLE dj_portal_accounts
             MODIFY show_audience_stats TINYINT(1) NOT NULL DEFAULT 0"
        );
    } catch (Throwable $e) {
        error_log('MyLive audience visibility default migration: ' . $e->getMessage());
    }

    try {
        $pdo->exec(
            "UPDATE dj_portal_accounts
             SET account_status = CASE
                 WHEN account_status = 'pending' THEN 'pending'
                 WHEN is_active = 1 THEN 'active'
                 ELSE 'disabled'
             END
             WHERE account_status NOT IN ('pending','active','disabled')
                OR account_status IS NULL
                OR account_status = ''"
        );
        $pdo->exec(
            "UPDATE dj_portal_accounts
             SET account_status = 'disabled'
             WHERE is_active = 0
               AND account_status = 'active'
               AND password_hash <> ''"
        );
    } catch (Throwable $e) {
        error_log('MyLive account status migration: ' . $e->getMessage());
    }

    try {
        $pdo->exec("ALTER TABLE dj_portal_accounts MODIFY booking_id BIGINT NULL");

        $ruleStmt = $pdo->query(
            "SELECT DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'dj_portal_accounts'
               AND CONSTRAINT_NAME = 'fk_portal_booking'
             LIMIT 1"
        );
        $deleteRule = $ruleStmt ? strtoupper((string)$ruleStmt->fetchColumn()) : '';
        if ($deleteRule !== '' && $deleteRule !== 'SET NULL') {
            $pdo->exec("ALTER TABLE dj_portal_accounts DROP FOREIGN KEY fk_portal_booking");
            $pdo->exec(
                "ALTER TABLE dj_portal_accounts
                 ADD CONSTRAINT fk_portal_booking
                 FOREIGN KEY (booking_id) REFERENCES dj_season_bookings(id)
                 ON UPDATE CASCADE ON DELETE SET NULL"
            );
        }
    } catch (Throwable $e) {
        error_log('MyLive booking link migration: ' . $e->getMessage());
    }

    try {
        $pdo->exec(
            "UPDATE dj_portal_accounts a
             INNER JOIN dj_season_bookings b ON b.id = a.booking_id
             INNER JOIN dj_season_slots s ON s.id = b.slot_id
             SET a.artist_name = CASE WHEN a.artist_name = '' THEN b.artist_name ELSE a.artist_name END,
                 a.full_name = CASE WHEN a.full_name = '' THEN b.full_name ELSE a.full_name END,
                 a.day_of_week = COALESCE(a.day_of_week, b.final_day_of_week, s.day_of_week),
                 a.start_time = COALESCE(a.start_time, b.final_start_time, s.start_time),
                 a.end_time = COALESCE(a.end_time, b.final_end_time, s.end_time)"
        );
    } catch (Throwable $e) {
        error_log('MyLive profile sync: ' . $e->getMessage());
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_portal_sets (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT NOT NULL,
        episode_no INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size BIGINT NOT NULL DEFAULT 0,
        mime_type VARCHAR(100) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'received',
        admin_note VARCHAR(500) NOT NULL DEFAULT '',
        broadcasted_at DATETIME NULL,
        delete_after DATETIME NULL,
        file_deleted_at DATETIME NULL,
        scheduled_show_end DATETIME NULL,
        hearthis_status VARCHAR(24) NOT NULL DEFAULT 'pending',
        hearthis_url VARCHAR(500) NULL,
        hearthis_track_id VARCHAR(120) NULL,
        hearthis_cover_asset_id BIGINT NULL,
        hearthis_error VARCHAR(500) NOT NULL DEFAULT '',
        hearthis_attempts INT NOT NULL DEFAULT 0,
        hearthis_started_at DATETIME NULL,
        hearthis_synced_at DATETIME NULL,
        uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_portal_episode (account_id, episode_no),
        KEY idx_portal_sets_account (account_id, uploaded_at),
        CONSTRAINT fk_portal_sets_account FOREIGN KEY (account_id) REFERENCES dj_portal_accounts(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $setColumns = [
        'broadcasted_at' => "DATETIME NULL AFTER admin_note",
        'delete_after' => "DATETIME NULL AFTER broadcasted_at",
        'file_deleted_at' => "DATETIME NULL AFTER delete_after",
        'scheduled_show_end' => "DATETIME NULL AFTER file_deleted_at",
        'hearthis_status' => "VARCHAR(24) NOT NULL DEFAULT 'pending' AFTER scheduled_show_end",
        'hearthis_url' => "VARCHAR(500) NULL AFTER hearthis_status",
        'hearthis_track_id' => "VARCHAR(120) NULL AFTER hearthis_url",
        'hearthis_cover_asset_id' => "BIGINT NULL AFTER hearthis_track_id",
        'hearthis_error' => "VARCHAR(500) NOT NULL DEFAULT '' AFTER hearthis_cover_asset_id",
        'hearthis_attempts' => "INT NOT NULL DEFAULT 0 AFTER hearthis_error",
        'hearthis_started_at' => "DATETIME NULL AFTER hearthis_attempts",
        'hearthis_synced_at' => "DATETIME NULL AFTER hearthis_started_at"
    ];
    foreach ($setColumns as $name => $definition) {
        if (!deseo_mylive_column_exists($pdo, 'dj_portal_sets', $name)) {
            $pdo->exec("ALTER TABLE dj_portal_sets ADD COLUMN " . $name . " " . $definition);
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_portal_assets (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT NOT NULL,
        asset_type VARCHAR(32) NOT NULL DEFAULT 'other',
        title VARCHAR(180) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size BIGINT NOT NULL DEFAULT 0,
        mime_type VARCHAR(100) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_portal_assets_account (account_id, created_at),
        CONSTRAINT fk_portal_assets_account FOREIGN KEY (account_id) REFERENCES dj_portal_accounts(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_public_profiles (
        account_id BIGINT PRIMARY KEY,
        draft_bio TEXT NOT NULL,
        draft_instagram VARCHAR(500) NOT NULL DEFAULT '',
        draft_tiktok VARCHAR(500) NOT NULL DEFAULT '',
        draft_soundcloud VARCHAR(500) NOT NULL DEFAULT '',
        draft_spotify VARCHAR(500) NOT NULL DEFAULT '',
        draft_website VARCHAR(500) NOT NULL DEFAULT '',
        published_bio TEXT NOT NULL,
        published_instagram VARCHAR(500) NOT NULL DEFAULT '',
        published_tiktok VARCHAR(500) NOT NULL DEFAULT '',
        published_soundcloud VARCHAR(500) NOT NULL DEFAULT '',
        published_spotify VARCHAR(500) NOT NULL DEFAULT '',
        published_website VARCHAR(500) NOT NULL DEFAULT '',
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        published_at DATETIME NULL,
        application_bio_seeded TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_public_profile_account FOREIGN KEY (account_id) REFERENCES dj_portal_accounts(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!deseo_mylive_column_exists($pdo, 'dj_public_profiles', 'application_bio_seeded')) {
        $pdo->exec(
            "ALTER TABLE dj_public_profiles
             ADD COLUMN application_bio_seeded TINYINT(1) NOT NULL DEFAULT 0 AFTER published_at"
        );
    }

    // One-time safe backfill for profiles created while application bios were not imported.
    // Only untouched, never-published profiles are filled, so existing DJ edits are preserved.
    $pdo->exec(
        "UPDATE dj_public_profiles p
         INNER JOIN dj_portal_accounts a ON a.id = p.account_id
         INNER JOIN dj_season_bookings b ON b.id = a.booking_id
         SET p.draft_bio = b.bio,
             p.application_bio_seeded = 1
         WHERE p.application_bio_seeded = 0
           AND TRIM(p.draft_bio) = ''
           AND TRIM(p.published_bio) = ''
           AND p.published_at IS NULL
           AND TRIM(COALESCE(b.bio, '')) <> ''"
    );
    $pdo->exec(
        "UPDATE dj_public_profiles
         SET application_bio_seeded = 1
         WHERE application_bio_seeded = 0"
    );

    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_password_resets (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_password_reset_token (token_hash),
        KEY idx_password_reset_account (account_id, created_at),
        KEY idx_password_reset_expiry (expires_at, used_at),
        CONSTRAINT fk_password_reset_account FOREIGN KEY (account_id) REFERENCES dj_portal_accounts(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $pdo->exec(
            "DELETE FROM dj_password_resets
             WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
                OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 1 DAY))"
        );
    } catch (Throwable $e) {
        error_log('MyLive password reset cleanup failed: ' . $e->getMessage());
    }
}

function deseo_mylive_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/mylive',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

function deseo_mylive_csrf(): string {
    deseo_mylive_session_start();
    if (empty($_SESSION['mylive_csrf'])) {
        $_SESSION['mylive_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['mylive_csrf'];
}

function deseo_mylive_verify_csrf(?string $token): bool {
    deseo_mylive_session_start();
    return is_string($token)
        && isset($_SESSION['mylive_csrf'])
        && hash_equals((string)$_SESSION['mylive_csrf'], $token);
}

function deseo_mylive_account_id(): int {
    deseo_mylive_session_start();
    return (int)($_SESSION['mylive_account_id'] ?? 0);
}

function deseo_mylive_logged_in(): bool {
    return deseo_mylive_account_id() > 0;
}

function deseo_mylive_e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function deseo_mylive_slug(string $value): string {
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');
    return $value !== '' ? substr($value, 0, 80) : 'DJ';
}

function deseo_mylive_next_episode(PDO $pdo, int $accountId): int {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(episode_no), 0) + 1 FROM dj_portal_sets WHERE account_id = ?");
    $stmt->execute([$accountId]);
    return max(1, (int)$stmt->fetchColumn());
}

function deseo_mylive_password_reset_recent(PDO $pdo, int $accountId, int $seconds = 120): bool {
    $seconds = max(30, min(3600, $seconds));
    $stmt = $pdo->prepare(
        "SELECT id
         FROM dj_password_resets
         WHERE account_id = ?
           AND used_at IS NULL
           AND expires_at > NOW()
           AND created_at >= DATE_SUB(NOW(), INTERVAL " . $seconds . " SECOND)
         LIMIT 1"
    );
    $stmt->execute([$accountId]);
    return (bool)$stmt->fetchColumn();
}

function deseo_mylive_password_reset_create(PDO $pdo, int $accountId, int $ttlSeconds = 3600): string {
    $ttlSeconds = max(300, min(86400, $ttlSeconds));
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $pdo->prepare(
        "UPDATE dj_password_resets
         SET used_at = NOW()
         WHERE account_id = ? AND used_at IS NULL"
    )->execute([$accountId]);

    $pdo->prepare(
        "INSERT INTO dj_password_resets (account_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . $ttlSeconds . " SECOND))"
    )->execute([$accountId, $tokenHash]);

    return $rawToken;
}

function deseo_mylive_password_reset_lookup(PDO $pdo, string $token, bool $forUpdate = false): ?array {
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $sql =
        "SELECT r.id AS reset_id, r.account_id, r.expires_at, r.used_at,
                a.artist_name, a.email, a.is_active, a.account_status
         FROM dj_password_resets r
         INNER JOIN dj_portal_accounts a ON a.id = r.account_id
         WHERE r.token_hash = ?
           AND r.used_at IS NULL
           AND r.expires_at > NOW()
           AND a.is_active = 1
         LIMIT 1";

    if ($forUpdate) $sql .= " FOR UPDATE";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function deseo_mylive_password_reset_consume(PDO $pdo, string $token, string $newPasswordHash): ?array {
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) $pdo->beginTransaction();

    try {
        $reset = deseo_mylive_password_reset_lookup($pdo, $token, true);
        if (!$reset) {
            if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return null;
        }

        $accountId = (int)$reset['account_id'];

        $pdo->prepare(
            "UPDATE dj_portal_accounts
             SET password_hash = ?,
                 must_change_password = 0
             WHERE id = ? AND is_active = 1"
        )->execute([$newPasswordHash, $accountId]);

        $pdo->prepare(
            "UPDATE dj_password_resets
             SET used_at = NOW()
             WHERE account_id = ? AND used_at IS NULL"
        )->execute([$accountId]);

        if ($startedTransaction) $pdo->commit();
        return $reset;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function deseo_mylive_account(PDO $pdo, int $accountId): ?array {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.booking_id, a.artist_name, a.full_name, a.email, a.day_of_week, a.start_time, a.end_time,
                a.must_change_password, a.is_active, a.account_status, a.show_audience_stats, a.public_profile_enabled, a.onboarding_email_sent_at, a.access_email_sent_at,
                a.last_login_at, a.created_at, a.updated_at,
                b.status AS application_status
         FROM dj_portal_accounts a
         LEFT JOIN dj_season_bookings b ON b.id = a.booking_id
         WHERE a.id = ? AND a.is_active = 1
         LIMIT 1"
    );
    $stmt->execute([$accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function deseo_mylive_is_guest_account(array $account): bool {
    return strtolower(trim((string)($account['application_status'] ?? ''))) === 'guest';
}

function deseo_mylive_sets(PDO $pdo, int $accountId): array {
    $stmt = $pdo->prepare(
        "SELECT id, episode_no, original_name, stored_name, file_size, mime_type, status, admin_note,
                broadcasted_at, delete_after, file_deleted_at, scheduled_show_end,
                hearthis_status, hearthis_url, hearthis_track_id, hearthis_error, hearthis_synced_at, uploaded_at
         FROM dj_portal_sets
         WHERE account_id = ?
         ORDER BY episode_no DESC"
    );
    $stmt->execute([$accountId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function deseo_mylive_set_statuses(): array {
    return ['received', 'checked', 'scheduled', 'needs_changes', 'broadcasted'];
}

function deseo_mylive_set_storage_file(string $filePath): array {
    $storageRoot = realpath(dirname(__DIR__) . '/mylive/storage');
    $relative = ltrim($filePath, '/');
    $candidate = dirname(__DIR__) . '/mylive/' . $relative;
    $realFile = realpath($candidate);

    return [
        'storage_root' => $storageRoot ?: '',
        'candidate' => $candidate,
        'real_file' => $realFile ?: '',
        'exists' => $realFile !== false && is_file($realFile),
    ];
}

function deseo_mylive_delete_set_file_now(int $setId, string $filePath): string {
    $file = deseo_mylive_set_storage_file($filePath);

    if (!$file['exists']) {
        return 'missing';
    }

    if (
        $file['storage_root'] === ''
        || !str_starts_with((string)$file['real_file'], (string)$file['storage_root'] . DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Το DJ Set file βρίσκεται εκτός του ασφαλούς MyLive storage και δεν διαγράφηκε.');
    }

    if (!@unlink((string)$file['real_file'])) {
        error_log('MyLive could not delete synced set ' . $setId . ': ' . $file['real_file']);
        throw new RuntimeException('Το συγχρονισμένο audio file δεν μπόρεσε να διαγραφεί από τον server.');
    }

    return 'deleted';
}

/**
 * The scheduler uses the real Program mapping. A slot starting today but already
 * in progress is NOT eligible for a newly scheduled upload.
 */
function deseo_mylive_next_show_end(PDO $pdo, int $accountId, DateTimeImmutable $now): ?string {
    // Season 6 DJ slots live on the MyLive account itself. The generic music-zone
    // program table is not populated with mylive_account_id in production.
    $stmt = $pdo->prepare(
        "SELECT a.day_of_week, a.start_time, a.end_time,
                b.status AS application_status
         FROM dj_portal_accounts a
         LEFT JOIN dj_season_bookings b ON b.id = a.booking_id
         WHERE a.id = ? LIMIT 1"
    );
    $stmt->execute([$accountId]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slot || strtolower((string)($slot['application_status'] ?? '')) === 'guest') {
        // Guest DJs need an explicit date/time rather than a recurring slot.
        return null;
    }

    $startTime = substr((string)($slot['start_time'] ?? ''), 0, 8);
    $endTime = substr((string)($slot['end_time'] ?? ''), 0, 8);
    if ($startTime === '') return null;
    if ($endTime === '') $endTime = '';
    // Existing strict slots sometimes end at HH:59:59. Their intended full-hour
    // end is HH+1:00, including the day rollover for 23:00 slots.
    if ($endTime !== '') {
        $base = DateTimeImmutable::createFromFormat('!H:i:s', $startTime);
        $endBase = DateTimeImmutable::createFromFormat('!H:i:s', $endTime);
        if ($base && $endBase && $endBase->format('H:i:s') === $base->modify('+1 hour -1 second')->format('H:i:s')) {
            $endTime = $base->modify('+1 hour')->format('H:i:s');
        }
    }
    $occupied = $pdo->prepare(
        "SELECT COUNT(*) FROM dj_portal_sets
         WHERE account_id = ? AND status = 'scheduled' AND scheduled_show_end = ?"
    );
    for ($attempt = 0; $attempt < 52; $attempt++) {
        $occurrence = dj_season_weekly_occurrence(
            (int)($slot['day_of_week'] ?? 0), $startTime, $endTime, $now
        );
        if (!$occurrence) return null;
        [$start, $end] = $occurrence;
        if ($start <= $now) {
            $start = $start->modify('+7 days');
            $end = $end->modify('+7 days');
        }
        if ($start > dj_season_end_at()) return null;
        $endSql = $end->format('Y-m-d H:i:s');
        $occupied->execute([$accountId, $endSql]);
        if ((int)$occupied->fetchColumn() === 0) return $endSql;
        $now = $end->modify('+1 second');
    }
    return null;
}

function deseo_mylive_update_set_status(PDO $pdo, int $setId, string $status, string $note = '', string $showEndInput = ''): array {
    if (!in_array($status, deseo_mylive_set_statuses(), true)) {
        throw new RuntimeException('Μη έγκυρο status.');
    }
    $stmt = $pdo->prepare(
        "SELECT id, account_id, status, broadcasted_at, scheduled_show_end,
                file_deleted_at, hearthis_status
         FROM dj_portal_sets WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$setId]);
    $set = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$set) throw new RuntimeException('Το DJ Set δεν βρέθηκε.');

    $previous = (string)$set['status'];
    $now = new DateTimeImmutable('now', dj_season_athens_timezone());
    $nowSql = $now->format('Y-m-d H:i:s');

    if ((string)$set['hearthis_status'] === 'uploading') {
        throw new RuntimeException('Το HearThis upload είναι σε εξέλιξη. Δεν μπορεί να αλλάξει το status.');
    }
    if ((string)$set['hearthis_status'] === 'synced' && $status !== 'broadcasted') {
        throw new RuntimeException('Το DJ Set έχει ήδη δημοσιευθεί στο HearThis. Δεν μπορεί να επιστρέψει σε προηγούμενο status.');
    }

    if ($status === 'broadcasted') {
        if ($previous !== 'broadcasted' && !empty($set['scheduled_show_end']) && $nowSql < $set['scheduled_show_end']) {
            throw new RuntimeException('Το DJ Set δεν μπορεί να γίνει BROADCASTED πριν ολοκληρωθεί το προγραμματισμένο slot.');
        }
        // Never delete audio here. The sync worker owns deletion after a persisted URL.
        $broadcastedAt = $previous === 'broadcasted' && !empty($set['broadcasted_at'])
            ? (string)$set['broadcasted_at'] : $nowSql;
        $pdo->prepare(
            "UPDATE dj_portal_sets
             SET status = 'broadcasted', admin_note = ?, broadcasted_at = ?, delete_after = NULL
             WHERE id = ?"
        )->execute([$note, $broadcastedAt, $setId]);
    } else {
        $scheduledEnd = null;
        if ($status === 'scheduled') {
            $scheduledEnd = $previous === 'scheduled' && !empty($set['scheduled_show_end'])
                ? (string)$set['scheduled_show_end']
                : deseo_mylive_next_show_end($pdo, (int)$set['account_id'], $now);
            // Admin may supply an actual end date/time for a one-off Guest DJ slot.
            if (trim($showEndInput) !== '') {
                $given = DateTimeImmutable::createFromFormat(
                    '!Y-m-d\\TH:i', trim($showEndInput), dj_season_athens_timezone()
                );
                $parseErrors = DateTimeImmutable::getLastErrors();
                if (!$given || ($parseErrors !== false && ($parseErrors['warning_count'] || $parseErrors['error_count']))
                    || $given->format('Y-m-d\\TH:i') !== trim($showEndInput)) {
                    throw new RuntimeException('Μη έγκυρη ημερομηνία λήξης μετάδοσης.');
                }
                $inputSql = $given->format('Y-m-d H:i:s');
                if (($given <= $now && $inputSql !== (string)($set['scheduled_show_end'] ?? ''))
                    || $given < dj_season_start_at()
                    || $given > dj_season_end_at()->modify('+1 day')) {
                    throw new RuntimeException('Η λήξη πρέπει να είναι μελλοντική και μέσα στη Season 6.');
                }
                $collision = $pdo->prepare(
                    "SELECT COUNT(*) FROM dj_portal_sets
                     WHERE account_id = ? AND id <> ? AND status = 'scheduled'
                       AND scheduled_show_end = ?"
                );
                $collision->execute([(int)$set['account_id'], $setId, $inputSql]);
                if ((int)$collision->fetchColumn() > 0) {
                    throw new RuntimeException('Άλλο episode του DJ έχει ήδη κρατήσει αυτό το slot.');
                }
                $scheduledEnd = $inputSql;
            }
            if ($scheduledEnd === null) {
                throw new RuntimeException('Δεν υπάρχει συνδεδεμένο εβδομαδιαίο slot. Όρισε ημερομηνία/ώρα λήξης για το Guest DJ Set.');
            }
        }
        $pdo->prepare(
            "UPDATE dj_portal_sets
             SET status = ?, admin_note = ?, broadcasted_at = NULL,
                 scheduled_show_end = ?, delete_after = NULL
             WHERE id = ?"
        )->execute([$status, $note, $scheduledEnd, $setId]);
    }
    $refresh = $pdo->prepare(
        "SELECT id, status, broadcasted_at, delete_after, file_deleted_at,
                scheduled_show_end, hearthis_status, hearthis_url, hearthis_error
         FROM dj_portal_sets WHERE id = ? LIMIT 1"
    );
    $refresh->execute([$setId]);
    return $refresh->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Safe retention only after the public HearThis URL is committed to the DB.
 * This is also called from existing page-load and legacy cron hooks.
 */
function deseo_mylive_cleanup_broadcasted_sets(PDO $pdo): array {
    $nowSql = (new DateTimeImmutable('now', dj_season_athens_timezone()))->format('Y-m-d H:i:s');
    $stmt = $pdo->query(
        "SELECT id, file_path, hearthis_url
         FROM dj_portal_sets
         WHERE status = 'broadcasted' AND hearthis_status = 'synced'
           AND hearthis_synced_at IS NOT NULL AND file_deleted_at IS NULL
         ORDER BY id ASC"
    );
    $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $deleted = 0; $missing = 0; $failed = 0;
    foreach ($sets as $set) {
        $id = (int)$set['id'];
        try {
            $url = (string)$set['hearthis_url'];
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            if (!filter_var($url, FILTER_VALIDATE_URL)
                || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || !in_array($host, ['hearthis.at', 'www.hearthis.at'], true)) {
                throw new RuntimeException('No confirmed HearThis public URL; audio retained.');
            }
            $result = deseo_mylive_delete_set_file_now($id, (string)$set['file_path']);
            $pdo->prepare(
                "UPDATE dj_portal_sets SET delete_after = NULL, file_deleted_at = ?
                 WHERE id = ? AND status = 'broadcasted'
                   AND hearthis_status = 'synced' AND hearthis_synced_at IS NOT NULL
                   AND hearthis_url = ? AND file_deleted_at IS NULL"
            )->execute([$nowSql, $id, $url]);
            if ($result === 'deleted') $deleted++;
            else $missing++;
        } catch (Throwable $error) {
            error_log('MyLive synced-file retention failed for set ' . $id . ': ' . $error->getMessage());
            $failed++;
        }
    }
    return ['checked' => count($sets), 'deleted' => $deleted,
            'already_missing' => $missing, 'failed' => $failed, 'at' => $nowSql];
}

function deseo_mylive_assets(PDO $pdo, int $accountId): array {
    $stmt = $pdo->prepare(
        "SELECT id, asset_type, title, original_name, stored_name, file_size, mime_type, created_at
         FROM dj_portal_assets
         WHERE account_id = ?
         ORDER BY
            CASE asset_type
                WHEN 'hearthis_cover' THEN 0
                WHEN 'artwork' THEN 1
                WHEN 'dj_spot' THEN 2
                WHEN 'dj_spot_30' THEN 3
                ELSE 4
            END,
            created_at DESC"
    );
    $stmt->execute([$accountId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function deseo_mylive_asset_label(string $type): string {
    return match ($type) {
        'hearthis_cover' => 'HearThis Square Cover · 02',
        'artwork' => 'Promotional Artwork',
        'dj_spot' => 'Personal DJ Imaging',
        'dj_spot_30' => "30' Imaging",
        default => 'Additional Asset'
    };
}

function deseo_mylive_day_label(?int $day): string {
    if (!$day) return '—';
    return dj_season_day_label($day);
}

function deseo_mylive_format_time(?string $time): string {
    return $time ? substr($time, 0, 5) : '—';
}

function deseo_mylive_slot(array $account): string {
    $day = deseo_mylive_day_label(isset($account['day_of_week']) ? (int)$account['day_of_week'] : null);
    $start = deseo_mylive_format_time((string)($account['start_time'] ?? ''));
    if ($day === '—' && $start === '—') return 'Slot to be announced';
    return trim($day . ' · ' . $start, " ·");
}

function deseo_mylive_create_pending_from_booking(PDO $pdo, int $bookingId): int {
    $stmt = $pdo->prepare(
        "SELECT b.id, b.artist_name, b.full_name, b.email,
                COALESCE(b.final_day_of_week, s.day_of_week) AS effective_day,
                COALESCE(b.final_start_time, s.start_time) AS effective_start,
                COALESCE(b.final_end_time, s.end_time) AS effective_end
         FROM dj_season_bookings b
         INNER JOIN dj_season_slots s ON s.id = b.slot_id
         WHERE b.id = ? AND b.season = ? AND b.status IN ('approved','guest')
         LIMIT 1"
    );
    $stmt->execute([$bookingId, DESEO_DJ_SEASON]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        throw new RuntimeException('Δεν βρέθηκε Approved ή Guest DJ για δημιουργία MyLive pending account.');
    }

    $existing = $pdo->prepare("SELECT id FROM dj_portal_accounts WHERE booking_id = ? OR LOWER(email) = LOWER(?) LIMIT 1");
    $existing->execute([$bookingId, (string)$booking['email']]);
    $existingId = (int)($existing->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $pdo->prepare(
            "UPDATE dj_portal_accounts
             SET booking_id = ?,
                 artist_name = ?,
                 full_name = ?,
                 email = ?,
                 day_of_week = ?,
                 start_time = ?,
                 end_time = ?
             WHERE id = ?"
        )->execute([
            $bookingId,
            (string)$booking['artist_name'],
            (string)$booking['full_name'],
            strtolower(trim((string)$booking['email'])),
            (int)$booking['effective_day'],
            (string)$booking['effective_start'],
            (string)$booking['effective_end'],
            $existingId
        ]);
        return $existingId;
    }

    $insert = $pdo->prepare(
        "INSERT INTO dj_portal_accounts
         (booking_id, artist_name, full_name, email, day_of_week, start_time, end_time,
          password_hash, must_change_password, is_active, account_status, show_audience_stats)
         VALUES (?, ?, ?, ?, ?, ?, ?, '', 1, 0, 'pending', 0)"
    );
    $insert->execute([
        $bookingId,
        (string)$booking['artist_name'],
        (string)$booking['full_name'],
        strtolower(trim((string)$booking['email'])),
        (int)$booking['effective_day'],
        (string)$booking['effective_start'],
        (string)$booking['effective_end']
    ]);

    return (int)$pdo->lastInsertId();
}

function deseo_mylive_profile_clean_url(string $value): string {
    $value = trim($value);
    if ($value === '') return '';

    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . ltrim($value, '/');
    }

    $url = filter_var($value, FILTER_VALIDATE_URL);
    if (!$url) return '';

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}

function deseo_mylive_public_profile_ensure(PDO $pdo, int $accountId): array {
    $source = [
        'bio' => '',
        'instagram' => '',
        'website' => '',
    ];

    $stmt = $pdo->prepare(
        "SELECT b.bio, b.instagram, b.website
         FROM dj_portal_accounts a
         LEFT JOIN dj_season_bookings b ON b.id = a.booking_id
         WHERE a.id = ?
         LIMIT 1"
    );
    $stmt->execute([$accountId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($booking) {
        $source['bio'] = trim((string)($booking['bio'] ?? ''));
        $source['instagram'] = deseo_mylive_profile_clean_url((string)($booking['instagram'] ?? ''));
        $source['website'] = deseo_mylive_profile_clean_url((string)($booking['website'] ?? ''));
    }

    $existing = $pdo->prepare("SELECT * FROM dj_public_profiles WHERE account_id = ? LIMIT 1");
    $existing->execute([$accountId]);
    $profile = $existing->fetch(PDO::FETCH_ASSOC);

    if ($profile) {
        if (empty($profile['application_bio_seeded'])) {
            $canSeedApplicationBio =
                trim((string)($profile['draft_bio'] ?? '')) === ''
                && trim((string)($profile['published_bio'] ?? '')) === ''
                && empty($profile['published_at'])
                && $source['bio'] !== '';

            if ($canSeedApplicationBio) {
                $pdo->prepare(
                    "UPDATE dj_public_profiles
                     SET draft_bio = ?, application_bio_seeded = 1
                     WHERE account_id = ?"
                )->execute([$source['bio'], $accountId]);
            } else {
                $pdo->prepare(
                    "UPDATE dj_public_profiles
                     SET application_bio_seeded = 1
                     WHERE account_id = ?"
                )->execute([$accountId]);
            }

            $existing->execute([$accountId]);
            $profile = $existing->fetch(PDO::FETCH_ASSOC);
        }

        return $profile ?: [];
    }

    $insert = $pdo->prepare(
        "INSERT INTO dj_public_profiles
         (account_id, draft_bio, draft_instagram, draft_website, published_bio, application_bio_seeded)
         VALUES (?, ?, ?, ?, '', 1)"
    );
    $insert->execute([
        $accountId,
        $source['bio'],
        $source['instagram'],
        $source['website'],
    ]);

    $existing->execute([$accountId]);
    return $existing->fetch(PDO::FETCH_ASSOC) ?: [];
}

function deseo_mylive_public_profile(PDO $pdo, int $accountId): array {
    return deseo_mylive_public_profile_ensure($pdo, $accountId);
}

function deseo_mylive_save_public_profile_draft(PDO $pdo, int $accountId, array $data): array {
    deseo_mylive_public_profile_ensure($pdo, $accountId);

    $bio = trim((string)($data['bio'] ?? ''));
    if (mb_strlen($bio) > 1600) {
        throw new RuntimeException('Το Bio μπορεί να έχει έως 1.600 χαρακτήρες.');
    }

    $instagram = deseo_mylive_profile_clean_url((string)($data['instagram'] ?? ''));
    $tiktok = deseo_mylive_profile_clean_url((string)($data['tiktok'] ?? ''));
    $soundcloud = deseo_mylive_profile_clean_url((string)($data['soundcloud'] ?? ''));
    $spotify = deseo_mylive_profile_clean_url((string)($data['spotify'] ?? ''));
    $website = deseo_mylive_profile_clean_url((string)($data['website'] ?? ''));

    $cleaned = [
        'instagram' => $instagram,
        'tiktok' => $tiktok,
        'soundcloud' => $soundcloud,
        'spotify' => $spotify,
        'website' => $website,
    ];

    foreach ($cleaned as $key => $clean) {
        $raw = trim((string)($data[$key] ?? ''));
        if ($raw !== '' && $clean === '') {
            throw new RuntimeException('Το ' . ucfirst($key) . ' link δεν είναι έγκυρο.');
        }
    }

    $stmt = $pdo->prepare(
        "UPDATE dj_public_profiles
         SET draft_bio = ?,
             draft_instagram = ?,
             draft_tiktok = ?,
             draft_soundcloud = ?,
             draft_spotify = ?,
             draft_website = ?
         WHERE account_id = ?"
    );
    $stmt->execute([$bio, $instagram, $tiktok, $soundcloud, $spotify, $website, $accountId]);

    return deseo_mylive_public_profile_ensure($pdo, $accountId);
}

function deseo_mylive_publish_public_profile(PDO $pdo, int $accountId): array {
    $profile = deseo_mylive_public_profile_ensure($pdo, $accountId);

    if (trim((string)($profile['draft_bio'] ?? '')) === '') {
        throw new RuntimeException('Συμπλήρωσε το Bio πριν δημοσιεύσεις το Public Profile.');
    }

    $stmt = $pdo->prepare(
        "UPDATE dj_public_profiles
         SET published_bio = draft_bio,
             published_instagram = draft_instagram,
             published_tiktok = draft_tiktok,
             published_soundcloud = draft_soundcloud,
             published_spotify = draft_spotify,
             published_website = draft_website,
             is_published = 1,
             published_at = NOW()
         WHERE account_id = ?"
    );
    $stmt->execute([$accountId]);

    return deseo_mylive_public_profile_ensure($pdo, $accountId);
}

function deseo_mylive_unpublish_public_profile(PDO $pdo, int $accountId): array {
    deseo_mylive_public_profile_ensure($pdo, $accountId);

    // Public visibility only. Do NOT touch program.mylive_account_id,
    // published content or the DJ account linkage.
    $stmt = $pdo->prepare(
        "UPDATE dj_public_profiles
         SET is_published = 0
         WHERE account_id = ?"
    );
    $stmt->execute([$accountId]);

    return deseo_mylive_public_profile_ensure($pdo, $accountId);
}

function deseo_mylive_public_profile_has_unpublished_changes(array $profile): bool {
    $fields = ['bio','instagram','tiktok','soundcloud','spotify','website'];
    foreach ($fields as $field) {
        if ((string)($profile['draft_' . $field] ?? '') !== (string)($profile['published_' . $field] ?? '')) {
            return true;
        }
    }
    return false;
}

function deseo_mylive_format_bytes(int $bytes): string {
    if ($bytes <= 0) return '0 MB';
    $mb = $bytes / 1048576;
    if ($mb < 1024) return number_format($mb, 1) . ' MB';
    return number_format($mb / 1024, 2) . ' GB';
}
