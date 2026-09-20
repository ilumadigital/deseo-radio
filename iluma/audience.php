<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
admin_require_access('audience');

require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/audience.php';
require_once __DIR__ . '/../includes/audience-report.php';
require_once __DIR__ . '/admin-ui.php';

deseo_mylive_bootstrap($pdo);
deseo_audience_bootstrap($pdo);

try {
    deseo_audience_maybe_send_monthly_report($pdo);
} catch (Throwable $reportError) {
    error_log('Audience monthly report fallback failed: ' . $reportError->getMessage());
}

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Η συνεδρία έληξε. Ανανέωσε τη σελίδα και δοκίμασε ξανά.';
    } else {
        $action = (string)($_POST['action'] ?? 'save_audience');

        try {
            if ($action === 'send_test_report') {
                $testResult = deseo_audience_send_test_report($pdo);
                $notice = 'Το test monthly report στάλθηκε αποκλειστικά στο greg@iluma.gr για '
                    . deseo_audience_month_label((string)($testResult['month'] ?? deseo_audience_current_month_key()))
                    . '.';
            } elseif ($action === 'toggle_all_stats') {
                $visible = (int)($_POST['visible'] ?? 0) === 1 ? 1 : 0;

                $stmt = $pdo->prepare(
                    "UPDATE dj_portal_accounts
                     SET show_audience_stats = ?
                     WHERE is_active = 1 AND account_status = 'active'"
                );
                $stmt->execute([$visible]);

                $notice = $visible
                    ? 'Τα audience statistics είναι πλέον ορατά σε όλους τους ενεργούς DJs.'
                    : 'Τα audience statistics κρύφτηκαν από όλους τους ενεργούς DJs.';
            } elseif ($action === 'toggle_dj_stats') {
                $accountId = (int)($_POST['account_id'] ?? 0);
                $visible = (int)($_POST['visible'] ?? 0) === 1 ? 1 : 0;

                if ($accountId < 1) {
                    throw new RuntimeException('Μη έγκυρο MyLive account.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE dj_portal_accounts
                     SET show_audience_stats = ?
                     WHERE id = ? AND is_active = 1 AND account_status = 'active'"
                );
                $stmt->execute([$visible, $accountId]);

                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException('Το MyLive account δεν βρέθηκε ή δεν είναι ενεργό.');
                }

                $notice = $visible
                    ? 'Τα audience statistics είναι πλέον ορατά στο MyLive του DJ.'
                    : 'Τα audience statistics κρύφτηκαν από το MyLive του DJ.';
            } else {
                $raw = preg_replace('/[^0-9]/', '', (string)($_POST['monthly_listeners'] ?? '')) ?? '';
                $monthlyListeners = (int)$raw;

                if ($monthlyListeners < 1) {
                    throw new RuntimeException('Συμπλήρωσε έγκυρο αριθμό μηνιαίων ακροατών.');
                }

                if ($monthlyListeners > 1000000000) {
                    throw new RuntimeException('Ο αριθμός μηνιαίων ακροατών είναι υπερβολικά μεγάλος.');
                }

                $currentMonthKey = deseo_audience_current_month_key();
                deseo_audience_save_month($pdo, $currentMonthKey, $monthlyListeners);

                $notice = 'Η ακροαματικότητα για ' . deseo_audience_month_label($currentMonthKey) . ' ενημερώθηκε. Τα MyLive estimates χρησιμοποιούν πλέον αυτό το audience.';
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Η αλλαγή δεν αποθηκεύτηκε.';
            error_log('Audience admin action failed: ' . $e->getMessage());
        }
    }
}

$currentMonthKey = deseo_audience_current_month_key();
$currentMonthLabel = deseo_audience_month_label($currentMonthKey);
$storedMonth = deseo_audience_stored_month($pdo);
$monthlyListeners = deseo_audience_monthly_listeners($pdo);
$updatedAt = deseo_audience_updated_at($pdo);
$displayAudience = deseo_audience_latest_record($pdo);
$displayMonthKey = $displayAudience ? (string)$displayAudience['month_key'] : '';
$displayMonthLabel = $displayMonthKey !== '' ? deseo_audience_month_label($displayMonthKey) : '';
$displayListeners = $displayAudience ? (int)$displayAudience['monthly_listeners'] : 0;
$previewListeners = $monthlyListeners > 0 ? $monthlyListeners : $displayListeners;

