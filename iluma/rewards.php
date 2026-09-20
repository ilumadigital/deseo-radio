<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/dj-rewards.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/admin-ui.php';

admin_require_access('rewards');
deseo_mylive_bootstrap($pdo);
deseo_rewards_bootstrap($pdo);

$notice = null;
$error = null;

function rewards_admin_decimal(string $value, string $label): float {
    $normalized = str_replace([' ', ','], ['', '.'], trim($value));
    if ($normalized === '') return 0.0;
    if (!is_numeric($normalized)) {
        throw new RuntimeException('Μη έγκυρη τιμή στο πεδίο ' . $label . '.');
    }
    $number = round((float)$normalized, 2);
    if ($number < 0) {
        throw new RuntimeException('Το πεδίο ' . $label . ' δεν μπορεί να είναι αρνητικό.');
    }
    return $number;
}

function rewards_admin_date(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('Η ημερομηνία referral δεν είναι έγκυρη.');
    }
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Η συνεδρία έληξε. Ανανέωσε τη σελίδα και δοκίμασε ξανά.');
        }

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_reward') {
            $rewardId = (int)($_POST['reward_id'] ?? 0);
            $accountId = (int)($_POST['account_id'] ?? 0);
            $businessName = trim((string)($_POST['business_name'] ?? ''));
            $contactName = trim((string)($_POST['contact_name'] ?? ''));
            $contactEmail = strtolower(trim((string)($_POST['contact_email'] ?? '')));
            $contactPhone = trim((string)($_POST['contact_phone'] ?? ''));
            $campaignValue = rewards_admin_decimal((string)($_POST['campaign_value'] ?? ''), 'Campaign value');
            $rewardPercent = rewards_admin_decimal((string)($_POST['reward_percent'] ?? '20'), 'Reward %');
            $rewardAmountRaw = trim((string)($_POST['reward_amount'] ?? ''));
            $status = (string)($_POST['status'] ?? 'new');
            $djNote = trim((string)($_POST['dj_note'] ?? ''));
            $internalNote = trim((string)($_POST['internal_note'] ?? ''));
            $referredAt = rewards_admin_date($_POST['referred_at'] ?? null);

            if ($accountId < 1) throw new RuntimeException('Επίλεξε DJ.');
            if ($businessName === '') throw new RuntimeException('Συμπλήρωσε την επιχείρηση.');
            if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Το contact email δεν είναι έγκυρο.');
            }
            if ($rewardPercent > 100) throw new RuntimeException('Το Reward % δεν μπορεί να ξεπερνά το 100%.');
            if (!array_key_exists($status, deseo_rewards_statuses())) {
                throw new RuntimeException('Μη έγκυρο Reward status.');
            }

            $accountStmt = $pdo->prepare(
                "SELECT id, artist_name, email
                 FROM dj_portal_accounts
                 WHERE id = ?
                 LIMIT 1"
            );
            $accountStmt->execute([$accountId]);
            $rewardAccount = $accountStmt->fetch(PDO::FETCH_ASSOC);
            if (!$rewardAccount) throw new RuntimeException('Το MyLive account του DJ δεν βρέθηκε.');

            $rewardAmount = $rewardAmountRaw === ''
                ? round($campaignValue * ($rewardPercent / 100), 2)
                : rewards_admin_decimal($rewardAmountRaw, 'Reward amount');

            $paidAtSql = $status === 'paid' ? date('Y-m-d H:i:s') : null;

            if ($rewardId > 0) {
                $currentStmt = $pdo->prepare("SELECT paid_at FROM dj_rewards WHERE id = ? LIMIT 1");
                $currentStmt->execute([$rewardId]);
                $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) throw new RuntimeException('Το Reward record δεν βρέθηκε.');

                if ($status === 'paid' && !empty($current['paid_at'])) {
                    $paidAtSql = (string)$current['paid_at'];
                }

                $stmt = $pdo->prepare(
                    "UPDATE dj_rewards
                     SET account_id = ?,
                         dj_name = ?,
                         business_name = ?,
                         contact_name = ?,
                         contact_email = ?,
                         contact_phone = ?,
                         campaign_value = ?,
                         reward_percent = ?,
                         reward_amount = ?,
                         status = ?,
                         dj_note = ?,
                         internal_note = ?,
                         referred_at = ?,
                         paid_at = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    $accountId,
                    (string)$rewardAccount['artist_name'],
                    $businessName,
                    $contactName,
                    $contactEmail,
                    $contactPhone,
                    $campaignValue,
                    $rewardPercent,
                    $rewardAmount,
                    $status,
                    $djNote,
                    $internalNote,
                    $referredAt,
                    $paidAtSql,
                    $rewardId,
                ]);

                $notice = 'Το Reward ενημερώθηκε.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO dj_rewards
                     (account_id, dj_name, business_name, contact_name, contact_email, contact_phone,
                      campaign_value, reward_percent, reward_amount, status, dj_note, internal_note,
                      referred_at, paid_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $accountId,
                    (string)$rewardAccount['artist_name'],
                    $businessName,
                    $contactName,
                    $contactEmail,
                    $contactPhone,
                    $campaignValue,
                    $rewardPercent,
                    $rewardAmount,
                    $status,
                    $djNote,
                    $internalNote,
                    $referredAt,
                    $paidAtSql,
                ]);

                $newRewardId = (int)$pdo->lastInsertId();
                $notice = 'Το νέο DJ Reward καταχωρήθηκε.';

                try {
                    $rewardMail = deseo_mylive_reward_created_email(
                        $rewardAccount,
                        [
                            'business_name' => $businessName,
                            'contact_name' => $contactName,
                            'contact_email' => $contactEmail,
                            'contact_phone' => $contactPhone,
                            'campaign_value' => $campaignValue,
                            'reward_percent' => $rewardPercent,
                            'reward_amount' => $rewardAmount,
                            'status' => $status,
                            'dj_note' => $djNote,
                            'referred_at' => $referredAt,
                        ]
                    );

                    deseo_send_smtp_mail(
                        (string)$rewardAccount['email'],
                        (string)$rewardAccount['artist_name'],
                        (string)$rewardMail['subject'],
                        (string)$rewardMail['html'],
                        (string)$rewardMail['text'],
                        true
                    );

                    $pdo->prepare(
                        "UPDATE dj_rewards
                         SET notification_email_sent_at = NOW()
                         WHERE id = ?"
                    )->execute([$newRewardId]);

                    $notice = 'Το νέο DJ Reward καταχωρήθηκε και στάλθηκε email στον '
                        . (string)$rewardAccount['artist_name']
                        . '.';
                } catch (Throwable $mailError) {
                    error_log(
                        'DJ Reward notification email failed for reward '
                        . $newRewardId
                        . ': '
                        . $mailError->getMessage()
                    );

                    $error = 'Το Reward αποθηκεύτηκε κανονικά, αλλά το email προς τον DJ δεν στάλθηκε: '
                        . $mailError->getMessage();
                }
            }
        } elseif ($action === 'delete_reward') {
            $rewardId = (int)($_POST['reward_id'] ?? 0);
            if ($rewardId < 1) throw new RuntimeException('Μη έγκυρο Reward record.');

            $stmt = $pdo->prepare("DELETE FROM dj_rewards WHERE id = ?");
            $stmt->execute([$rewardId]);
            $notice = 'Το Reward record διαγράφηκε.';
        }
    } catch (Throwable $e) {
        error_log('Rewards admin action failed: ' . $e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Η ενέργεια δεν ολοκληρώθηκε.';
    }
}

