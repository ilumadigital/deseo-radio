<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/dj-portal.php';
require_once __DIR__ . '/../includes/mylive-communications.php';
require_once __DIR__ . '/admin-ui.php';

admin_require_access('communications');
deseo_mylive_bootstrap($pdo);
deseo_mylive_communications_bootstrap($pdo);

$notice = null;
$error = null;

function communications_admin_account(PDO $pdo, int $accountId): array {
    $stmt = $pdo->prepare(
        "SELECT *
         FROM dj_portal_accounts
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$accountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        throw new RuntimeException('Το MyLive account δεν βρέθηκε.');
    }
    return $account;
}

function communications_variable_picker(string $targetId): void {
    $variables = [
        '{artist}' => 'DJ / Artist name',
        '{name}' => 'Full name',
        '{email}' => 'MyLive email',
        '{slot}' => 'Weekly slot',
    ];
    ?>
    <select class="communications-variable-picker"
            data-variable-picker
            data-variable-target="<?= admin_e($targetId) ?>"
            aria-label="Insert variable into <?= admin_e($targetId) ?>">
        <option value="">+ Insert variable</option>
        <?php foreach ($variables as $token => $label): ?>
            <option value="<?= admin_e($token) ?>"><?= admin_e($token) ?> · <?= admin_e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <?php
}

