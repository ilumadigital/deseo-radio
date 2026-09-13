<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-ui.php';

$success = null;
$error = null;

function playlist_local_file(?string $url): ?string {
    if (!$url || strpos($url, '/iluma/uploads/playlist-') !== 0) return null;
    return __DIR__ . '/uploads/' . basename($url);
}

function playlist_delete_local_if_unused(PDO $pdo, ?string $url): void {
    $file = playlist_local_file($url);
    if (!$file) return;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM playlists WHERE artwork_url = ?");
    $stmt->execute([$url]);
    if ((int)$stmt->fetchColumn() === 0 && is_file($file)) {
        @unlink($file);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Η συνεδρία έληξε. Ανανεώστε τη σελίδα.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id) {
                $stmt = $pdo->prepare("SELECT artwork_url FROM playlists WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                $pdo->prepare("DELETE FROM playlists WHERE id = ?")->execute([$id]);
                if ($row) playlist_delete_local_if_unused($pdo, (string)($row['artwork_url'] ?? ''));

                $success = 'Η playlist διαγράφηκε.';
            }
        }

        if ($action === 'save') {
            $url = trim((string)($_POST['spotify_url'] ?? ''));
            $customTitle = trim((string)($_POST['title'] ?? ''));
            $position = filter_var($_POST['position'] ?? null, FILTER_VALIDATE_INT);

            $parts = parse_url($url);
            $host = strtolower((string)($parts['host'] ?? ''));
            $path = (string)($parts['path'] ?? '');

            if (!$position || $position < 1 || $position > 24) {
                $error = 'Επιλέξτε σειρά εμφάνισης 1–24.';
            } elseif (!in_array($host, ['open.spotify.com', 'www.open.spotify.com'], true) || strpos($path, '/playlist/') !== 0) {
                $error = 'Χρησιμοποιήστε έγκυρο Spotify playlist link.';
            } else {
                $endpoint = 'https://open.spotify.com/oembed?url=' . rawurlencode($url);
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_USERAGENT => 'DeseoRadioCMS/3.0',
                ]);
                $json = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $data = is_string($json) ? json_decode($json, true) : null;
                if ($httpCode !== 200 || !is_array($data)) {
                    $error = 'Το Spotify δεν επέστρεψε στοιχεία για αυτή την playlist.';
                } else {
                    $title = $customTitle !== '' ? mb_substr($customTitle, 0, 255) : mb_substr((string)($data['title'] ?? 'Deseo Playlist'), 0, 255);
                    $artwork = (string)($data['thumbnail_url'] ?? '');
                    $uploadedFile = null;

                    if (isset($_FILES['cover']) && $_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE) {
                        if ($_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
                            $error = 'Το cover δεν ανέβηκε σωστά.';
                        } elseif ((int)$_FILES['cover']['size'] > 5 * 1024 * 1024) {
                            $error = 'Το cover πρέπει να είναι μικρότερο από 5MB.';
                        } else {
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            $mime = $finfo->file($_FILES['cover']['tmp_name']);
                            $allowed = [
                                'image/jpeg' => 'jpg',
                                'image/png' => 'png',
                                'image/webp' => 'webp',
                            ];

                            if (!isset($allowed[$mime]) || @getimagesize($_FILES['cover']['tmp_name']) === false) {
                                $error = 'Το cover πρέπει να είναι JPG, PNG ή WEBP.';
                            } else {
                                $uploadDir = __DIR__ . '/uploads';
                                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                                    $error = 'Δεν ήταν δυνατή η δημιουργία του uploads folder.';
                                } else {
                                    $filename = 'playlist-' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
                                    $target = $uploadDir . '/' . $filename;

                                    if (!move_uploaded_file($_FILES['cover']['tmp_name'], $target)) {
                                        $error = 'Δεν ήταν δυνατή η αποθήκευση του cover.';
                                    } else {
                                        $uploadedFile = $target;
                                        $artwork = '/iluma/uploads/' . $filename;
                                    }
                                }
                            }
                        }
                    }

                    if ($error === null) {
                        $pdo->beginTransaction();
                        try {
                            $oldStmt = $pdo->prepare("SELECT artwork_url FROM playlists WHERE position = ?");
                            $oldStmt->execute([$position]);
                            $oldRows = $oldStmt->fetchAll(PDO::FETCH_COLUMN);

                            $pdo->prepare("DELETE FROM playlists WHERE position = ?")->execute([$position]);

                            $stmt = $pdo->prepare(
                                "INSERT INTO playlists (spotify_url, title, artwork_url, position) VALUES (?, ?, ?, ?)"
                            );
                            $stmt->execute([$url, $title, $artwork, $position]);
                            $pdo->commit();

                            foreach ($oldRows as $oldArtwork) {
                                playlist_delete_local_if_unused($pdo, is_string($oldArtwork) ? $oldArtwork : '');
                            }

                            $success = 'Η playlist αποθηκεύτηκε στη θέση #' . $position . '.';
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            if ($uploadedFile && is_file($uploadedFile)) @unlink($uploadedFile);
                            error_log('Playlist save failed: ' . $e->getMessage());
                            $error = 'Δεν ήταν δυνατή η αποθήκευση. Δοκιμάστε ξανά.';
                        }
                    }
                }
            }
        }
    }
}

