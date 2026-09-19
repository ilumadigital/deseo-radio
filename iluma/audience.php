<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/audience.php';
require_once __DIR__ . '/admin-ui.php';

deseo_mylive_bootstrap($pdo);
deseo_audience_bootstrap($pdo);

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Η συνεδρία έληξε. Ανανέωσε τη σελίδα και δοκίμασε ξανά.';
    } else {
        try {
            $raw = preg_replace('/[^0-9]/', '', (string)($_POST['monthly_listeners'] ?? '')) ?? '';
            $monthlyListeners = (int)$raw;

            if ($monthlyListeners < 1) {
                throw new RuntimeException('Συμπλήρωσε έγκυρο αριθμό μηνιαίων ακροατών.');
            }

            if ($monthlyListeners > 1000000000) {
                throw new RuntimeException('Ο αριθμός μηνιαίων ακροατών είναι υπερβολικά μεγάλος.');
            }

            $stmt = $pdo->prepare(
                "UPDATE deseo_audience_settings
                 SET monthly_listeners = ?
                 WHERE id = 1"
            );
            $stmt->execute([$monthlyListeners]);

            $notice = 'Η μηνιαία ακροαματικότητα ενημερώθηκε. Τα MyLive estimates υπολογίζονται πλέον με το νέο audience.';
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Η ακροαματικότητα δεν αποθηκεύτηκε.';
            error_log('Audience settings save failed: ' . $e->getMessage());
        }
    }
}

$monthlyListeners = deseo_audience_monthly_listeners($pdo);
$updatedAt = deseo_audience_updated_at($pdo);

$activeStmt = $pdo->query(
    "SELECT id, artist_name, day_of_week, start_time, end_time
     FROM dj_portal_accounts
     WHERE is_active = 1 AND account_status = 'active'
     ORDER BY day_of_week ASC, start_time ASC, artist_name ASC"
);
$activeAccounts = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

$previews = [
    ['label' => '20:00–21:00', 'note' => 'Strong', 'start' => '20:00:00', 'end' => '21:00:00', 'seed' => 201],
    ['label' => '21:00–22:00', 'note' => 'Peak', 'start' => '21:00:00', 'end' => '22:00:00', 'seed' => 211],
    ['label' => '22:00–23:00', 'note' => 'Lower', 'start' => '22:00:00', 'end' => '23:00:00', 'seed' => 221],
    ['label' => '23:00–24:00', 'note' => 'Late', 'start' => '23:00:00', 'end' => '24:00:00', 'seed' => 231],
];

admin_page_start('Audience', 'audience');
?>
<div class="page-heading">
    <div>
        <span>Deseo Radio · Reach model</span>
        <h1>Audience</h1>
        <p>Όρισε τη μηνιαία ακροαματικότητα του σταθμού. Το MyLive χρησιμοποιεί αυτό το νούμερο μαζί με ημέρα, ώρα και διάρκεια slot για να εμφανίζει εκτιμώμενο reach σε κάθε DJ.</p>
    </div>
</div>

<?php if ($notice): ?><div class="notice notice-success"><?= admin_e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

<section class="audience-layout">
    <article class="panel audience-settings-card">
        <div class="audience-card-kicker">STATION AUDIENCE</div>
        <h2>Monthly listeners</h2>
        <p>Χρησιμοποίησε το πιο πρόσφατο συνολικό monthly audience του Deseo Radio.</p>

        <form method="post" class="audience-form">
            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">

            <label>
                <span>Monthly listeners</span>
                <input
                    type="number"
                    min="1"
                    max="1000000000"
                    step="1"
                    name="monthly_listeners"
                    value="<?= $monthlyListeners > 0 ? (int)$monthlyListeners : '' ?>"
                    placeholder="12000000"
                    required
                >
            </label>

            <button class="button button-primary" type="submit">Save audience</button>
        </form>

        <div class="audience-current">
            <span>CURRENT</span>
            <strong><?= $monthlyListeners > 0 ? admin_e(deseo_audience_format($monthlyListeners)) : '—' ?></strong>
            <small><?= $updatedAt ? 'Updated ' . admin_e(date('d.m.Y · H:i', strtotime($updatedAt))) : 'Not configured yet' ?></small>
        </div>
    </article>

    <article class="panel audience-model-card">
        <div class="audience-card-kicker">TIME WEIGHTING</div>
        <h2>Evening model</h2>
        <p>Οι εκτιμήσεις είναι ανά 1-hour show. Το 20:00–22:00 είναι το ισχυρότερο window, μετά υπάρχει προοδευτική πτώση.</p>

        <div class="audience-preview-grid">
            <?php foreach ($previews as $preview): ?>
                <?php
                $previewReach = deseo_audience_preview(
                    $monthlyListeners,
                    5,
                    $preview['start'],
                    $preview['end'],
                    $preview['seed']
                );
                ?>
                <div class="audience-preview">
                    <span><?= admin_e($preview['note']) ?></span>
                    <strong><?= admin_e($preview['label']) ?></strong>
                    <b><?= $monthlyListeners > 0 ? '~' . admin_e(deseo_audience_format($previewReach)) : '—' ?></b>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="audience-method-note">
            Το estimate δεν αλλάζει σε κάθε refresh. Χρησιμοποιεί σταθερή μηνιαία διακύμανση ±6% ανά DJ, ώστε το αποτέλεσμα να είναι φυσικό αλλά συνεπές μέσα στον ίδιο μήνα.
        </div>
    </article>
</section>

<section class="panel audience-djs-panel">
    <div class="mylive-panel-head">
        <div>
            <span>MYLIVE PREVIEW</span>
            <h2>DJ estimated reach</h2>
            <p>Έλεγχος του αριθμού που θα βλέπει ο κάθε ενεργός DJ στο MyLive.</p>
        </div>
        <strong><?= count($activeAccounts) ?></strong>
    </div>

    <?php if (!$activeAccounts): ?>
        <div class="empty-admin">Δεν υπάρχουν ακόμη ενεργά MyLive accounts.</div>
    <?php else: ?>
        <div class="audience-dj-list">
            <?php foreach ($activeAccounts as $account): ?>
                <?php $estimate = deseo_audience_estimated_reach($pdo, $account); ?>
                <div class="audience-dj-row">
                    <div>
                        <span><?= admin_e(deseo_mylive_day_label((int)$account['day_of_week'])) ?> · <?= admin_e(deseo_mylive_format_time((string)$account['start_time'])) ?>–<?= admin_e(deseo_mylive_format_time((string)$account['end_time'])) ?></span>
                        <strong><?= admin_e($account['artist_name']) ?></strong>
                    </div>
                    <b><?= $monthlyListeners > 0 ? '~' . admin_e(deseo_audience_format($estimate)) : '—' ?></b>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php admin_page_end(); ?>
