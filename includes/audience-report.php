<?php
declare(strict_types=1);

require_once __DIR__ . '/dj-portal.php';
require_once __DIR__ . '/audience.php';
require_once __DIR__ . '/mailer.php';

const DESEO_AUDIENCE_REPORT_RECIPIENT = 'greg@iluma.gr';
const DESEO_AUDIENCE_REPORT_RECIPIENT_NAME = 'Greg · ILUMA Digital Agency';

function deseo_audience_report_month_bounds(string $monthKey): array {
    $tz = new DateTimeZone('Europe/Athens');
    $start = DateTimeImmutable::createFromFormat('!Y-m', $monthKey, $tz);
    if (!$start) {
        throw new RuntimeException('Invalid audience report month.');
    }

    return [
        $start->format('Y-m-01 00:00:00'),
        $start->modify('+1 month')->format('Y-m-01 00:00:00'),
    ];
}

function deseo_audience_report_data(PDO $pdo, string $monthKey): array {
    deseo_mylive_bootstrap($pdo);
    deseo_audience_bootstrap($pdo);

    [$start, $end] = deseo_audience_report_month_bounds($monthKey);
    $monthlyListeners = deseo_audience_month_listeners($pdo, $monthKey);

    $stmt = $pdo->prepare(
        "SELECT
            a.id,
            a.artist_name,
            a.day_of_week,
            a.start_time,
            a.end_time,
            a.show_audience_stats,
            COALESCE(b.status, 'manual') AS application_status,
            (
                SELECT COUNT(*)
                FROM dj_portal_sets s
                WHERE s.account_id = a.id
                  AND s.uploaded_at >= ?
                  AND s.uploaded_at < ?
            ) AS month_sets,
            (
                SELECT COUNT(*)
                FROM dj_portal_assets x
                WHERE x.account_id = a.id
                  AND x.created_at >= ?
                  AND x.created_at < ?
            ) AS month_assets
         FROM dj_portal_accounts a
         LEFT JOIN dj_season_bookings b ON b.id = a.booking_id
         WHERE a.is_active = 1
           AND a.account_status = 'active'
         ORDER BY a.day_of_week ASC, a.start_time ASC, a.artist_name ASC"
    );
    $stmt->execute([$start, $end, $start, $end]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalSets = 0;
    $totalAssets = 0;
    $visible = 0;
    $hidden = 0;
    $strongest = null;

    foreach ($accounts as &$account) {
        $account['estimated_reach'] = $monthlyListeners > 0
            ? deseo_audience_estimated_reach_for_month(
                $pdo,
                $account,
                $monthKey,
                $monthlyListeners
            )
            : 0;

        $totalSets += (int)$account['month_sets'];
        $totalAssets += (int)$account['month_assets'];

        if (!empty($account['show_audience_stats'])) {
            $visible++;
        } else {
            $hidden++;
        }

        if (
            $monthlyListeners > 0
            && ($strongest === null || (int)$account['estimated_reach'] > (int)$strongest['estimated_reach'])
        ) {
            $strongest = $account;
        }
    }
    unset($account);

    return [
        'month_key' => $monthKey,
        'month_label' => deseo_audience_month_label($monthKey),
        'monthly_listeners' => $monthlyListeners,
        'active_djs' => count($accounts),
        'visible_djs' => $visible,
        'hidden_djs' => $hidden,
        'total_sets' => $totalSets,
        'total_assets' => $totalAssets,
        'strongest' => $strongest,
        'accounts' => $accounts,
    ];
}

function deseo_audience_report_email(array $report, string $newMonthKey): array {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

    $monthLabel = (string)$report['month_label'];
    $newMonthLabel = deseo_audience_month_label($newMonthKey);
    $listeners = (int)$report['monthly_listeners'];
    $listenerText = $listeners > 0 ? deseo_audience_format($listeners) : 'Δεν καταχωρήθηκε';
    $strongest = $report['strongest'] ?? null;

    $strongestText = '—';
    if (is_array($strongest) && !empty($strongest['artist_name'])) {
        $strongestText = (string)$strongest['artist_name']
            . ' · ~'
            . deseo_audience_format((int)$strongest['estimated_reach']);
    }

    $djRows = '';
    foreach ($report['accounts'] as $account) {
        $status = strtoupper((string)($account['application_status'] ?? 'manual'));
        $slot = deseo_mylive_slot($account);
        $reach = $listeners > 0
            ? '~' . deseo_audience_format((int)$account['estimated_reach'])
            : '—';

        $djRows .=
            '<tr>' .
                '<td style="padding:13px 8px 13px 0;border-bottom:1px solid #242427;color:#fff;font:700 13px Arial,sans-serif;">' .
                    $e($account['artist_name']) .
                    '<div style="margin-top:4px;color:#6f6f76;font:700 8px Arial,sans-serif;letter-spacing:.08em;">' . $e($status) . '</div>' .
                '</td>' .
                '<td style="padding:13px 8px;border-bottom:1px solid #242427;color:#8b8b91;font:400 11px Arial,sans-serif;">' . $e($slot) . '</td>' .
                '<td style="padding:13px 8px;border-bottom:1px solid #242427;color:#fff;font:700 12px Arial,sans-serif;text-align:right;">' . $e($reach) . '</td>' .
                '<td style="padding:13px 0 13px 8px;border-bottom:1px solid #242427;color:#8b8b91;font:700 11px Arial,sans-serif;text-align:right;">' .
                    (int)$account['month_sets'] . ' set' . ((int)$account['month_sets'] === 1 ? '' : 's') .
                '</td>' .
            '</tr>';
    }

    if ($djRows === '') {
        $djRows = '<tr><td colspan="4" style="padding:22px 0;color:#6f6f76;font:400 12px Arial,sans-serif;text-align:center;">Δεν υπήρχαν ενεργοί MyLive DJs για το report.</td></tr>';
    }

    $subject = 'Deseo Radio · Monthly Audience Report · ' . $monthLabel;

    $html =
        '<!doctype html><html><body style="margin:0;padding:0;background:#050505;color:#fff;">' .
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#050505;padding:30px 12px;"><tr><td align="center">' .
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:720px;background:#0d0d0f;border:1px solid #252529;border-radius:26px;overflow:hidden;">' .

        '<tr><td style="padding:32px;background:#101012;border-bottom:1px solid #252529;">' .
            '<img src="https://deseoradio.com/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="190" style="display:block;max-width:190px;height:auto;margin:0 0 28px;">' .
            '<div style="color:#ff2b36;font:800 10px Arial,sans-serif;letter-spacing:.17em;text-transform:uppercase;">ILUMA CMS · MONTHLY AUDIENCE</div>' .
            '<h1 style="margin:9px 0 12px;color:#fff;font:800 34px/1.05 Arial,sans-serif;">Ο ' . $e($monthLabel) . ' έκλεισε.</h1>' .
            '<p style="margin:0;color:#a5a5ac;font:400 15px/1.65 Arial,sans-serif;">Η μηνιαία εικόνα του Deseo Radio και των MyLive DJs είναι έτοιμη. Παρακάτω είναι η σύνοψη πριν περαστούν τα νέα στοιχεία για ' . $e($newMonthLabel) . '.</p>' .
        '</td></tr>' .

        '<tr><td style="padding:28px 32px;">' .
            '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>' .
                '<td width="50%" style="padding:17px;border:1px solid #252529;border-radius:16px;background:#09090b;">' .
                    '<div style="color:#707078;font:800 9px Arial,sans-serif;letter-spacing:.1em;text-transform:uppercase;">MONTHLY LISTENERS</div>' .
                    '<div style="margin-top:6px;color:#fff;font:800 27px Arial,sans-serif;">' . $e($listenerText) . '</div>' .
                '</td>' .
                '<td width="12"></td>' .
                '<td width="50%" style="padding:17px;border:1px solid #252529;border-radius:16px;background:#09090b;">' .
                    '<div style="color:#707078;font:800 9px Arial,sans-serif;letter-spacing:.1em;text-transform:uppercase;">ACTIVE MYLIVE DJS</div>' .
                    '<div style="margin-top:6px;color:#fff;font:800 27px Arial,sans-serif;">' . (int)$report['active_djs'] . '</div>' .
                '</td>' .
            '</tr></table>' .

            '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:12px;"><tr>' .
                '<td width="33%" style="padding:15px;border:1px solid #252529;border-radius:15px;background:#09090b;">' .
                    '<div style="color:#707078;font:800 8px Arial,sans-serif;letter-spacing:.08em;">DJ SETS</div>' .
                    '<div style="margin-top:5px;color:#fff;font:800 22px Arial,sans-serif;">' . (int)$report['total_sets'] . '</div>' .
                '</td>' .
                '<td width="10"></td>' .
                '<td width="33%" style="padding:15px;border:1px solid #252529;border-radius:15px;background:#09090b;">' .
                    '<div style="color:#707078;font:800 8px Arial,sans-serif;letter-spacing:.08em;">ASSETS</div>' .
                    '<div style="margin-top:5px;color:#fff;font:800 22px Arial,sans-serif;">' . (int)$report['total_assets'] . '</div>' .
                '</td>' .
                '<td width="10"></td>' .
                '<td width="33%" style="padding:15px;border:1px solid #252529;border-radius:15px;background:#09090b;">' .
                    '<div style="color:#707078;font:800 8px Arial,sans-serif;letter-spacing:.08em;">STATS VISIBLE</div>' .
                    '<div style="margin-top:5px;color:#fff;font:800 22px Arial,sans-serif;">' . (int)$report['visible_djs'] . '/' . (int)$report['active_djs'] . '</div>' .
                '</td>' .
            '</tr></table>' .

            '<div style="margin-top:18px;padding:18px 20px;border-radius:17px;background:#ff2b36;color:#080808;">' .
                '<div style="font:800 9px Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase;">STRONGEST ESTIMATED DJ REACH</div>' .
                '<div style="margin-top:6px;font:800 21px Arial,sans-serif;">' . $e($strongestText) . '</div>' .
            '</div>' .

            '<div style="margin-top:28px;color:#fff;font:800 15px Arial,sans-serif;">DJ breakdown · ' . $e($monthLabel) . '</div>' .
            '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:9px;">' .
                '<tr>' .
                    '<th align="left" style="padding:8px 8px 8px 0;color:#5d5d64;font:800 8px Arial,sans-serif;letter-spacing:.09em;">DJ</th>' .
                    '<th align="left" style="padding:8px;color:#5d5d64;font:800 8px Arial,sans-serif;letter-spacing:.09em;">SLOT</th>' .
                    '<th align="right" style="padding:8px;color:#5d5d64;font:800 8px Arial,sans-serif;letter-spacing:.09em;">EST. REACH</th>' .
                    '<th align="right" style="padding:8px 0 8px 8px;color:#5d5d64;font:800 8px Arial,sans-serif;letter-spacing:.09em;">UPLOADS</th>' .
                '</tr>' .
                $djRows .
            '</table>' .

            '<div style="margin-top:28px;padding:22px;border:1px solid rgba(255,43,54,.28);border-radius:18px;background:rgba(255,43,54,.06);">' .
                '<div style="color:#ff2b36;font:800 9px Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase;">NEXT ACTION · ' . $e($newMonthLabel) . '</div>' .
                '<h2 style="margin:7px 0 8px;color:#fff;font:800 22px Arial,sans-serif;">Πέρασε τα νέα audience stats.</h2>' .
                '<p style="margin:0;color:#8f8f96;font:400 13px/1.65 Arial,sans-serif;">Μέχρι να καταχωρηθούν τα νέα στοιχεία, οι DJs συνεχίζουν να βλέπουν τον τελευταίο ολοκληρωμένο μήνα στο MyLive. Μόλις αποθηκευτεί το νέο audience, τα estimates ενημερώνονται αυτόματα.</p>' .
                '<a href="https://deseoradio.com/iluma/audience.php" style="display:inline-block;margin-top:17px;padding:12px 18px;border-radius:999px;background:#ff2b36;color:#080808;text-decoration:none;font:800 10px Arial,sans-serif;letter-spacing:.05em;">OPEN AUDIENCE IN ILUMA CMS</a>' .
            '</div>' .
        '</td></tr>' .

        '<tr><td style="padding:20px 32px;border-top:1px solid #252529;color:#55555c;font:400 10px/1.6 Arial,sans-serif;">Private operational report · Deseo Radio / ILUMA Digital Agency · Recipient restricted to greg@iluma.gr</td></tr>' .
        '</table></td></tr></table></body></html>';

    $text =
        "Deseo Radio · Monthly Audience Report · {$monthLabel}\n\n" .
        "Monthly listeners: {$listenerText}\n" .
        "Active MyLive DJs: " . (int)$report['active_djs'] . "\n" .
        "DJ sets uploaded: " . (int)$report['total_sets'] . "\n" .
        "Assets published: " . (int)$report['total_assets'] . "\n" .
        "Stats visible: " . (int)$report['visible_djs'] . "/" . (int)$report['active_djs'] . "\n" .
        "Strongest estimated reach: {$strongestText}\n\n" .
        "Next action: πέρασε τα νέα audience stats για {$newMonthLabel}.\n" .
        "https://deseoradio.com/iluma/audience.php";

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}

function deseo_audience_maybe_send_monthly_report(PDO $pdo): array {
    deseo_audience_bootstrap($pdo);

    $lock = (int)$pdo->query("SELECT GET_LOCK('deseo_audience_monthly_report', 5)")->fetchColumn();
    if ($lock !== 1) {
        return ['status' => 'locked'];
    }

    try {
        $currentMonth = deseo_audience_current_month_key();

        $stmt = $pdo->query(
            "SELECT last_seen_month, last_report_month
             FROM deseo_audience_report_state
             WHERE id = 1
             LIMIT 1"
        );
        $state = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'last_seen_month' => '',
            'last_report_month' => '',
        ];

        $lastSeen = trim((string)$state['last_seen_month']);
        if ($lastSeen === '') {
            $stmt = $pdo->prepare(
                "UPDATE deseo_audience_report_state
                 SET last_seen_month = ?
                 WHERE id = 1"
            );
            $stmt->execute([$currentMonth]);
            return ['status' => 'initialized', 'month' => $currentMonth];
        }

        if ($lastSeen === $currentMonth) {
            return ['status' => 'not_due', 'month' => $currentMonth];
        }

        $reportMonth = deseo_audience_previous_month_key($currentMonth);
        if ($reportMonth === '') {
            return ['status' => 'invalid_month'];
        }

        if ((string)$state['last_report_month'] === $reportMonth) {
            $stmt = $pdo->prepare(
                "UPDATE deseo_audience_report_state
                 SET last_seen_month = ?
                 WHERE id = 1"
            );
            $stmt->execute([$currentMonth]);
            return ['status' => 'already_sent', 'month' => $reportMonth];
        }

        $report = deseo_audience_report_data($pdo, $reportMonth);
        $mail = deseo_audience_report_email($report, $currentMonth);

        deseo_send_smtp_mail(
            DESEO_AUDIENCE_REPORT_RECIPIENT,
            DESEO_AUDIENCE_REPORT_RECIPIENT_NAME,
            (string)$mail['subject'],
            (string)$mail['html'],
            (string)$mail['text']
        );

        $stmt = $pdo->prepare(
            "UPDATE deseo_audience_report_state
             SET last_seen_month = ?,
                 last_report_month = ?,
                 last_report_sent_at = NOW()
             WHERE id = 1"
        );
        $stmt->execute([$currentMonth, $reportMonth]);

        return [
            'status' => 'sent',
            'month' => $reportMonth,
            'recipient' => DESEO_AUDIENCE_REPORT_RECIPIENT,
        ];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('deseo_audience_monthly_report')");
    }
}