$playlists = $pdo->query("SELECT * FROM playlists ORDER BY position ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

admin_page_start('Playlists', 'playlists');
?>
<div class="page-heading">
    <div>
        <span>Spotify curation</span>
        <h1>Playlists</h1>
        <p>Πρόσθεσε playlists που θα εμφανίζονται στο public site. Το cover μπορεί να έρθει αυτόματα από Spotify ή να ανέβει χειροκίνητα.</p>
    </div>
</div>

<?php if ($success): ?><div class="notice notice-success"><?= admin_e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

<section class="panel">
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="save">

        <div class="form-grid">
            <div class="field full">
                <label for="spotify_url">Spotify playlist URL</label>
                <input id="spotify_url" type="url" name="spotify_url" placeholder="https://open.spotify.com/playlist/..." required>
            </div>

            <div class="field">
                <label for="title">Custom title · optional</label>
                <input id="title" type="text" name="title" maxlength="255" placeholder="Deseo Sunset Selection">
            </div>

            <div class="field">
                <label for="position">Display order</label>
                <select id="position" name="position" required>
                    <?php for ($i = 1; $i <= 24; $i++): ?><option value="<?= $i ?>">#<?= $i ?></option><?php endfor; ?>
                </select>
            </div>

            <div class="field full">
                <label for="cover">Custom cover · optional · JPG/PNG/WEBP · max 5MB</label>
                <input class="file-input" id="cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp">
            </div>
        </div>

        <div class="form-actions"><button class="button button-primary" type="submit">Save playlist</button></div>
    </form>
</section>

<?php if ($playlists): ?>
<div class="cards-grid">
<?php foreach ($playlists as $playlist): ?>
    <article class="media-card">
        <div class="media-card-art">
            <img src="<?= admin_e($playlist['artwork_url'] ?: '/assets/img/favicon.png') ?>" alt="">
            <span class="rank-badge"><?= (int)$playlist['position'] ?></span>
        </div>
        <div class="media-card-body">
            <h3><?= admin_e($playlist['title']) ?></h3>
            <p>Spotify playlist · Position #<?= (int)$playlist['position'] ?></p>
            <div class="media-card-actions">
                <form method="post" class="inline-form" onsubmit="return confirm('Να διαγραφεί αυτή η playlist;')">
                    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$playlist['id'] ?>">
                    <button class="danger-link" type="submit">Delete</button>
                </form>
            </div>
        </div>
    </article>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty-admin">Δεν υπάρχουν playlists ακόμη. Πρόσθεσε το πρώτο Spotify playlist link.</div>
<?php endif; ?>
<?php admin_page_end(); ?>