$accountsStmt = $pdo->query(
    "SELECT id, artist_name, email, account_status, is_active
     FROM dj_portal_accounts
     WHERE account_status <> 'pending'
     ORDER BY
        CASE WHEN is_active = 1 AND account_status = 'active' THEN 0 ELSE 1 END,
        artist_name ASC"
);
$rewardAccounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

$rewardsStmt = $pdo->query(
    "SELECT r.*,
            a.artist_name AS current_artist_name,
            a.email AS dj_email
     FROM dj_rewards r
     LEFT JOIN dj_portal_accounts a ON a.id = r.account_id
     ORDER BY COALESCE(r.referred_at, DATE(r.created_at)) DESC, r.id DESC"
);
$rewards = $rewardsStmt->fetchAll(PDO::FETCH_ASSOC);

$editReward = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    foreach ($rewards as $reward) {
        if ((int)$reward['id'] === $editId) {
            $editReward = $reward;
            break;
        }
    }
}

$statusCounts = array_fill_keys(array_keys(deseo_rewards_statuses()), 0);
$pendingRewards = 0.0;
$paidRewards = 0.0;
$totalCampaignValue = 0.0;

foreach ($rewards as $reward) {
    $status = (string)$reward['status'];
    if (isset($statusCounts[$status])) $statusCounts[$status]++;
    $amount = (float)$reward['reward_amount'];
    $totalCampaignValue += (float)$reward['campaign_value'];

    if (in_array($status, ['confirmed', 'reward_ready'], true)) $pendingRewards += $amount;
    if ($status === 'paid') $paidRewards += $amount;
}

