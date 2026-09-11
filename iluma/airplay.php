<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-ui.php';

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Η συνεδρία έληξε. Ανανεώστε τη σελίδα.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id) {
                $stmt = $pdo->prepare("DELETE FROM airplay WHERE id = ?");
                $stmt->execute([$id]);
                $success = 'Το track αφαιρέθηκε.';
            }
        }

        if ($action === 'save') {
            $url = trim((string) ($_POST['spotify_url'] ?? ''));
            $position = filter_var($_POST['position'] ?? null, FILTER_VALIDATE_INT);
            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');

            if (!$position || $position < 1 || $position > 10) {
                $error = 'Επιλέξτε έγκυρη θέση 1–10.';
            } elseif (!in_array($host, ['open.spotify.com', 'www.open.spotify.com'], true) || strpos($path, '/track/') !== 0) {
                $error = 'Χρησιμοποιήστε έγκυρο Spotify track link.';
            } else {
                $endpoint = 'https://open.spotify.com/oembed?url=' . rawurlencode($url);
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_USERAGENT => 'DeseoRadioCMS/2.0',
                ]);
                $json = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $data = is_string($json) ? json_decode($json, true) : null;
                if ($httpCode !== 200 || !is_array($data) || empty($data['title'])) {
                    $error = 'Το Spotify δεν επέστρεψε στοιχεία για αυτό το track. Δοκιμάστε ξανά.';
                } else {
                    $trackName = mb_substr((string) $data['title'], 0, 255);
                    $artwork = (string) ($data['thumbnail_url'] ?? '');
                    $artist = 'Deseo Radio Selection';

                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("DELETE FROM airplay WHERE position = ?")->execute([$position]);
                        $stmt = $pdo->prepare(
                            "INSERT INTO airplay (spotify_url, track_name, artist_name, artwork_url, position)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        $stmt->execute([$url, $trackName, $artist, $artwork, $position]);
                        $pdo->commit();
                        $success = 'Η θέση #' . $position . ' ενημερώθηκε.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('Airplay save failed: ' . $e->getMessage());
                        $error = 'Δεν ήταν δυνατή η αποθήκευση. Δοκιμάστε ξανά.';
                    }
                }
            }
        }
    }
}

$tracks = $pdo->query("SELECT * FROM airplay ORDER BY position ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

admin_page_start('Airplay Top 10', 'airplay');
?>
<div class="page-heading">
    <div><span>Weekly rotation</span><h1>Airplay Top 10</h1><p>Βάλε ένα Spotify track σε συγκεκριμένη θέση. Αν η θέση είναι ήδη γεμάτη, αντικαθίσταται με ασφάλεια.</p></div>
</div>

<?php if ($success): ?><div class="notice notice-success"><?= admin_e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

<section class="panel">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <div class="form-grid">
            <div class="field">
                <label for="spotify_url">Spotify track URL</label>
                <input id="spotify_url" type="url" name="spotify_url" placeholder="https://open.spotify.com/track/..." required>
            </div>
            <div class="field">
                <label for="position">Chart position</label>
                <select id="position" name="position" required>
                    <?php for ($i=1; $i<=10; $i++): ?><option value="<?= $i ?>">#<?= $i ?></option><?php endfor; ?>
                </select>
            </div>
        </div>
        <div class="form-actions"><button class="button button-primary" type="submit">Save track</button></div>
    </form>
</section>

<?php if ($tracks): ?>
<div class="cards-grid">
<?php foreach ($tracks as $track): ?>
    <article class="media-card">
        <div class="media-card-art">
            <img src="<?= admin_e($track['artwork_url'] ?: '/assets/img/favicon.png') ?>" alt="">
            <span class="rank-badge"><?= (int)$track['position'] ?></span>
        </div>
        <div class="media-card-body">
            <h3><?= admin_e($track['track_name']) ?></h3>
            <p>Spotify linked · Position #<?= (int)$track['position'] ?></p>
            <div class="media-card-actions">
                <form method="post" class="inline-form" onsubmit="return confirm('Να διαγραφεί αυτό το track;')">
                    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$track['id'] ?>">
                    <button class="danger-link" type="submit">Delete</button>
                </form>
            </div>
        </div>
    </article>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty-admin">Δεν υπάρχουν tracks ακόμη. Πρόσθεσε το πρώτο Spotify link.</div>
<?php endif; ?>
<?php admin_page_end(); ?>