function communications_admin_targets(PDO $pdo, string $target): array {
    $sql =
        "SELECT *
         FROM dj_portal_accounts
         WHERE is_active = 1
           AND account_status = 'active'";
    $params = [];

    if ($target !== 'all') {
        $accountId = (int)$target;
        if ($accountId < 1) {
            throw new RuntimeException('Επίλεξε έγκυρο MyLive user.');
        }
        $sql .= " AND id = ?";
        $params[] = $accountId;
    }

    $sql .= " ORDER BY artist_name ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!admin_verify_csrf($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Η συνεδρία έληξε. Ανανέωσε τη σελίδα και δοκίμασε ξανά.');
        }

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_preferences') {
            $accountId = (int)($_POST['account_id'] ?? 0);
            if ($accountId < 1) {
                throw new RuntimeException('Δεν βρέθηκε MyLive user.');
            }

            $account = communications_admin_account($pdo, $accountId);
            deseo_mylive_save_communication_preferences($pdo, $accountId, [
                'email_enabled' => isset($_POST['email_enabled']),
                'push_enabled' => isset($_POST['push_enabled']),
                'set_reminder_email' => isset($_POST['set_reminder_email']),
                'set_reminder_push' => isset($_POST['set_reminder_push']),
                'on_air_email' => isset($_POST['on_air_email']),
                'on_air_push' => isset($_POST['on_air_push']),
                'announcements_email' => isset($_POST['announcements_email']),
                'announcements_push' => isset($_POST['announcements_push']),
            ]);

            $notice = 'Οι ρυθμίσεις επικοινωνίας του ' . (string)$account['artist_name'] . ' αποθηκεύτηκαν.';

        } elseif ($action === 'send_manual_email') {
            $target = trim((string)($_POST['target'] ?? ''));
            $subjectRaw = trim((string)($_POST['subject'] ?? ''));
            $messageRaw = trim((string)($_POST['message'] ?? ''));
            $ctaLabelRaw = trim((string)($_POST['cta_label'] ?? ''));
            $ctaUrlRaw = trim((string)($_POST['cta_url'] ?? ''));

            if ($subjectRaw === '') throw new RuntimeException('Συμπλήρωσε Email Subject.');
            if ($messageRaw === '') throw new RuntimeException('Συμπλήρωσε το email message.');
            if ($ctaUrlRaw !== '' && !filter_var($ctaUrlRaw, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Το CTA URL δεν είναι έγκυρο.');
            }

            $targets = communications_admin_targets($pdo, $target);
            if (!$targets) throw new RuntimeException('Δεν βρέθηκαν παραλήπτες.');

            $sent = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($targets as $account) {
                $accountId = (int)$account['id'];

                if (
                    !filter_var((string)$account['email'], FILTER_VALIDATE_EMAIL)
                    || !deseo_mylive_communication_allows($pdo, $accountId, 'announcement', 'email')
                ) {
                    $skipped++;
                    continue;
                }

                $subject = deseo_mylive_personalize_communication($account, $subjectRaw);
                $message = deseo_mylive_personalize_communication($account, $messageRaw);
                $ctaLabel = deseo_mylive_personalize_communication($account, $ctaLabelRaw);
                $ctaUrl = deseo_mylive_personalize_communication($account, $ctaUrlRaw);

                try {
                    $mail = deseo_mylive_manual_email($account, $subject, $message, $ctaLabel, $ctaUrl);
                    deseo_send_smtp_mail(
                        (string)$account['email'],
                        (string)$account['artist_name'],
                        (string)$mail['subject'],
                        (string)$mail['html'],
                        (string)$mail['text'],
                        false
                    );

                    deseo_mylive_communication_log(
                        $pdo,
                        $accountId,
                        'email',
                        'announcement',
                        $subject,
                        $message,
                        $ctaUrl,
                        true,
                        admin_current_email()
                    );
                    $sent++;
                } catch (Throwable $sendError) {
                    deseo_mylive_communication_log(
                        $pdo,
                        $accountId,
                        'email',
                        'announcement',
                        $subject,
                        $message,
                        $ctaUrl,
                        false,
                        admin_current_email(),
                        $sendError->getMessage()
                    );
                    error_log('Manual DJ email failed for account ' . $accountId . ': ' . $sendError->getMessage());
                    $failed++;
                }
            }

            if ($sent < 1 && $failed > 0) {
                throw new RuntimeException('Δεν στάλθηκε κανένα email. Έλεγξε το communication log.');
            }

            $notice = 'Manual Email · sent ' . $sent . ' · skipped ' . $skipped . ' · failed ' . $failed . '.';

        } elseif ($action === 'send_manual_push') {
            $target = trim((string)($_POST['target'] ?? ''));
            $titleRaw = trim((string)($_POST['title'] ?? ''));
            $messageRaw = trim((string)($_POST['message'] ?? ''));
            $targetUrlRaw = trim((string)($_POST['target_url'] ?? 'https://deseoradio.com/mylive/'));

            if ($titleRaw === '') throw new RuntimeException('Συμπλήρωσε Push Title.');
            if ($messageRaw === '') throw new RuntimeException('Συμπλήρωσε το push message.');
            if ($targetUrlRaw !== '' && !filter_var($targetUrlRaw, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Το target URL δεν είναι έγκυρο.');
            }

            $targets = communications_admin_targets($pdo, $target);
            if (!$targets) throw new RuntimeException('Δεν βρέθηκαν παραλήπτες.');

            $sent = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($targets as $account) {
                $accountId = (int)$account['id'];

                if (
                    !deseo_mylive_communication_allows($pdo, $accountId, 'announcement', 'push')
                    || deseo_mylive_push_subscription_count($pdo, $accountId) < 1
                ) {
                    $skipped++;
                    continue;
                }

                $title = deseo_mylive_personalize_communication($account, $titleRaw);
                $message = deseo_mylive_personalize_communication($account, $messageRaw);
                $targetUrl = deseo_mylive_personalize_communication($account, $targetUrlRaw);

                try {
                    deseo_mylive_push_send_to_account(
                        $pdo,
                        $accountId,
                        $title,
                        $message,
                        $targetUrl,
                        [
                            'name' => 'Manual · ' . (string)$account['artist_name'],
                            'expire_push' => '1d',
                            'auto_hide' => 1,
                        ]
                    );

                    deseo_mylive_communication_log(
                        $pdo,
                        $accountId,
                        'push',
                        'announcement',
                        $title,
                        $message,
                        $targetUrl,
                        true,
                        admin_current_email()
                    );
                    $sent++;
                } catch (Throwable $sendError) {
                    deseo_mylive_communication_log(
                        $pdo,
                        $accountId,
                        'push',
                        'announcement',
                        $title,
                        $message,
                        $targetUrl,
                        false,
                        admin_current_email(),
                        $sendError->getMessage()
                    );
                    error_log('Manual DJ push failed for account ' . $accountId . ': ' . $sendError->getMessage());
                    $failed++;
                }
            }

            if ($sent < 1 && $failed > 0) {
                throw new RuntimeException('Δεν στάλθηκε κανένα push. Έλεγξε το communication log.');
            }

            $notice = 'Manual Push · sent ' . $sent . ' · skipped ' . $skipped . ' · failed ' . $failed . '.';
        }
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Κάτι πήγε στραβά. Δοκίμασε ξανά.';
        error_log('Communications admin action failed: ' . $e->getMessage());
    }
}