admin_page_start('Rewards', 'rewards');
?>
<div class="page-heading rewards-heading">
    <div>
        <span>Deseo Radio · DJ Partner Rewards</span>
        <h1>Rewards</h1>
        <p>Καταχώρησε χειροκίνητα τα referrals που έρχονται από τα προσωπικά My Rewards links των DJs και ενημέρωνε ποσά, status και notes.</p>
    </div>
</div>

<?php if ($notice): ?><div class="notice notice-success"><?= admin_e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

<section class="rewards-admin-stats" aria-label="Rewards overview">
    <article>
        <span>REFERRALS</span>
        <strong><?= count($rewards) ?></strong>
        <small>συνολικά records</small>
    </article>
    <article>
        <span>IN DISCUSSION</span>
        <strong><?= (int)$statusCounts['in_discussion'] ?></strong>
        <small>ανοιχτές συζητήσεις</small>
    </article>
    <article>
        <span>PENDING REWARDS</span>
        <strong><?= admin_e(deseo_rewards_money($pendingRewards)) ?></strong>
        <small>confirmed + ready</small>
    </article>
    <article class="is-paid">
        <span>PAID</span>
        <strong><?= admin_e(deseo_rewards_money($paidRewards)) ?></strong>
        <small>συνολικά καταβληθέντα</small>
    </article>
</section>

