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
        uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_portal_episode (account_id, episode_no),
        KEY idx_portal_sets_account (account_id, uploaded_at),
        CONSTRAINT fk_portal_sets_account FOREIGN KEY (account_id) REFERENCES dj_portal_accounts(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $setColumns = [
        'broadcasted_at' => "DATETIME NULL AFTER admin_note",
        'delete_after' => "DATETIME NULL AFTER broadcasted_at",
        'file_deleted_at' => "DATETIME NULL AFTER delete_after"
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
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_public_profile_account FOREIGN KEY (account_id) REFERENCES dj_portal_accounts(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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

function deseo_mylive_account(PDO $pdo, int $accountId): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, booking_id, artist_name, full_name, email, day_of_week, start_time, end_time,
                must_change_password, is_active, account_status, show_audience_stats, public_profile_enabled, onboarding_email_sent_at, access_email_sent_at,
                last_login_at, created_at, updated_at
         FROM dj_portal_accounts
         WHERE id = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->execute([$accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function deseo_mylive_sets(PDO $pdo, int $accountId): array {
    $stmt = $pdo->prepare(
        "SELECT id, episode_no, original_name, stored_name, file_size, mime_type, status, admin_note,
                broadcasted_at, delete_after, file_deleted_at, uploaded_at
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
        error_log('MyLive could not immediately delete BROADCASTED set ' . $setId . ': ' . $file['real_file']);
        throw new RuntimeException('Το status δεν άλλαξε σε BROADCASTED επειδή το audio file δεν μπόρεσε να διαγραφεί από τον server.');
    }

    return 'deleted';
}

function deseo_mylive_update_set_status(PDO $pdo, int $setId, string $status, string $note = ''): array {
    if (!in_array($status, deseo_mylive_set_statuses(), true)) {
        throw new RuntimeException('Μη έγκυρο status.');
    }

    $stmt = $pdo->prepare(
        "SELECT id, status, file_path, broadcasted_at, delete_after, file_deleted_at
         FROM dj_portal_sets
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$setId]);
    $set = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$set) {
        throw new RuntimeException('Το DJ Set δεν βρέθηκε.');
    }

    $previous = (string)$set['status'];
    $timezone = new DateTimeZone('Europe/Athens');
    $now = new DateTimeImmutable('now', $timezone);
    $nowSql = $now->format('Y-m-d H:i:s');

    if ($status === 'broadcasted') {
        if (empty($set['file_deleted_at'])) {
            deseo_mylive_delete_set_file_now($setId, (string)$set['file_path']);
        }

        $broadcastedAt = ($previous === 'broadcasted' && !empty($set['broadcasted_at']))
            ? (string)$set['broadcasted_at']
            : $nowSql;

        $pdo->prepare(
            "UPDATE dj_portal_sets
             SET status = 'broadcasted',
                 admin_note = ?,
                 broadcasted_at = ?,
                 delete_after = NULL,
                 file_deleted_at = COALESCE(file_deleted_at, ?)
             WHERE id = ?"
        )->execute([
            $note,
            $broadcastedAt,
            $nowSql,
            $setId
        ]);
    } else {
        if (empty($set['file_deleted_at'])) {
            $pdo->prepare(
                "UPDATE dj_portal_sets
                 SET status = ?,
                     admin_note = ?,
                     broadcasted_at = NULL,
                     delete_after = NULL
                 WHERE id = ?"
            )->execute([$status, $note, $setId]);
        } else {
            $pdo->prepare(
                "UPDATE dj_portal_sets
                 SET status = ?,
                     admin_note = ?,
                     delete_after = NULL
                 WHERE id = ?"
            )->execute([$status, $note, $setId]);
        }
    }

    $refresh = $pdo->prepare(
        "SELECT id, status, broadcasted_at, delete_after, file_deleted_at
         FROM dj_portal_sets
         WHERE id = ?
         LIMIT 1"
    );
    $refresh->execute([$setId]);
    return $refresh->fetch(PDO::FETCH_ASSOC) ?: [];
}

function deseo_mylive_cleanup_broadcasted_sets(PDO $pdo): array {
    // Safety net for legacy BROADCASTED rows created before immediate deletion
    // was introduced. Any BROADCASTED set that still has a file is removed now.
    $timezone = new DateTimeZone('Europe/Athens');
    $now = new DateTimeImmutable('now', $timezone);
    $nowSql = $now->format('Y-m-d H:i:s');

    $stmt = $pdo->query(
        "SELECT id, file_path
         FROM dj_portal_sets
         WHERE status = 'broadcasted'
           AND file_deleted_at IS NULL
         ORDER BY id ASC"
    );
    $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deleted = 0;
    $missing = 0;
    $failed = 0;

    foreach ($sets as $set) {
        $setId = (int)$set['id'];

        try {
            $result = deseo_mylive_delete_set_file_now($setId, (string)$set['file_path']);

            $pdo->prepare(
                "UPDATE dj_portal_sets
                 SET delete_after = NULL,
                     file_deleted_at = ?
                 WHERE id = ? AND file_deleted_at IS NULL"
            )->execute([$nowSql, $setId]);

            if ($result === 'deleted') $deleted++;
            else $missing++;
        } catch (Throwable $cleanupError) {
            error_log('MyLive immediate BROADCASTED cleanup failed for set ' . $setId . ': ' . $cleanupError->getMessage());
            $failed++;
        }
    }

    return [
        'checked' => count($sets),
        'deleted' => $deleted,
        'already_missing' => $missing,
        'failed' => $failed,
        'at' => $nowSql,
    ];
}

function deseo_mylive_assets(PDO $pdo, int $accountId): array {
    $stmt = $pdo->prepare(
        "SELECT id, asset_type, title, original_name, stored_name, file_size, mime_type, created_at
         FROM dj_portal_assets
         WHERE account_id = ?
         ORDER BY
            CASE asset_type
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
    $existing = $pdo->prepare("SELECT * FROM dj_public_profiles WHERE account_id = ? LIMIT 1");
    $existing->execute([$accountId]);
    $profile = $existing->fetch(PDO::FETCH_ASSOC);
    if ($profile) return $profile;

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

    $insert = $pdo->prepare(
        "INSERT INTO dj_public_profiles
         (account_id, draft_bio, draft_instagram, draft_website, published_bio)
         VALUES (?, ?, ?, ?, '')"
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
        throw new RuntimeException('Το About μπορεί να έχει έως 1.600 χαρακτήρες.');
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
        throw new RuntimeException('Συμπλήρωσε το About πριν δημοσιεύσεις το Public Profile.');
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