$activeStmt = $pdo->query(
    "SELECT id, artist_name, day_of_week, start_time, end_time, show_audience_stats
     FROM dj_portal_accounts
     WHERE is_active = 1 AND account_status = 'active'
     ORDER BY day_of_week ASC, start_time ASC, artist_name ASC"
);
$activeAccounts = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

$previews = [
    ['label' => '15:00–18:00', 'note' => 'Main peak', 'start' => '15:00:00', 'end' => '18:00:00', 'seed' => 151],
    ['label' => '18:00–20:00', 'note' => 'High', 'start' => '18:00:00', 'end' => '20:00:00', 'seed' => 181],
    ['label' => '20:00–22:00', 'note' => 'Peak', 'start' => '20:00:00', 'end' => '22:00:00', 'seed' => 201],
    ['label' => '22:00–23:00', 'note' => 'Lower', 'start' => '22:00:00', 'end' => '23:00:00', 'seed' => 221],
    ['label' => '23:00–24:00', 'note' => 'Late', 'start' => '23:00:00', 'end' => '24:00:00', 'seed' => 231],
];

admin_page_start('Audience', 'audience');
?>
<div class="page-heading audience-page-heading">
    <div>
        <span>Deseo Radio · Reach model</span>
        <h1>Audience · <?= admin_e($currentMonthLabel) ?></h1>
        <p>Όρισε την ακροαματικότητα του τρέχοντος μήνα. Το MyLive χρησιμοποιεί το audience του <?= admin_e($currentMonthLabel) ?> μαζί με ημέρα, ώρα και διάρκεια slot για να εμφανίζει εκτιμώμενο reach σε κάθε DJ.</p>
    </div>

    <form method="post" class="audience-test-report-form" data-deseo-confirm="Να σταλεί test Monthly Audience Report αποκλειστικά στο greg@iluma.gr;" data-deseo-confirm-title="Test audience report" data-deseo-confirm-label="Αποστολή">
        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="send_test_report">
        <button class="button button-secondary" type="submit">Send test report</button>
        <small>greg@iluma.gr only</small>
    </form>
</div>

<?php if ($notice): ?><div class="notice notice-success"><?= admin_e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

<section class="audience-layout">
    <article class="panel audience-settings-card">
        <div class="audience-card-kicker">CURRENT MONTH · <?= admin_e(strtoupper($currentMonthLabel)) ?></div>
        <h2>Monthly listeners</h2>
        <p>Καταχώρησε το συνολικό audience του Deseo Radio για τον <?= admin_e($currentMonthLabel) ?>.</p>

        <form method="post" class="audience-form">
            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="save_audience">

            <label>
                <span>Monthly listeners · <?= admin_e($currentMonthLabel) ?></span>
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
            <span><?= admin_e(strtoupper($currentMonthLabel)) ?></span>
            <strong><?= $monthlyListeners > 0 ? admin_e(deseo_audience_format($monthlyListeners)) : 'AWAITING DATA' ?></strong>
            <small>
                <?php if ($monthlyListeners > 0 && $updatedAt): ?>
                    Updated <?= admin_e(date('d.m.Y · H:i', strtotime($updatedAt))) ?>
                <?php elseif ($displayAudience): ?>
                    Δεν έχει καταχωρηθεί ακόμη audience για <?= admin_e($currentMonthLabel) ?>. Το MyLive συνεχίζει να εμφανίζει <?= admin_e($displayMonthLabel) ?> · <?= admin_e(deseo_audience_format($displayListeners)) ?> listeners.
                <?php else: ?>
                    Δεν έχει καταχωρηθεί ακόμη audience για <?= admin_e($currentMonthLabel) ?>.
                <?php endif; ?>
            </small>
        </div>
    </article>

    <article class="panel audience-model-card">
        <div class="audience-card-kicker">TIME WEIGHTING</div>
        <h2>Evening model</h2>
        <p>Η βασική καμπύλη είναι: 15:00–18:00 MAIN PEAK, 18:00–20:00 λίγο υψηλότερα, 20:00–22:00 το ισχυρότερο PEAK, και μετά προοδευτική πτώση στις 22:00–23:00 και 23:00–24:00.</p>

        <div class="audience-preview-grid">
            <?php foreach ($previews as $preview): ?>
                <?php
                $previewReach = deseo_audience_band_baseline(
                    $previewListeners,
                    5,
                    $preview['start'],
                    $preview['end']
                );
                ?>
                <div class="audience-preview">
                    <span><?= admin_e($preview['note']) ?></span>
                    <strong><?= admin_e($preview['label']) ?></strong>
                    <b><?= $previewListeners > 0 ? '~' . admin_e(deseo_audience_format($previewReach)) : '—' ?></b>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="audience-method-note">
            Οι παραπάνω ζώνες εμφανίζονται ως 1-hour equivalent για σωστή σύγκριση μεταξύ διαφορετικών time bands. Στο πραγματικό MyLive estimate εφαρμόζεται επιπλέον σταθερή μηνιαία διακύμανση ±6% ανά DJ, ώστε το αποτέλεσμα να παραμένει φυσικό αλλά συνεπές μέσα στον ίδιο μήνα.
        </div>
    </article>