<div class="rewards-admin-layout">
    <section class="panel rewards-editor-panel">
        <div class="rewards-panel-head">
            <div>
                <span><?= $editReward ? 'EDIT REWARD' : 'NEW REFERRAL' ?></span>
                <h2><?= $editReward ? admin_e((string)$editReward['business_name']) : 'Add a DJ referral' ?></h2>
                <p>Τα public στοιχεία και το DJ note εμφανίζονται στο MyLive. Το Internal Note μένει μόνο εδώ.</p>
            </div>
            <?php if ($editReward): ?>
                <a class="rewards-cancel-edit" href="rewards.php">Cancel edit</a>
            <?php endif; ?>
        </div>

        <?php if (!$rewardAccounts): ?>
            <div class="empty-admin">Δεν υπάρχουν ακόμη MyLive DJ accounts για σύνδεση με Rewards.</div>
        <?php else: ?>
            <form method="post" class="rewards-editor-form" id="rewardEditorForm">
                <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="save_reward">
                <input type="hidden" name="reward_id" value="<?= $editReward ? (int)$editReward['id'] : 0 ?>">

                <div class="field">
                    <label>DJ / MyLive account</label>
                    <select name="account_id" required>
                        <option value="">Select DJ</option>
                        <?php foreach ($rewardAccounts as $rewardAccount): ?>
                            <?php $selected = $editReward && (int)$editReward['account_id'] === (int)$rewardAccount['id']; ?>
                            <option value="<?= (int)$rewardAccount['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                                <?= admin_e((string)$rewardAccount['artist_name']) ?> · <?= admin_e((string)$rewardAccount['email']) ?>
                                <?= empty($rewardAccount['is_active']) ? ' · DISABLED' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Business</label>
                    <input type="text" name="business_name" required value="<?= admin_e((string)($editReward['business_name'] ?? '')) ?>" placeholder="Brand / company name">
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label>Contact name</label>
                        <input type="text" name="contact_name" value="<?= admin_e((string)($editReward['contact_name'] ?? '')) ?>" placeholder="Optional">
                    </div>
                    <div class="field">
                        <label>Referral date</label>
                        <input type="date" name="referred_at" value="<?= admin_e($editReward ? (string)($editReward['referred_at'] ?? '') : date('Y-m-d')) ?>">
                    </div>
                    <div class="field">
                        <label>Contact email</label>
                        <input type="email" name="contact_email" value="<?= admin_e((string)($editReward['contact_email'] ?? '')) ?>" placeholder="Optional">
                    </div>
                    <div class="field">
                        <label>Contact phone</label>
                        <input type="text" name="contact_phone" value="<?= admin_e((string)($editReward['contact_phone'] ?? '')) ?>" placeholder="Optional">
                    </div>
                </div>

                <div class="rewards-money-grid">
                    <div class="field">
                        <label>Campaign value (€)</label>
                        <input type="number" min="0" step="0.01" name="campaign_value" data-reward-campaign value="<?= admin_e(isset($editReward['campaign_value']) ? number_format((float)$editReward['campaign_value'], 2, '.', '') : '') ?>" placeholder="0.00">
                    </div>
                    <div class="field">
                        <label>Reward %</label>
                        <input type="number" min="0" max="100" step="0.01" name="reward_percent" data-reward-percent value="<?= admin_e(isset($editReward['reward_percent']) ? number_format((float)$editReward['reward_percent'], 2, '.', '') : '20.00') ?>">
                    </div>
                    <div class="field">
                        <label>Reward amount (€)</label>
                        <input type="number" min="0" step="0.01" name="reward_amount" data-reward-amount value="<?= admin_e(isset($editReward['reward_amount']) ? number_format((float)$editReward['reward_amount'], 2, '.', '') : '') ?>" placeholder="Auto">
                        <small class="rewards-field-help">Υπολογίζεται αυτόματα, αλλά μπορείς να το αλλάξεις.</small>
                    </div>
                </div>

                <div class="field">
                    <label>Status</label>
                    <select name="status">
                        <?php $currentStatus = (string)($editReward['status'] ?? 'new'); ?>
                        <?php foreach (deseo_rewards_statuses() as $statusKey => $statusLabel): ?>
                            <option value="<?= admin_e($statusKey) ?>" <?= $currentStatus === $statusKey ? 'selected' : '' ?>><?= admin_e($statusLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field rewards-textarea-field">
                    <label>DJ note · visible in MyLive</label>
                    <textarea name="dj_note" rows="4" placeholder="Optional update for the DJ…"><?= admin_e((string)($editReward['dj_note'] ?? '')) ?></textarea>
                </div>

                <div class="field rewards-textarea-field">
                    <label>Internal note · ILUMA only</label>
                    <textarea name="internal_note" rows="4" placeholder="Email context, follow-up, invoice notes…"><?= admin_e((string)($editReward['internal_note'] ?? '')) ?></textarea>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit"><?= $editReward ? 'Update Reward' : 'Add Referral' ?></button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel rewards-list-panel">
        <div class="rewards-panel-head">
            <div>
                <span>REFERRAL PIPELINE</span>
                <h2>All Rewards</h2>
                <p><?= admin_e(deseo_rewards_money($totalCampaignValue)) ?> συνολικό campaign value στα καταχωρημένα referrals.</p>
            </div>
            <strong><?= count($rewards) ?></strong>
        </div>

        <?php if (!$rewards): ?>
            <div class="empty-admin">Δεν έχει καταχωρηθεί ακόμη κάποιο DJ referral.</div>
        <?php else: ?>
            <div class="rewards-admin-list">
                <?php foreach ($rewards as $reward): ?>
                    <?php
                    $displayDj = trim((string)($reward['current_artist_name'] ?? '')) !== ''
                        ? (string)$reward['current_artist_name']
                        : (string)$reward['dj_name'];
                    ?>
                    <article class="rewards-admin-card">
                        <div class="rewards-admin-card-top">
                            <div>
                                <span class="reward-status <?= admin_e(deseo_rewards_status_class((string)$reward['status'])) ?>">
                                    <?= admin_e(deseo_rewards_status_label((string)$reward['status'])) ?>
                                </span>
                                <h3><?= admin_e((string)$reward['business_name']) ?></h3>
                                <p><?= admin_e($displayDj) ?><?= !empty($reward['referred_at']) ? ' · ' . admin_e(date('d.m.Y', strtotime((string)$reward['referred_at']))) : '' ?></p>
                            </div>
                            <div class="rewards-admin-amount">
                                <small>DJ REWARD</small>
                                <strong><?= admin_e(deseo_rewards_money((float)$reward['reward_amount'])) ?></strong>
                            </div>
                        </div>

                        <div class="rewards-admin-meta">
                            <span>Campaign <b><?= admin_e(deseo_rewards_money((float)$reward['campaign_value'])) ?></b></span>
                            <span>Reward <b><?= admin_e(number_format((float)$reward['reward_percent'], 2, ',', '.')) ?>%</b></span>
                            <?php if (!empty($reward['contact_name'])): ?><span>Contact <b><?= admin_e((string)$reward['contact_name']) ?></b></span><?php endif; ?>
                            <?php if (!empty($reward['notification_email_sent_at'])): ?>
                                <span class="reward-email-state is-sent">Email <b>Sent ✓</b></span>
                            <?php else: ?>
                                <span class="reward-email-state is-pending">Email <b>Not sent</b></span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($reward['internal_note'])): ?>
                            <p class="rewards-internal-preview"><?= admin_e((string)$reward['internal_note']) ?></p>
                        <?php endif; ?>

                        <div class="rewards-admin-actions">
                            <a class="button button-secondary" href="rewards.php?edit=<?= (int)$reward['id'] ?>">Edit</a>
                            <form method="post"
                                  data-deseo-confirm="Να διαγραφεί οριστικά αυτό το Reward record;"
                                  data-deseo-confirm-title="Delete Reward"
                                  data-deseo-confirm-label="Delete"
                                  data-deseo-confirm-danger>
                                <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_reward">
                                <input type="hidden" name="reward_id" value="<?= (int)$reward['id'] ?>">
                                <button class="button button-danger" type="submit">Delete</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
(function () {
    const campaign = document.querySelector('[data-reward-campaign]');
    const percent = document.querySelector('[data-reward-percent]');
    const amount = document.querySelector('[data-reward-amount]');
    if (!campaign || !percent || !amount) return;

    let manualAmount = amount.value.trim() !== '';

    amount.addEventListener('input', function () {
        manualAmount = amount.value.trim() !== '';
    });

    function calculate() {
        if (manualAmount) return;
        const campaignValue = parseFloat(campaign.value || '0');
        const rewardPercent = parseFloat(percent.value || '0');
        if (!Number.isFinite(campaignValue) || !Number.isFinite(rewardPercent)) return;
        amount.value = (campaignValue * rewardPercent / 100).toFixed(2);
    }

    campaign.addEventListener('input', calculate);
    percent.addEventListener('input', calculate);
    calculate();
}());
</script>
<?php admin_page_end(); ?>