$accounts = deseo_mylive_communication_accounts($pdo);
$activeAccounts = array_values(array_filter(
    $accounts,
    static fn(array $row): bool =>
        !empty($row['is_active']) && (string)$row['account_status'] === 'active'
));

$emailEnabledCount = 0;
$pushEnabledCount = 0;
$pushDeviceCount = 0;
foreach ($activeAccounts as $row) {
    if (!empty($row['email_notifications_enabled'])) $emailEnabledCount++;
    if (!empty($row['push_notifications_enabled'])) $pushEnabledCount++;
    $pushDeviceCount += (int)($row['push_devices'] ?? 0);
}

$logStmt = $pdo->query(
    "SELECT l.*, a.artist_name
     FROM dj_communication_log l
     LEFT JOIN dj_portal_accounts a ON a.id = l.account_id
     ORDER BY l.id DESC
     LIMIT 30"
);
$recentLogs = $logStmt ? $logStmt->fetchAll(PDO::FETCH_ASSOC) : [];

admin_page_start('Communications', 'communications');
?>
<div class="page-heading communications-heading">
    <div>
        <span>Deseo Radio · MyLive Messaging</span>
        <h1>Communications</h1>
        <p>Διαχειρίσου τα Email και Push preferences όλων των MyLive DJs και στείλε προσωποποιημένα manual messages με το επίσημο Deseo Radio template.</p>
    </div>
</div>

<?php if ($notice): ?><div class="notice notice-success"><?= admin_e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= admin_e($error) ?></div><?php endif; ?>

<section class="communications-stats">
    <article>
        <span>ACTIVE MYLIVE</span>
        <strong><?= count($activeAccounts) ?></strong>
        <small>DJ accounts</small>
    </article>
    <article>
        <span>EMAIL ON</span>
        <strong><?= $emailEnabledCount ?></strong>
        <small>users</small>
    </article>
    <article>
        <span>PUSH ON</span>
        <strong><?= $pushEnabledCount ?></strong>
        <small>users</small>
    </article>
    <article>
        <span>PUSH DEVICES</span>
        <strong><?= $pushDeviceCount ?></strong>
        <small>active subscriptions</small>
    </article>
</section>