</section>

<section class="panel audience-djs-panel">
    <div class="mylive-panel-head audience-panel-head">
        <div>
            <span>MYLIVE PREVIEW</span>
            <h2>DJ estimated reach</h2>
            <p>
                <?php if ($displayAudience): ?>
                    Το MyLive εμφανίζει αυτή τη στιγμή τα τελευταία ολοκληρωμένα stats: <?= admin_e($displayMonthLabel) ?>.
                <?php else: ?>
                    Δεν υπάρχουν ακόμη ολοκληρωμένα audience stats για προβολή στο MyLive.
                <?php endif; ?>
            </p>
        </div>

        <div class="audience-global-actions">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle_all_stats">
                <input type="hidden" name="visible" value="1">
                <button class="button button-primary" type="submit">Show stats for all</button>
            </form>

            <form method="post" data-deseo-confirm="Να κρυφτούν τα audience statistics από όλους τους ενεργούς DJs;" data-deseo-confirm-title="Απόκρυψη statistics" data-deseo-confirm-label="Απόκρυψη" data-deseo-confirm-danger>
                <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle_all_stats">
                <input type="hidden" name="visible" value="0">
                <button class="button button-secondary" type="submit">Hide stats for all</button>
            </form>
        </div>
    </div>

    <?php if (!$activeAccounts): ?>
        <div class="empty-admin">Δεν υπάρχουν ακόμη ενεργά MyLive accounts.</div>
    <?php else: ?>
        <div class="audience-dj-list">
            <?php foreach ($activeAccounts as $account): ?>
                <?php
                $estimate = ($displayAudience && $displayMonthKey !== '')
                    ? deseo_audience_estimated_reach_for_month($pdo, $account, $displayMonthKey, $displayListeners)
                    : 0;
                ?>
                <div class="audience-dj-row">
                    <div class="audience-dj-copy">
                        <span><?= admin_e(deseo_mylive_day_label((int)$account['day_of_week'])) ?> · <?= admin_e(deseo_mylive_format_time((string)$account['start_time'])) ?>–<?= admin_e(deseo_mylive_format_time((string)$account['end_time'])) ?></span>
                        <strong><?= admin_e($account['artist_name']) ?></strong>
                    </div>

                    <div class="audience-dj-value">
                        <span class="audience-visibility <?= !empty($account['show_audience_stats']) ? 'is-visible' : 'is-hidden' ?>">
                            <?= !empty($account['show_audience_stats']) ? 'VISIBLE' : 'HIDDEN' ?>
                        </span>
                        <b><?= $displayAudience ? '~' . admin_e(deseo_audience_format($estimate)) : '—' ?></b>
                    </div>

                    <form method="post" class="audience-visibility-form">
                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                        <input type="hidden" name="action" value="toggle_dj_stats">
                        <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                        <input type="hidden" name="visible" value="<?= !empty($account['show_audience_stats']) ? '0' : '1' ?>">
                        <button class="button <?= !empty($account['show_audience_stats']) ? 'button-secondary' : 'button-primary' ?>" type="submit">
                            <?= !empty($account['show_audience_stats']) ? 'Hide stats' : 'Show stats' ?>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php admin_page_end(); ?>