<section class="communications-compose-grid">
    <article class="panel communications-compose-card">
        <div class="communications-card-head">
            <span>MANUAL EMAIL</span>
            <h2>Send branded email</h2>
            <p>Χρησιμοποιεί το κανονικό MyLive / Deseo Radio email template. Υποστηρίζει personalization placeholders.</p>
        </div>

        <form method="post" class="communications-form">
            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="send_manual_email">

            <div class="field">
                <label>Recipient</label>
                <select name="target" required>
                    <option value="all">All eligible MyLive DJs</option>
                    <?php foreach ($activeAccounts as $account): ?>
                        <option value="<?= (int)$account['id'] ?>">
                            <?= admin_e((string)$account['artist_name']) ?> · <?= admin_e((string)$account['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <div class="communications-field-head">
                    <label for="manual-email-subject">Subject</label>
                    <?php communications_variable_picker('manual-email-subject'); ?>
                </div>
                <input id="manual-email-subject" type="text" name="subject" required maxlength="190" placeholder="Deseo Radio · Νέα ενημέρωση για {artist}">
            </div>

            <div class="field">
                <div class="communications-field-head">
                    <label for="manual-email-message">Message</label>
                    <?php communications_variable_picker('manual-email-message'); ?>
                </div>
                <textarea id="manual-email-message" name="message" required rows="7" placeholder="{artist}, γράψε εδώ το μήνυμα που θέλεις να λάβει ο DJ."></textarea>
            </div>

            <div class="form-grid">
                <div class="field">
                    <div class="communications-field-head">
                        <label for="manual-email-cta-label">CTA Label</label>
                        <?php communications_variable_picker('manual-email-cta-label'); ?>
                    </div>
                    <input id="manual-email-cta-label" type="text" name="cta_label" placeholder="OPEN MYLIVE">
                </div>
                <div class="field">
                    <label>CTA URL</label>
                    <input type="url" name="cta_url" placeholder="https://deseoradio.com/mylive/">
                </div>
            </div>

            <div class="communications-placeholders" aria-label="Available variables">
                <span><b>{artist}</b> Artist</span>
                <span><b>{name}</b> Full name</span>
                <span><b>{email}</b> Email</span>
                <span><b>{slot}</b> Weekly slot</span>
            </div>

            <div class="form-actions">
                <button class="button button-primary" type="submit">Send Manual Email</button>
            </div>
        </form>
    </article>

    <article class="panel communications-compose-card">
        <div class="communications-card-head">
            <span>MANUAL PUSH</span>
            <h2>Send push notification</h2>
            <p>Στέλνεται μόνο σε DJs που έχουν ενεργό Push channel, Announcement Push και τουλάχιστον μία registered συσκευή.</p>
        </div>

        <form method="post" class="communications-form">
            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="send_manual_push">

            <div class="field">
                <label>Recipient</label>
                <select name="target" required>
                    <option value="all">All eligible MyLive DJs</option>
                    <?php foreach ($activeAccounts as $account): ?>
                        <option value="<?= (int)$account['id'] ?>">
                            <?= admin_e((string)$account['artist_name']) ?> · <?= (int)$account['push_devices'] ?> device<?= (int)$account['push_devices'] === 1 ? '' : 's' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <div class="communications-field-head">
                    <label for="manual-push-title">Push Title</label>
                    <?php communications_variable_picker('manual-push-title'); ?>
                </div>
                <input id="manual-push-title" type="text" name="title" required maxlength="100" placeholder="Deseo Radio · Update for {artist}">
            </div>

            <div class="field">
                <div class="communications-field-head">
                    <label for="manual-push-message">Push Message</label>
                    <?php communications_variable_picker('manual-push-message'); ?>
                </div>
                <textarea id="manual-push-message" name="message" required rows="7" maxlength="255" placeholder="{artist}, γράψε εδώ το σύντομο push message."></textarea>
            </div>

            <div class="field">
                <label>Open URL</label>
                <input type="url" name="target_url" value="https://deseoradio.com/mylive/">
            </div>

            <div class="communications-placeholders" aria-label="Available variables">
                <span><b>{artist}</b> Artist</span>
                <span><b>{name}</b> Full name</span>
                <span><b>{email}</b> Email</span>
                <span><b>{slot}</b> Weekly slot</span>
            </div>

            <div class="form-actions">
                <button class="button button-primary" type="submit">Send Manual Push</button>
            </div>
        </form>
    </article>
</section>

<section class="communications-directory">
    <div class="communications-section-head">
        <div>
            <span>MYLIVE USERS</span>
            <h2>Notification preferences</h2>
        </div>
        <p>Οι ίδιες ρυθμίσεις εμφανίζονται και στο MySettings του DJ. Ό,τι αλλάζει εδώ συγχρονίζεται άμεσα.</p>
    </div>

    <?php if (!$accounts): ?>
        <div class="empty-admin">Δεν υπάρχουν MyLive users.</div>
    <?php else: ?>
        <div class="communications-users">
            <?php foreach ($accounts as $account): ?>
                <?php
                $accountId = (int)$account['id'];
                $isActive = !empty($account['is_active']) && (string)$account['account_status'] === 'active';
                ?>
                <article class="communications-user-card <?= $isActive ? '' : 'is-disabled' ?>">
                    <div class="communications-user-head">
                        <div>
                            <span><?= $isActive ? 'ACTIVE MYLIVE' : strtoupper((string)$account['account_status']) ?></span>
                            <h3><?= admin_e((string)$account['artist_name']) ?></h3>
                            <p><?= admin_e((string)$account['email']) ?> · <?= admin_e(deseo_mylive_slot($account)) ?></p>
                        </div>
                        <div class="communications-user-devices">
                            <strong><?= (int)$account['push_devices'] ?></strong>
                            <span>PUSH DEVICE<?= (int)$account['push_devices'] === 1 ? '' : 'S' ?></span>
                        </div>
                    </div>

                    <form method="post" class="communications-pref-form">
                        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_preferences">
                        <input type="hidden" name="account_id" value="<?= $accountId ?>">

                        <div class="communications-channel-masters">
                            <label class="communications-switch">
                                <input type="checkbox" name="email_enabled" value="1" <?= !empty($account['email_notifications_enabled']) ? 'checked' : '' ?>>
                                <span></span>
                                <div><strong>Email</strong><small>Master channel</small></div>
                            </label>
                            <label class="communications-switch">
                                <input type="checkbox" name="push_enabled" value="1" <?= !empty($account['push_notifications_enabled']) ? 'checked' : '' ?>>
                                <span></span>
                                <div><strong>Push</strong><small><?= (int)$account['push_devices'] ?> registered device<?= (int)$account['push_devices'] === 1 ? '' : 's' ?></small></div>
                            </label>
                        </div>

                        <div class="communications-pref-matrix">
                            <div class="communications-pref-row is-head">
                                <strong>TYPE</strong><span>EMAIL</span><span>PUSH</span>
                            </div>
                            <div class="communications-pref-row">
                                <div><strong>3-day Set Reminder</strong><small>μόνο όταν λείπει set</small></div>
                                <label><input type="checkbox" name="set_reminder_email" value="1" <?= !empty($account['notify_set_reminder_email']) ? 'checked' : '' ?>><span></span></label>
                                <label><input type="checkbox" name="set_reminder_push" value="1" <?= !empty($account['notify_set_reminder_push']) ? 'checked' : '' ?>><span></span></label>
                            </div>
                            <div class="communications-pref-row">
                                <div><strong>On Air Now</strong><small>μόλις ξεκινήσει το slot</small></div>
                                <label><input type="checkbox" name="on_air_email" value="1" <?= !empty($account['notify_on_air_email']) ? 'checked' : '' ?>><span></span></label>
                                <label><input type="checkbox" name="on_air_push" value="1" <?= !empty($account['notify_on_air_push']) ? 'checked' : '' ?>><span></span></label>
                            </div>
                            <div class="communications-pref-row">
                                <div><strong>Announcements</strong><small>manual ενημερώσεις Deseo</small></div>
                                <label><input type="checkbox" name="announcements_email" value="1" <?= !empty($account['notify_announcements_email']) ? 'checked' : '' ?>><span></span></label>
                                <label><input type="checkbox" name="announcements_push" value="1" <?= !empty($account['notify_announcements_push']) ? 'checked' : '' ?>><span></span></label>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button class="button button-secondary" type="submit">Save Preferences</button>
                        </div>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel communications-log-panel">
    <div class="communications-section-head">
        <div>
            <span>DELIVERY LOG</span>
            <h2>Recent manual messages</h2>
        </div>
        <p>Τα τελευταία 30 manual Email / Push sends από το CMS.</p>
    </div>

    <?php if (!$recentLogs): ?>
        <div class="empty-admin">Δεν υπάρχουν ακόμη manual communications.</div>
    <?php else: ?>
        <div class="communications-log-list">
            <?php foreach ($recentLogs as $log): ?>
                <div class="communications-log-row">
                    <span class="is-<?= admin_e((string)$log['status']) ?>"><?= admin_e(strtoupper((string)$log['channel'])) ?> · <?= admin_e(strtoupper((string)$log['status'])) ?></span>
                    <div>
                        <strong><?= admin_e((string)($log['artist_name'] ?: 'Deleted user')) ?></strong>
                        <p><?= admin_e((string)$log['subject']) ?></p>
                    </div>
                    <small><?= admin_e((string)($log['sent_at'] ?: $log['created_at'])) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script>
(function(){
  function insertVariable(select) {
    var token = select.value;
    if (!token) return;

    var targetId = select.getAttribute('data-variable-target');
    var field = targetId ? document.getElementById(targetId) : null;
    select.value = '';
    if (!field) return;

    var value = field.value || '';
    var start = typeof field.selectionStart === 'number' ? field.selectionStart : value.length;
    var end = typeof field.selectionEnd === 'number' ? field.selectionEnd : start;

    field.value = value.slice(0, start) + token + value.slice(end);
    var caret = start + token.length;

    field.focus();
    if (typeof field.setSelectionRange === 'function') {
      field.setSelectionRange(caret, caret);
    }

    field.dispatchEvent(new Event('input', { bubbles: true }));
  }

  document.querySelectorAll('[data-variable-picker]').forEach(function(select){
    select.addEventListener('change', function(){
      insertVariable(select);
    });
  });
}());
</script>

<?php admin_page_end(); ?>
