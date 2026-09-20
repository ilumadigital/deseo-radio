<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function deseo_env(string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false ? $default : trim((string)$value);
}

function deseo_mail_encode_header(string $value): string {
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function deseo_smtp_read($socket): string {
    $response = '';
    while (($line = fgets($socket, 4096)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function deseo_smtp_expect($socket, array $codes): string {
    $response = deseo_smtp_read($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP server rejected the request (code ' . $code . ').');
    }
    return $response;
}

function deseo_smtp_command($socket, string $command, array $codes): string {
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('Could not write to SMTP server.');
    }
    return deseo_smtp_expect($socket, $codes);
}

function deseo_send_smtp_mail(string $toEmail, string $toName, string $subject, string $html, string $text = '', bool $important = false): void {
    $host = deseo_env('SMTP_HOST');
    $port = (int)deseo_env('SMTP_PORT', '465');
    $username = deseo_env('SMTP_USERNAME');
    $password = deseo_env('SMTP_PASSWORD');
    $secure = strtolower(deseo_env('SMTP_SECURE', 'ssl'));
    $fromEmail = deseo_env('SMTP_FROM_EMAIL', $username);
    $fromName = deseo_env('SMTP_FROM_NAME', 'Deseo Radio');
    $replyTo = deseo_env('SMTP_REPLY_TO', $fromEmail);

    $missing = [];
    if ($host === '') $missing[] = 'SMTP_HOST';
    if ($port < 1) $missing[] = 'SMTP_PORT';
    if ($username === '') $missing[] = 'SMTP_USERNAME';
    if ($password === '') $missing[] = 'SMTP_PASSWORD';
    if ($fromEmail === '') $missing[] = 'SMTP_FROM_EMAIL';

    if ($missing) {
        throw new RuntimeException('SMTP configuration is incomplete. Missing: ' . implode(', ', $missing) . '.');
    }

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email address.');
    }

    $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        $safeDetail = trim((string)$errstr);
        throw new RuntimeException(
            'Could not connect to SMTP server'
            . ($safeDetail !== '' ? ': ' . $safeDetail : '')
            . ($errno > 0 ? ' (code ' . $errno . ')' : '')
            . '.'
        );
    }

    stream_set_timeout($socket, 15);

    try {
        deseo_smtp_expect($socket, [220]);

        $serverName = $_SERVER['SERVER_NAME'] ?? 'deseoradio.com';
        deseo_smtp_command($socket, 'EHLO ' . preg_replace('/[^a-zA-Z0-9.-]/', '', $serverName), [250]);

        if ($secure === 'tls') {
            deseo_smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not enable SMTP TLS.');
            }
            deseo_smtp_command($socket, 'EHLO ' . preg_replace('/[^a-zA-Z0-9.-]/', '', $serverName), [250]);
        }

        deseo_smtp_command($socket, 'AUTH LOGIN', [334]);
        deseo_smtp_command($socket, base64_encode($username), [334]);
        deseo_smtp_command($socket, base64_encode($password), [235]);

        deseo_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        deseo_smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        deseo_smtp_command($socket, 'DATA', [354]);

        $boundary = 'deseo_' . bin2hex(random_bytes(12));
        $fromDomain = substr(strrchr($fromEmail, '@') ?: '@iluma.gr', 1) ?: 'iluma.gr';
        $fromDomain = preg_replace('/[^a-zA-Z0-9.-]/', '', $fromDomain) ?: 'iluma.gr';
        $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $fromDomain . '>';
        $plain = $text !== '' ? $text : trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: ' . $messageId,
            'From: ' . deseo_mail_encode_header($fromName) . ' <' . $fromEmail . '>',
            'To: ' . deseo_mail_encode_header($toName !== '' ? $toName : $toEmail) . ' <' . $toEmail . '>',
            'Reply-To: ' . $replyTo,
            'Subject: ' . deseo_mail_encode_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Deseo Radio Season 6',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($plain), 76, "\r\n") . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html), 76, "\r\n") . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        if (fwrite($socket, $body . "\r\n.\r\n") === false) {
            throw new RuntimeException('Could not send SMTP message data.');
        }
        deseo_smtp_expect($socket, [250]);
        deseo_smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function deseo_dj_mail_context(array $booking, bool $preferFinalSchedule = true): array {
    $setTypeMap = [
        'new' => 'Νέο DJ set',
        'previous' => 'Παλαιότερο / ήδη ηχογραφημένο set',
        'exclusive' => 'Set ειδικά για το Deseo Radio',
    ];

    $requestedDay = (int)($booking['day_of_week'] ?? 0);
    $requestedStart = (string)($booking['start_time'] ?? '');
    $requestedEnd = (string)($booking['end_time'] ?? '');

    $dayValue = $requestedDay;
    $startValue = $requestedStart;
    $endValue = $requestedEnd;

    if ($preferFinalSchedule && !empty($booking['final_day_of_week']) && !empty($booking['final_start_time']) && !empty($booking['final_end_time'])) {
        $dayValue = (int)$booking['final_day_of_week'];
        $startValue = (string)$booking['final_start_time'];
        $endValue = (string)$booking['final_end_time'];
    }

    $day = dj_season_day_label($dayValue);
    $start = dj_season_format_time($startValue);
    $end = dj_season_format_time($endValue);

    return [
        'artist' => trim((string)($booking['artist_name'] ?? 'DJ')),
        'full_name' => trim((string)($booking['full_name'] ?? '')),
        'email' => trim((string)($booking['email'] ?? '')),
        'instagram' => trim((string)($booking['instagram'] ?? '')),
        'website' => trim((string)($booking['website'] ?? '')),
        'bio' => trim((string)($booking['bio'] ?? '')),
        'work_sample' => trim((string)($booking['work_sample_url'] ?? '')),
        'set_type' => $setTypeMap[(string)($booking['set_type'] ?? '')] ?? (string)($booking['set_type'] ?? ''),
        'day' => $day,
        'start' => $start,
        'end' => $end,
        'slot' => trim($day . ' · ' . $start . '–' . $end, " ·–"),
    ];
}

function deseo_dj_email_rows(array $rows): string {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $html = '';

    foreach ($rows as $label => $value) {
        $value = trim((string)$value);
        if ($value === '') continue;

        $html .= '<tr>' .
            '<td style="padding:11px 0;color:#707078;font:700 10px Arial,sans-serif;text-transform:uppercase;letter-spacing:.08em;border-bottom:1px solid #202024;width:34%;vertical-align:top;">' . $e($label) . '</td>' .
            '<td style="padding:11px 0;color:#f5f5f7;font:400 14px/1.55 Arial,sans-serif;border-bottom:1px solid #202024;vertical-align:top;">' . nl2br($e($value)) . '</td>' .
            '</tr>';
    }

    return $html;
}

function deseo_dj_email_frame(
    string $eyebrow,
    string $title,
    string $intro,
    string $highlightLabel,
    string $highlightValue,
    array $rows,
    string $nextTitle,
    string $nextText
): string {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

    $highlight = '';
    if ($highlightValue !== '') {
        $highlight = '<div style="padding:18px 20px;border-radius:18px;background:#ff2b36;color:#090909;margin-bottom:24px;">' .
            '<div style="font:800 10px Arial,sans-serif;letter-spacing:.14em;text-transform:uppercase;">' . $e($highlightLabel) . '</div>' .
            '<div style="margin-top:6px;font:800 25px/1.2 Arial,sans-serif;">' . $e($highlightValue) . '</div>' .
            '</div>';
    }

    return '<!doctype html><html><body style="margin:0;padding:0;background:#050505;color:#fff;">' .
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#050505;padding:28px 12px;"><tr><td align="center">' .
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#0d0d0f;border:1px solid #252529;border-radius:24px;overflow:hidden;">' .
        '<tr><td style="padding:30px;background:#101012;border-bottom:1px solid #252529;">' .
        '<img src="https://deseoradio.com/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="190" style="display:block;max-width:190px;height:auto;margin:0 0 26px;">' .
        '<div style="font:800 10px Arial,sans-serif;color:#ff2b36;letter-spacing:.16em;text-transform:uppercase;">' . $e($eyebrow) . '</div>' .
        '<h1 style="margin:10px 0 12px;font:800 34px/1.05 Arial,sans-serif;color:#fff;">' . $e($title) . '</h1>' .
        '<p style="margin:0;color:#aaaab0;font:400 15px/1.65 Arial,sans-serif;">' . $e($intro) . '</p>' .
        '</td></tr>' .
        '<tr><td style="padding:28px 30px;">' .
        $highlight .
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0">' . deseo_dj_email_rows($rows) . '</table>' .
        '<div style="margin-top:26px;padding:18px;border:1px solid #252529;border-radius:16px;background:#09090b;">' .
        '<div style="color:#fff;font:800 14px Arial,sans-serif;">' . $e($nextTitle) . '</div>' .
        '<div style="margin:7px 0 0;color:#85858b;font:400 13px/1.65 Arial,sans-serif;">' . nl2br($e($nextText)) . '</div>' .
        '</div>' .
        '<p style="margin:24px 0 0;color:#5f5f65;font:400 11px/1.6 Arial,sans-serif;">Deseo Radio · An ILUMA Digital Agency project<br>radio@iluma.gr · deseoradio.com</p>' .
        '</td></tr></table></td></tr></table></body></html>';
}

function deseo_dj_submission_email(array $booking): array {
    $c = deseo_dj_mail_context($booking, false);
    $subject = 'Deseo Radio Season 6 · Λάβαμε την αίτησή σου · ' . $c['artist'];

    $html = deseo_dj_email_frame(
        'Season 6 · Inquiry received',
        'Λάβαμε την αίτησή σου.',
        $c['artist'] . ', το inquiry σου για τη Season 6 του Deseo Radio καταχωρήθηκε επιτυχώς.',
        'Preferred slot',
        $c['slot'],
        [
            'Artist name' => $c['artist'],
            'Ονοματεπώνυμο' => $c['full_name'],
            'Email' => $c['email'],
            'Τύπος set' => $c['set_type'],
            'Work sample' => $c['work_sample'],
        ],
        'Πότε θα έχεις απάντηση',
        'Η ομάδα του Deseo Radio θα αξιολογήσει τις αιτήσεις και θα σε ενημερώσει μέσω email έως τις ' . DESEO_DJ_DECISION_DEADLINE . '.'
    );

    $text = "DESEO RADIO · SEASON 6\n\n" .
        "Λάβαμε την αίτησή σου.\n\n" .
        "Artist name: {$c['artist']}\n" .
        "Ονοματεπώνυμο: {$c['full_name']}\n" .
        "Email: {$c['email']}\n" .
        "Preferred slot: {$c['slot']}\n" .
        "Τύπος set: {$c['set_type']}\n" .
        "Work sample: {$c['work_sample']}\n\n" .
        "Θα ενημερωθείς μέσω email έως τις " . DESEO_DJ_DECISION_DEADLINE . ".\n\n" .
        "Deseo Radio · radio@iluma.gr";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}


function deseo_dj_admin_submission_email(array $booking): array {
    $c = deseo_dj_mail_context($booking, false);
    $subject = 'NEW DJ APPLICATION · Season 6 · ' . $c['artist'];

    $html = deseo_dj_email_frame(
        'Season 6 · New DJ application',
        'Νέα αίτηση DJ.',
        'Μόλις καταχωρήθηκε νέα αίτηση για τη Season 6 του Deseo Radio.',
        'Requested slot',
        $c['slot'],
        [
            'Artist name' => $c['artist'],
            'Ονοματεπώνυμο' => $c['full_name'],
            'Email' => $c['email'],
            'Instagram / Social' => $c['instagram'],
            'Website / SoundCloud / Mixcloud' => $c['website'],
            'Τύπος set' => $c['set_type'],
            'Work sample' => $c['work_sample'],
            'Bio' => $c['bio'],
        ],
        'CMS',
        'Άνοιξε το DJ Season CMS για να αξιολογήσεις την αίτηση: https://deseoradio.com/iluma/dj-season.php'
    );

    $text = "DESEO RADIO · SEASON 6\n\n" .
        "Νέα αίτηση DJ.\n\n" .
        "Artist name: {$c['artist']}\n" .
        "Ονοματεπώνυμο: {$c['full_name']}\n" .
        "Email: {$c['email']}\n" .
        "Requested slot: {$c['slot']}\n" .
        "Τύπος set: {$c['set_type']}\n" .
        "Work sample: {$c['work_sample']}\n" .
        "Instagram / Social: {$c['instagram']}\n" .
        "Website: {$c['website']}\n\n" .
        "Bio:\n{$c['bio']}\n\n" .
        "CMS: https://deseoradio.com/iluma/dj-season.php\n\n" .
        "Deseo Radio · radio@iluma.gr";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

function deseo_dj_approval_email(array $booking): array {
    $c = deseo_dj_mail_context($booking);
    $subject = 'Deseo Radio Season 6 · Welcome · ' . $c['artist'] . ' · ' . $c['day'] . ' ' . $c['start'];

    $html = deseo_dj_email_frame(
        'Season 6 · Approved',
        'Καλώς ήρθες στο Deseo Radio.',
        $c['artist'] . ', σε επιλέξαμε για το σταθερό πρόγραμμα της Season 6.',
        'Your weekly slot',
        $c['slot'],
        [
            'Artist name' => $c['artist'],
            'Ονοματεπώνυμο' => $c['full_name'],
            'Email' => $c['email'],
            'Τύπος set' => $c['set_type'],
        ],
        'Επόμενα βήματα',
        "Η συμμετοχή σου στη Season 6 έχει επιβεβαιωθεί. Από εδώ και πέρα, η διαχείριση όλου του υλικού του show σου θα γίνεται αποκλειστικά μέσα από το MyLive, τον προσωπικό σου χώρο στο Deseo Radio.\n\n"
        . "Από εκεί θα ανεβάζεις τα DJ Sets σου, θα κατεβάζεις artwork, προσωπικό imaging και promotional assets και θα βλέπεις συγκεντρωμένα τα βασικά στοιχεία και τα διαθέσιμα στατιστικά του show σου.\n\n"
        . "Σε δεύτερο ξεχωριστό email θα λάβεις τους προσωπικούς σου κωδικούς πρόσβασης, το temporary password και αναλυτικές οδηγίες για το πώς χρησιμοποιείται το MyLive."
    );

    $text = "DESEO RADIO · SEASON 6\n\n" .
        "Καλώς ήρθες στο Deseo Radio.\n" .
        "Σε επιλέξαμε για το σταθερό πρόγραμμα της Season 6.\n\n" .
        "Ημέρα / ώρα: {$c['slot']}\n" .
        "Artist name: {$c['artist']}\n\n" .
        "Η συμμετοχή σου έχει επιβεβαιωθεί.\n" .
        "Από εδώ και πέρα, η διαχείριση όλου του υλικού του show σου θα γίνεται αποκλειστικά μέσα από το MyLive. Εκεί θα ανεβάζεις DJ Sets, θα κατεβάζεις artwork, imaging και promotional assets και θα βλέπεις συγκεντρωμένα τα βασικά στοιχεία και τα διαθέσιμα στατιστικά του show σου.\n\n" .
        "Σε δεύτερο ξεχωριστό email θα λάβεις τους προσωπικούς σου κωδικούς πρόσβασης, το temporary password και αναλυτικές οδηγίες χρήσης του MyLive.\n\n" .
        "Deseo Radio · radio@iluma.gr";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

function deseo_dj_guest_email(array $booking): array {
    $c = deseo_dj_mail_context($booking);
    $subject = 'Deseo Radio Season 6 · Guest DJ · ' . $c['artist'];

    $html = deseo_dj_email_frame(
        'Season 6 · Guest DJ',
        'Καλώς ήρθες στο Deseo Radio.',
        $c['artist'] . ', επιλέχθηκες ως Guest DJ στο πρόγραμμα του Deseo Radio.',
        'Selection',
        'Guest DJ · Deseo Radio Season 6',
        [
            'Artist name' => $c['artist'],
            'Ονοματεπώνυμο' => $c['full_name'],
            'Email' => $c['email'],
            'Αρχική προτίμηση slot' => $c['slot'],
        ],
        'Επόμενα βήματα',
        'Θα επικοινωνήσουμε μαζί σου μέσω email για την ημερομηνία της guest εμφάνισης, την ώρα μετάδοσης και όλες τις τεχνικές λεπτομέρειες.'
    );

    $text = "DESEO RADIO · SEASON 6\n\n" .
        "Καλώς ήρθες στο Deseo Radio.\n" .
        "Επιλέχθηκες ως Guest DJ στο πρόγραμμα του Deseo Radio.\n\n" .
        "Artist name: {$c['artist']}\n\n" .
        "Θα σε ενημερώσουμε για τα επόμενα βήματα μέσω email.\n\n" .
        "Deseo Radio · radio@iluma.gr";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

function deseo_dj_rejected_email(array $booking): array {
    $c = deseo_dj_mail_context($booking);
    $subject = 'Deseo Radio Season 6 · Update · ' . $c['artist'];

    $html = deseo_dj_email_frame(
        'Season 6 · Application update',
        'Ευχαριστούμε για την αίτησή σου.',
        $c['artist'] . ', ολοκληρώσαμε την επιλογή για τα σταθερά slots της Season 6.',
        'Season 6 status',
        'Το πρόγραμμα καλύφθηκε',
        [
            'Artist name' => $c['artist'],
            'Ονοματεπώνυμο' => $c['full_name'],
            'Email' => $c['email'],
        ],
        'Μείνε σε επαφή',
        'Δεν επιλέχθηκες για σταθερό slot στη συγκεκριμένη φάση. Θα σε ενημερώσουμε εκ νέου μέσω email αν προκύψει δυνατότητα για Guest DJ εμφάνιση στο Deseo Radio.'
    );

    $text = "DESEO RADIO · SEASON 6\n\n" .
        "Ευχαριστούμε για την αίτησή σου.\n" .
        "Το πρόγραμμα της Season 6 έχει καλυφθεί και δεν επιλέχθηκες για σταθερό slot στη συγκεκριμένη φάση.\n\n" .
        "Θα σε ενημερώσουμε εκ νέου μέσω email αν προκύψει δυνατότητα για Guest DJ εμφάνιση στο Deseo Radio.\n\n" .
        "Deseo Radio · radio@iluma.gr";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}


function deseo_mylive_email_section(string $number, string $title, string $html): string {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    return '<tr><td style="padding:0 0 22px;">'
        . '<div style="color:#ff2b36;font:800 11px Arial,sans-serif;letter-spacing:.13em;text-transform:uppercase;margin-bottom:8px;">' . $e($number) . '</div>'
        . '<div style="color:#ffffff;font:800 20px/1.28 Arial,sans-serif;margin-bottom:10px;">' . $e($title) . '</div>'
        . '<div style="color:#b5b5bb;font:400 14px/1.76 Arial,sans-serif;">' . $html . '</div>'
        . '</td></tr>';
}

function deseo_mylive_email_shell(
    string $eyebrow,
    string $title,
    string $intro,
    string $body,
    string $ctaLabel = '',
    string $ctaUrl = ''
): string {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $cta = '';
    if ($ctaLabel !== '' && $ctaUrl !== '') {
        $cta = '<tr><td style="padding:4px 30px 30px;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="border-radius:999px;background:#ff2b36;">'
            . '<a href="' . $e($ctaUrl) . '" style="display:inline-block;padding:15px 24px;color:#080808;text-decoration:none;font:800 11px Arial,sans-serif;letter-spacing:.08em;text-transform:uppercase;">' . $e($ctaLabel) . '</a>'
            . '</td></tr></table></td></tr>';
    }

    return '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>@media only screen and (max-width:620px){.d-wrap{padding:12px!important}.d-card{border-radius:18px!important}.d-head,.d-body{padding:25px 20px!important}.d-title{font-size:32px!important;line-height:1.06!important}.d-logo{width:156px!important}.d-intro{font-size:16px!important;line-height:1.72!important}.d-eyebrow{font-size:11px!important}.d-credentials td{display:block!important;width:100%!important;padding:9px 0!important}.d-credential-value{font-size:18px!important;word-break:break-word!important}}</style>'
        . '</head><body style="margin:0;padding:0;background:#050505;color:#ffffff;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="d-wrap" style="width:100%;background:#050505;padding:30px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="d-card" style="width:100%;max-width:690px;background:#0c0c0e;border:1px solid #252529;border-radius:26px;overflow:hidden;">'
        . '<tr><td class="d-head" style="padding:32px 30px 28px;background:linear-gradient(145deg,#121214,#0b0b0d);border-bottom:1px solid #252529;">'
        . '<img class="d-logo" src="https://deseoradio.com/assets/img/deseoradio-logo.png" width="184" alt="Deseo Radio" style="display:block;width:184px;max-width:100%;height:auto;margin:0 0 27px;">'
        . '<div class="d-eyebrow" style="margin-top:4px;color:#ff2b36;font:800 11px Arial,sans-serif;letter-spacing:.15em;text-transform:uppercase;">' . $e($eyebrow) . '</div>'
        . '<h1 class="d-title" style="margin:11px 0 15px;color:#fff;font:800 40px/1.05 Arial,sans-serif;letter-spacing:-.035em;">' . $e($title) . '</h1>'
        . '<p class="d-intro" style="margin:0;max-width:590px;color:#b4b4ba;font:400 17px/1.72 Arial,sans-serif;">' . $e($intro) . '</p>'
        . '</td></tr>'
        . '<tr><td class="d-body" style="padding:30px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0">' . $body . '</table>'
        . '<div style="margin-top:8px;padding-top:22px;border-top:1px solid #242428;color:#5f5f66;font:400 10px/1.7 Arial,sans-serif;">'
        . 'Deseo Radio · Season 6<br>Powered by <strong style="color:#8d8d94;">ILUMA Digital Agency</strong><br>radio@iluma.gr · deseoradio.com'
        . '</div></td></tr>'
        . $cta
        . '</table></td></tr></table></body></html>';
}

function deseo_mylive_technical_email(array $account): array {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $artist = trim((string)($account['artist_name'] ?? 'DJ'));
    $day = deseo_mylive_day_label(isset($account['day_of_week']) ? (int)$account['day_of_week'] : null);
    $start = deseo_mylive_format_time((string)($account['start_time'] ?? ''));
    $slot = ($day === '—' && $start === '—') ? 'Θα ανακοινωθεί' : trim($day . ' · ' . $start, ' ·');

    $body = '<tr><td style="padding:0 0 26px;">'
        . '<div style="padding:20px;border-radius:19px;background:#ff2b36;color:#080808;">'
        . '<div style="font:800 9px Arial,sans-serif;letter-spacing:.13em;text-transform:uppercase;">YOUR WEEKLY SLOT</div>'
        . '<div style="margin-top:6px;font:800 24px/1.2 Arial,sans-serif;">' . $e($slot) . '</div>'
        . '</div></td></tr>';

    $body .= deseo_mylive_email_section('01', 'Διάρκεια DJ Set',
        'Το προγραμματισμένο slot είναι ωριαίο. Ιδανικά το set διαρκεί περίπου <strong style="color:#fff;">58–59 λεπτά</strong>. Αν είναι μεγαλύτερο, δεν απαιτείται υποχρεωτικό manual edit: το Airtime του Deseo Radio μπορεί να διαχειριστεί το τέλος όταν έρθει η ώρα για το επόμενο Imaging Spot. Αν γίνει χειροκίνητη περικοπή, επίλεξε φυσικό μουσικό σημείο και ολοκλήρωσε με <strong style="color:#fff;">8-second Fade Out</strong>.');

    $body .= deseo_mylive_email_section('02', 'Personal Deseo Radio Imaging',
        'Το Deseo Radio σε συνεργασία με την <strong style="color:#fff;">ILUMA Digital Agency</strong> δημιουργεί προσωπικό branded radio imaging με το DJ / Artist Name σου. Τα αρχεία θα εμφανιστούν στο <strong style="color:#fff;">MyLive</strong>, από όπου θα μπορείς να τα κατεβάσεις. Δεν πρέπει να τροποποιούνται, να κόβονται ή να αλλάζουν ηχητικά χωρίς προηγούμενη συνεννόηση.');

    $body .= deseo_mylive_email_section('03', 'Exclusive DJ Sets',
        'Για set που δημιουργείται ειδικά για το Deseo Radio, το βασικό Personal DJ Spot μπαίνει στην αρχή, ιδανικά πάνω σε intro ή καθαρό instrumental σημείο. Το αρχείο με ένδειξη <strong style="color:#fff;">_30</strong> τοποθετείται περίπου στο 30ό λεπτό, σε breakdown / intro / outro ή άλλο σημείο όπου η εκφώνηση ακούγεται καθαρά. Δεν απαιτείται ακρίβεια δευτερολέπτου.');

    $body .= deseo_mylive_email_section('04', 'Ήδη ηχογραφημένα Sets',
        'Μπορεί να χρησιμοποιηθεί ήδη ηχογραφημένο ή δημοσιευμένο set, εφόσον είναι κατάλληλο για radio broadcast. Δεν πρέπει να περιλαμβάνει station IDs άλλου σταθμού, μη εγκεκριμένες διαφημίσεις, commercial spots τρίτων, μεγάλα silent σημεία ή εμφανή τεχνικά προβλήματα. Αν γίνει manual cut, ισχύει <strong style="color:#fff;">8-second fade out</strong>.');

    $body .= deseo_mylive_email_section('05', 'Episodes & Filename',
        'Κάθε νέο set αριθμείται <strong style="color:#fff;">EP001, EP002, EP003…</strong>. Το MyLive αναλαμβάνει αυτόματα και την αρίθμηση και το filename, με format <strong style="color:#fff;">ARTISTNAME_DESEO_S06_EP001.mp3</strong>. Δεν χρειάζεται να μετονομάσεις το αρχείο πριν το upload.');

    $body .= deseo_mylive_email_section('06', 'Audio Format',
        'Το DJ Set πρέπει να παραδίδεται σε <strong style="color:#fff;">MP3 · 192 kbps · Stereo</strong>. Το αρχείο πρέπει να έχει καθαρή, συνεπή στάθμη χωρίς clipping ή εμφανές distortion.');

    $body .= deseo_mylive_email_section('07', 'Μουσική ταυτότητα',
        'Το set πρέπει να εκφράζει το προσωπικό σου sound και να παραμένει συμβατό με τη φιλοσοφία του Deseo Radio: <strong style="color:#fff;">House · Afro House · Organic House · Deep House · Melodic House · Electronica · sophisticated electronic sound</strong>. Δεν χρησιμοποιούνται εν γνώσει σου leaked, παράνομα ή μη εξουσιοδοτημένα recordings. Ισχύουν επίσης οι όροι της Season 6 για δικαιώματα και AI-generated musical works / recordings.');

    $body .= deseo_mylive_email_section('08', 'Personal Promotional Artwork',
        'Το γραφιστικό τμήμα της <strong style="color:#fff;">ILUMA Digital Agency</strong> δημιουργεί προσωπικό branded artwork με Artist Name, ημέρα, ώρα και Deseo Radio branding. Το artwork, τα Personal DJ Spots, το <strong style="color:#fff;">_30 Imaging</strong> και κάθε πρόσθετο asset θα παραδίδονται μέσα από το MyLive.');

    $body .= deseo_mylive_email_section('09', 'Social Media Announcement',
        'Η δημοσίευση του προσωπικού artwork αποτελεί μέρος της συμμετοχής στη Season 6. Το Deseo Radio θα ανακοινώνει και θα προωθεί τη συμμετοχή σου στα επίσημα digital channels και, ανάλογα με το promotional plan, στα κανάλια της <strong style="color:#fff;">ILUMA Digital Agency</strong> / ILUMA Radios. Αντίστοιχα, ανακοινώνεις το show και το slot σου στα προσωπικά social media. Ιδανικά χρησιμοποιούμε <strong style="color:#fff;">Collaborator Post</strong>. Όταν λαμβάνεις collaboration request από το Deseo Radio, το αποδέχεσαι ή κάνεις repost / mention του σταθμού.');

    $body .= deseo_mylive_email_section('10', 'Promotional Support',
        'Η συμμετοχή μπορεί να υποστηρίζεται με official social media, website παρουσίαση, stories / reposts, newsletter της ILUMA Digital Agency, επιλεγμένες sponsored promotions, branded posters / covers και editorial παρουσίαση. Οι ενέργειες εξαρτώνται από το εκάστοτε editorial και media plan και δεν αποτελούν εγγύηση συγκεκριμένου αριθμού listeners, impressions, clicks ή followers.');

    $body .= deseo_mylive_email_section('11', 'Χορηγοί / Brand Partners',
        'Αν το show έχει προσωπικό χορηγό ή brand partner, ενημέρωσε το Deseo Radio <strong style="color:#fff;">πριν από την παράδοση</strong>. Sponsor ID, commercial spot, promo code, paid mention ή branded message χρειάζεται προηγούμενη έγκριση από Deseo Radio / ILUMA Digital Agency. Για ωριαίο show προτείνονται έως <strong style="color:#fff;">1–2 σύντομες sponsor αναφορές</strong>, κατόπιν έγκρισης.');

    $body .= deseo_mylive_email_section('12', 'Ενδεικτική δομή Exclusive Show',
        '<strong style="color:#fff;">00:00</strong> Personal Deseo DJ Imaging<br>'
        . '<strong style="color:#fff;">~15:00</strong> Optional approved Sponsor ID<br>'
        . '<strong style="color:#fff;">~30:00</strong> Deseo _30 Imaging<br>'
        . '<strong style="color:#fff;">~45:00</strong> Optional δεύτερο Sponsor ID<br>'
        . '<strong style="color:#fff;">58:00–59:00</strong> Natural Outro ή 8-second Fade Out<br><br>'
        . 'Οι χρόνοι είναι ενδεικτικοί. Προτεραιότητα έχει η σωστή μουσική ροή.');

    $body .= deseo_mylive_email_section('13', 'Τελικός έλεγχος',
        'Πριν από κάθε upload έλεγξε ότι πρόκειται για το final on-air master, δεν υπάρχουν IDs άλλων stations ή μη εγκεκριμένα commercial messages, δεν υπάρχουν μεγάλα κενά ή distortion, τα Deseo Imaging Spots έχουν τοποθετηθεί σωστά όπου απαιτείται και κάθε manual cut ολοκληρώνεται με 8-second fade out.');

    $body .= deseo_mylive_email_section('14', 'MyLive · το κεντρικό σου workspace',
        'Μέσα από το MyLive <strong style="color:#fff;">ανεβάζεις τα DJ Sets σου</strong> και <strong style="color:#fff;">κατεβάζεις το προσωπικό artwork, τα branded DJ Spots, το _30 Imaging και κάθε νέο promotional asset</strong> που προσθέτει η ομάδα του Deseo Radio / ILUMA Digital Agency. Δεν χρειάζεται WeTransfer, Drive link ή μεγάλα email attachments.');

    $body .= '<tr><td style="padding:2px 0 0;"><div style="padding:18px;border:1px solid #3f161b;border-radius:17px;background:#160b0d;color:#d8b4b7;font:400 12px/1.65 Arial,sans-serif;">'
        . '<strong style="display:block;margin-bottom:5px;color:#ff4650;">NEXT EMAIL · MYLIVE ACCESS</strong>'
        . 'Σε ξεχωριστό email θα λάβεις το MyLive URL, το email πρόσβασης και προσωρινό password. Στην πρώτη είσοδο θα πρέπει υποχρεωτικά να δημιουργήσεις δικό σου password.'
        . '</div></td></tr>';

    $subject = 'Deseo Radio Season 6 · Οδηγίες συμμετοχής & DJ Set Delivery';
    $html = deseo_mylive_email_shell(
        'DESEO RADIO · SEASON 6',
        'Οι οδηγίες για το show σου.',
        $artist . ', καλώς ήρθες στη Season 6. Κράτησε αυτό το email ως το βασικό reference για τα DJ Sets, το imaging, τα promotional assets και τη συμμετοχή σου.',
        $body
    );

    $text = "DESEO RADIO · SEASON 6\n\n"
        . "Artist: {$artist}\nSlot: {$slot}\n\n"
        . "Ιδανική διάρκεια set: 58–59 λεπτά. Σε manual περικοπή: 8-second fade out.\n"
        . "Exclusive set: Personal DJ Spot στην αρχή και _30 Imaging περίπου στο 30ό λεπτό.\n"
        . "Naming: ARTISTNAME_DESEO_S06_EP001 — το MyLive το δημιουργεί αυτόματα.\n"
        . "Format: MP3 192 kbps Stereo.\n"
        . "Artwork και branded DJ spots παραδίδονται μέσα από το MyLive.\n"
        . "Η ανακοίνωση στα προσωπικά social media με mention / collaboration του Deseo Radio αποτελεί μέρος της συμμετοχής.\n"
        . "Sponsor material απαιτεί προηγούμενη έγκριση.\n\n"
        . "Θα ακολουθήσει ξεχωριστό email με τα στοιχεία MyLive.\n\n"
        . "Deseo Radio · Powered by ILUMA Digital Agency";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

function deseo_mylive_access_email(array $account, string $temporaryPassword, bool $reset = false): array {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $artist = trim((string)($account['artist_name'] ?? 'DJ'));
    $email = trim((string)($account['email'] ?? ''));
    $slot = deseo_mylive_slot($account);
    $artistSlug = deseo_mylive_slug($artist);

    $body = '<tr><td style="padding:0 0 25px;">'
        . '<div style="padding:20px;border-radius:19px;background:#ff2b36;color:#080808;">'
        . '<div style="font:800 9px Arial,sans-serif;letter-spacing:.13em;text-transform:uppercase;">MYLIVE ACCESS</div>'
        . '<div style="margin-top:7px;font:800 24px/1.15 Arial,sans-serif;">deseoradio.com/mylive/</div>'
        . '</div></td></tr>';

    $body .= '<tr><td style="padding:0 0 24px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="d-credentials" style="border:1px solid #27272b;border-radius:18px;background:#09090b;">'
        . '<tr><td style="padding:17px 20px;border-bottom:1px solid #222226;color:#66666d;font:800 9px Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase;width:34%;">EMAIL</td>'
        . '<td class="d-credential-value" style="padding:17px 20px;border-bottom:1px solid #222226;color:#fff;font:700 16px Arial,sans-serif;">' . $e($email) . '</td></tr>'
        . '<tr><td style="padding:17px 20px;color:#66666d;font:800 9px Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase;">TEMPORARY PASSWORD</td>'
        . '<td class="d-credential-value" style="padding:17px 20px;color:#fff;font:800 19px Arial,sans-serif;letter-spacing:.04em;">' . $e($temporaryPassword) . '</td></tr>'
        . '</table></td></tr>';

    $body .= deseo_mylive_email_section('FIRST ACCESS', 'Το password είναι προσωρινό',
        'Στην πρώτη είσοδο το MyLive θα σε μεταφέρει υποχρεωτικά σε οθόνη δημιουργίας προσωπικού password. Θα υπάρχει <strong style="color:#fff;">Show / Hide Password</strong> και στα δύο πεδία ώστε να μπορείς να ελέγξεις τι πληκτρολογείς. Μετά την αλλαγή, το temporary password παύει να ισχύει.');

    $body .= deseo_mylive_email_section('01', 'Το weekly slot σου',
        '<strong style="color:#fff;">' . $e($slot) . '</strong>. Το slot εμφανίζεται και μέσα στο προσωπικό dashboard.');

    $body .= deseo_mylive_email_section('02', 'Upload DJ Set',
        'Μπαίνεις στο dashboard, πατάς <strong style="color:#fff;">+ Upload DJ Set</strong>, επιλέγεις το τελικό <strong style="color:#fff;">MP3 · 192 kbps · Stereo</strong> αρχείο και κάνεις Upload. Δεν συμπληρώνεις episode number ή filename. Το MyLive δημιουργεί αυτόματα <strong style="color:#fff;">EP001 → EP002 → EP003</strong> και filenames όπως <strong style="color:#fff;">' . $e($artistSlug) . '_DESEO_S06_EP001.mp3</strong>.');

    $body .= deseo_mylive_email_section('03', 'Τα αρχεία από το Deseo Radio',
        'Στο <strong style="color:#fff;">Your Assets</strong> θα βρίσκεις ό,τι παραδίδει η ομάδα του Deseo Radio / <strong style="color:#fff;">ILUMA Digital Agency</strong>: προσωπικό Instagram / social artwork, Personal DJ Imaging, _30 Imaging και οποιοδήποτε πρόσθετο promotional ή on-air asset.');

    $body .= deseo_mylive_email_section('04', 'Πριν το Upload',
        'Το αρχείο πρέπει να είναι το final on-air master. Για exclusive Deseo set ακολούθησε τις οδηγίες του προηγούμενου email με τις Season 6 οδηγίες: Personal Imaging στην αρχή, _30 περίπου στο μέσο και 8-second fade out όταν έχει γίνει manual περικοπή.');

    $body .= deseo_mylive_email_section('05', 'Privacy & Access',
        'Τα DJ Sets και τα προσωπικά assets δεν είναι δημόσια. Η πρόσβαση παρέχεται μόνο στο προσωπικό σου account και στην εξουσιοδοτημένη ομάδα του Deseo Radio / ILUMA Digital Agency. Μην κοινοποιείς το password σου σε τρίτους.');

    $subject = $reset
        ? 'Deseo Radio MyLive · Νέο προσωρινό password'
        : 'Deseo Radio MyLive · Το account σου είναι έτοιμο';

    $html = deseo_mylive_email_shell(
        'DESEO RADIO · MYLIVE',
        $reset ? 'Νέο MyLive access.' : 'Το MyLive account σου είναι έτοιμο.',
        $reset
            ? $artist . ', δημιουργήσαμε νέο προσωρινό password για το MyLive account σου.'
            : $artist . ', αυτός είναι ο προσωπικός σου χώρος για DJ Set delivery και για όλα τα επίσημα assets της Season 6.',
        $body,
        'OPEN MYLIVE',
        'https://deseoradio.com/mylive/'
    );

    $text = "DESEO RADIO · MYLIVE\n\n"
        . "URL: https://deseoradio.com/mylive/\n"
        . "Email: {$email}\n"
        . "Temporary Password: {$temporaryPassword}\n\n"
        . "Στην πρώτη είσοδο θα πρέπει υποχρεωτικά να δημιουργήσεις προσωπικό password.\n"
        . "Weekly slot: {$slot}\n\n"
        . "Στο MyLive ανεβάζεις τα DJ Sets και κατεβάζεις artwork, Personal DJ Imaging, _30 Imaging και άλλα assets από Deseo Radio / ILUMA Digital Agency.\n\n"
        . "Deseo Radio · Powered by ILUMA Digital Agency";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}



function deseo_mylive_public_profile_enabled_email(array $account): array {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $artist = trim((string)($account['artist_name'] ?? 'DJ'));

    $body = '<tr><td style="padding:0 0 18px;">'
        . '<div style="padding:22px;border-radius:20px;background:#ff2b36;color:#080808;">'
        . '<div style="font:800 9px Arial,sans-serif;letter-spacing:.14em;text-transform:uppercase;">PUBLIC PROFILE · ACTIVE</div>'
        . '<div style="margin-top:7px;font:800 25px/1.15 Arial,sans-serif;">Το profile σου είναι έτοιμο για διαχείριση.</div>'
        . '</div></td></tr>';

    $body .= deseo_mylive_email_section('01', 'Τι μπορείς να διαχειρίζεσαι',
        'Μέσα από το <strong style="color:#fff;">MyLive → Your Public DJ Profile</strong> μπορείς να γράφεις και να ενημερώνεις το προσωπικό σου <strong style="color:#fff;">About</strong> και τα social links σου: Instagram, TikTok, SoundCloud, Spotify και Website.');

    $body .= deseo_mylive_email_section('02', 'Πώς εμφανίζεται στους ακροατές',
        'Όταν το show σου παίζει στον αέρα του Deseo Radio και έχει συνδεθεί με το MyLive profile σου, ο ακροατής μπορεί να <strong style="color:#fff;">πατήσει πάνω στη φωτογραφία σου</strong> στο Now On Air / Radio Program και να ανοίξει το μικρό δημόσιο DJ profile σου.<br><br>'
        . 'Εκεί εμφανίζονται το About που έχεις δημοσιεύσει και τα social media links που έχεις επιλέξει.');

    $body .= deseo_mylive_email_section('03', 'Save Draft & Publish',
        'Το <strong style="color:#fff;">Save Draft</strong> αποθηκεύει τις αλλαγές μόνο μέσα στο MyLive και δεν τις εμφανίζει δημόσια.<br><br>'
        . 'Μόνο όταν πατήσεις <strong style="color:#fff;">Publish Profile</strong> περνάει η νέα έκδοση στο website. Αν κάνεις νέες αλλαγές αργότερα, η προηγούμενη published έκδοση παραμένει live μέχρι να ξαναπατήσεις Publish.');

    $body .= deseo_mylive_email_section('04', 'Η φωτογραφία και το show σου',
        'Δεν χρειάζεται να ανεβάζεις φωτογραφία μέσα από το Public Profile. Η εικόνα, το show title και το on-air slot διαχειρίζονται από το <strong style="color:#fff;">Deseo Radio</strong> μέσα από το επίσημο Radio Program, ώστε η παρουσίαση να παραμένει ενιαία και σωστή.');

    $body .= '<tr><td style="padding:2px 0 0;">'
        . '<div style="padding:18px;border:1px solid #3f161b;border-radius:17px;background:#160b0d;color:#d8b4b7;font:400 12px/1.65 Arial,sans-serif;">'
        . '<strong style="display:block;margin-bottom:5px;color:#ff4650;">TIP</strong>'
        . 'Κράτησε το About σύντομο, προσωπικό και αντιπροσωπευτικό του sound σου. Είναι το κείμενο που θα βλέπει ο listener την ώρα που σε ακούει.'
        . '</div></td></tr>';

    $subject = 'Deseo Radio MyLive · Το Public Profile σου ενεργοποιήθηκε';

    $html = deseo_mylive_email_shell(
        'DESEO RADIO · MYLIVE · PUBLIC PROFILE',
        'Το Public Profile σου ενεργοποιήθηκε.',
        $artist . ', από σήμερα μπορείς να διαχειρίζεσαι μέσα από το MyLive το κείμενο και τα social links που βλέπει ο listener όταν ανοίγει το DJ profile σου στο Deseo Radio.',
        $body,
        'OPEN MYLIVE',
        'https://deseoradio.com/mylive/'
    );

    $text = "DESEO RADIO · MYLIVE · PUBLIC PROFILE\n\n"
        . "{$artist}, το Public Profile σου ενεργοποιήθηκε.\n\n"
        . "Μέσα από το MyLive μπορείς να διαχειρίζεσαι το About σου και τα social links: Instagram, TikTok, SoundCloud, Spotify και Website.\n\n"
        . "Όταν το show σου παίζει στον αέρα και έχει συνδεθεί με το MyLive profile σου, ο ακροατής μπορεί να πατήσει πάνω στη φωτογραφία σου στο Now On Air / Radio Program και να δει το published About και τα social media σου.\n\n"
        . "Save Draft: αποθηκεύει ιδιωτικά τις αλλαγές.\n"
        . "Publish Profile: δημοσιεύει τη νέα έκδοση στο website.\n\n"
        . "Η φωτογραφία, το show title και το on-air slot διαχειρίζονται από το Deseo Radio μέσα από το Radio Program.\n\n"
        . "MyLive: https://deseoradio.com/mylive/\n\n"
        . "Deseo Radio · Powered by ILUMA Digital Agency";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}


function deseo_mylive_onboarding_email(array $account, string $temporaryPassword, bool $reset = false): array {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

    $artist = trim((string)($account['artist_name'] ?? 'DJ'));
    $email = trim((string)($account['email'] ?? ''));
    $slot = deseo_mylive_slot($account);
    $artistSlug = deseo_mylive_slug($artist);

    $section = static function(string $kicker, string $title, string $content) use ($e): string {
        return '<tr><td style="padding:0 0 14px;">'
            . '<div style="padding:20px 20px 19px;border:1px solid #242428;border-radius:18px;background:#101012;">'
            . '<div style="margin-bottom:8px;color:#ff3944;font:800 10px Arial,sans-serif;letter-spacing:.13em;text-transform:uppercase;">' . $e($kicker) . '</div>'
            . '<div style="margin-bottom:10px;color:#fff;font:800 20px/1.28 Arial,sans-serif;">' . $e($title) . '</div>'
            . '<div style="color:#b4b4ba;font:400 14px/1.76 Arial,sans-serif;">' . $content . '</div>'
            . '</div></td></tr>';
    };

    $bullet = static function(string $text): string {
        return '<tr>'
            . '<td width="20" valign="top" style="padding:0 0 9px;color:#ff3944;font:700 15px Arial,sans-serif;">•</td>'
            . '<td valign="top" style="padding:0 0 9px;color:#bdbdc2;font:400 14px/1.62 Arial,sans-serif;">' . $text . '</td>'
            . '</tr>';
    };

    $quick = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0">'
        . $bullet('<strong style="color:#fff;">58–59 λεπτά</strong> ιδανική διάρκεια για το ωριαίο slot.')
        . $bullet('Παράδοση σε <strong style="color:#fff;">MP3 · 192 kbps · Stereo</strong>.')
        . $bullet('Το <strong style="color:#fff;">MyLive αριθμεί και μετονομάζει αυτόματα</strong> κάθε επεισόδιο.')
        . $bullet('Στο dashboard βλέπεις <strong style="color:#fff;">πόσοι σε άκουσαν, πόσα episodes έχεις ανεβάσει και πόσα assets έχεις διαθέσιμα</strong>.')
        . $bullet('Artwork και branded DJ spots κατεβαίνουν από το <strong style="color:#fff;">MyLive</strong>.')
        . $bullet('<strong style="color:#fff;">My Rewards</strong> — έχεις προσωπικό referral link και μπορείς να κερδίζεις <strong style="color:#fff;">20%</strong> από νέα διαφημιστική καμπάνια που κλείνει μέσω της σύστασής σου.')
        . '</table>';

    $body = '<tr><td style="padding:0 0 18px;">'
        . '<div style="padding:22px;border-radius:20px;background:#ff2b36;color:#080808;">'
        . '<div style="font:800 14px/1.45 Arial,sans-serif;letter-spacing:.01em;">Παίζεις στον Deseo κάθε:</div>'
        . '<div style="margin-top:8px;font:800 30px/1.16 Arial,sans-serif;letter-spacing:-.02em;">' . $e($slot) . '</div>'
        . '</div></td></tr>';

    $body .= $section('QUICK GUIDE', 'Τα βασικά με μια ματιά', $quick);

    $dashboard = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0">'
        . $bullet('<strong style="color:#fff;">Σε άκουσαν</strong> — βλέπεις την εκτιμώμενη απήχηση του show σου για τον τελευταίο ολοκληρωμένο μήνα, όταν τα στοιχεία είναι διαθέσιμα.')
        . $bullet('<strong style="color:#fff;">Episodes</strong> — βλέπεις πόσα DJ Sets έχεις ήδη ανεβάσει στο MyLive.')
        . $bullet('<strong style="color:#fff;">Your Assets</strong> — βλέπεις πόσα προσωπικά artwork, audio spots και άλλα αρχεία έχεις διαθέσιμα.')
        . '</table>';

    $body .= $section('YOUR MYLIVE DASHBOARD', 'Όλα για το show σου σε ένα σημείο',
        'Μπαίνοντας στο MyLive θα βλέπεις πλέον μια απλή εικόνα του show σου:<br><br>'
        . $dashboard
        . '<div style="margin-top:7px;color:#77777e;font:400 11px/1.6 Arial,sans-serif;">Τα audience statistics ανανεώνονται όταν υπάρχουν διαθέσιμα στοιχεία για τον ολοκληρωμένο μήνα.</div>'
    );

    $body .= $section('DJ SET DELIVERY', 'Διάρκεια & τελικό αρχείο',
        'Το slot σου είναι ωριαίο και η ιδανική διάρκεια του set είναι περίπου <strong style="color:#fff;">58–59 λεπτά</strong>. '
        . 'Αν το set είναι μεγαλύτερο, δεν χρειάζεται υποχρεωτικά manual edit: το Airtime του Deseo Radio μπορεί να ολοκληρώσει την αναπαραγωγή όταν έρθει η ώρα για το επόμενο προγραμματισμένο Imaging Spot. '
        . 'Αν κάνεις χειροκίνητη περικοπή, επίλεξε φυσικό μουσικό σημείο και ολοκλήρωσε με <strong style="color:#fff;">8-second fade out</strong>.<br><br>'
        . 'Το τελικό DJ Set πρέπει να παραδίδεται σε <strong style="color:#fff;">MP3 · 192 kbps · Stereo</strong>, χωρίς clipping, εμφανές distortion ή μεγάλα ανεπιθύμητα κενά.');

    $body .= $section('MYLIVE AUTOMATION', 'Δεν χρειάζεται να μετονομάζεις τίποτα',
        'Ανεβάζεις απλώς το τελικό MP3 από το κουμπί <strong style="color:#fff;">+ Upload DJ Set</strong>. '
        . 'Το MyLive βρίσκει μόνο του ποιο episode ακολουθεί και αποθηκεύει το set με το σωστό naming format.<br><br>'
        . '<strong style="color:#fff;">EP001 → EP002 → EP003…</strong><br>'
        . '<strong style="color:#fff;">' . $e($artistSlug) . '_DESEO_S06_EP001.mp3</strong><br><br>'
        . 'Δεν χρειάζεται να αλλάξεις μόνος σου filename, να γράψεις episode number ή να στείλεις WeTransfer / Drive link.');

    $body .= $section('DESEO IMAGING', 'Τα προσωπικά branded DJ spots σου',
        'Το Deseo Radio σε συνεργασία με την <strong style="color:#fff;">ILUMA Digital Agency</strong> δημιουργεί το προσωπικό σου branded radio imaging. '
        . 'Θα το βρίσκεις μέσα στο MyLive, μαζί με κάθε άλλο επίσημο asset του show σου.<br><br>'
        . 'Για exclusive set του Deseo Radio, το βασικό Personal DJ Spot τοποθετείται στην αρχή, ιδανικά πάνω σε intro ή καθαρό instrumental σημείο. '
        . 'Το αρχείο με ένδειξη <strong style="color:#fff;">_30</strong> τοποθετείται περίπου στο 30ό λεπτό, σε σημείο όπου η εκφώνηση ακούγεται καθαρά. '
        . 'Δεν χρειάζεται ακρίβεια δευτερολέπτου — προτεραιότητα έχει η μουσική ροή.');

    $body .= $section('EXISTING SETS', 'Αν το set έχει ήδη ηχογραφηθεί',
        'Μπορείς να χρησιμοποιήσεις ήδη ηχογραφημένο ή δημοσιευμένο set, εφόσον είναι κατάλληλο για radio broadcast. '
        . 'Δεν πρέπει να περιλαμβάνει station IDs άλλου ραδιοφωνικού σταθμού, μη εγκεκριμένες διαφημίσεις ή commercial spots, μεγάλα silent σημεία ή εμφανή τεχνικά προβλήματα. '
        . 'Αν γίνει manual cut, ισχύει και εδώ το <strong style="color:#fff;">8-second fade out</strong>.');

    $body .= $section('YOUR ASSETS', 'Artwork, Imaging & αρχεία του show',
        'Το MyLive είναι και ο προσωπικός σου χώρος παραλαβής υλικού. Από το section <strong style="color:#fff;">Your Assets</strong> θα μπορείς να κατεβάζεις:<br><br>'
        . '• το προσωπικό Instagram / social artwork σου<br>'
        . '• το Personal DJ Imaging<br>'
        . '• το <strong style="color:#fff;">_30 Imaging</strong><br>'
        . '• οποιοδήποτε πρόσθετο promotional ή on-air asset δημιουργήσει το Deseo Radio / ILUMA Digital Agency.');

    $body .= $section('MY REWARDS', 'Φέρε μια επιχείρηση. Κράτα το 20%.',
        'Στο <strong style="color:#fff;">My Rewards</strong> θα βρεις το προσωπικό σου referral link. '
        . 'Αν γνωρίζεις μια επιχείρηση που θέλει να διαφημιστεί στο Deseo Radio, της στέλνεις το link σου.<br><br>'
        . 'Αν η επιχείρηση προχωρήσει σε νέα διαφημιστική καμπάνια μέσω της σύστασής σου, κερδίζεις <strong style="color:#fff;">20%</strong> από την καμπάνια. '
        . 'Μέσα στο My Rewards βλέπεις απλά το <strong style="color:#fff;">status</strong>, το <strong style="color:#fff;">ποσό του Reward</strong> και πότε έχει πληρωθεί.');

    $body .= $section('SOCIAL MEDIA', 'Η ανακοίνωση της συμμετοχής σου',
        'Το προσωπικό promotional artwork δημιουργείται από το γραφιστικό τμήμα της <strong style="color:#fff;">ILUMA Digital Agency</strong> με Artist Name, ημέρα, ώρα και Deseo Radio branding. '
        . 'Η δημοσίευσή του στα προσωπικά σου social media αποτελεί μέρος της συμμετοχής στη Season 6.<br><br>'
        . 'Το Deseo Radio θα προωθεί αντίστοιχα τη συμμετοχή σου από τα επίσημα κανάλια του. Ιδανικά χρησιμοποιούμε <strong style="color:#fff;">Collaborator Post</strong>. '
        . 'Όταν λαμβάνεις collaboration request από το Deseo Radio, αποδέξου το. Αν δεν είναι διαθέσιμο, χρησιμοποίησε tag / mention και repost της επίσημης ανακοίνωσης.');

    $body .= $section('MUSIC & RIGHTS', 'Το sound σου, μέσα στη φιλοσοφία του Deseo',
        'Το set πρέπει να εκφράζει το προσωπικό σου sound και να παραμένει συμβατό με τη μουσική ταυτότητα του Deseo Radio: '
        . '<strong style="color:#fff;">House · Afro House · Organic House · Deep House · Melodic House · Electronica · sophisticated electronic sound</strong>.<br><br>'
        . 'Δεν χρησιμοποιούνται εν γνώσει σου leaked, παράνομα ή μη εξουσιοδοτημένα recordings.<br><br>'
        . '<strong style="color:#fff;">Promo tracks:</strong> επιτρέπονται μόνο όταν τα έχεις παραλάβει απευθείας από τον ίδιο τον δημιουργό ή τον νόμιμο δικαιούχο και έχεις άδεια να μεταδοθούν μέσω internet / online radio. Η άδεια αυτή αφορά αποκλειστικά τη χρήση τους μέσα στο δικό σου DJ Set και δεν σημαίνει ότι επιτρέπεται ξεχωριστή δημοσίευση, διανομή ή άλλη χρήση του αρχείου.<br><br>'
        . 'Ισχύουν επίσης οι όροι της Season 6 για δικαιώματα και AI-generated musical works / recordings.');

    $body .= $section('SPONSORS', 'Αν το show έχει προσωπικό χορηγό',
        'Ενημέρωσε το Deseo Radio πριν από την παράδοση του επεισοδίου. Sponsor ID, commercial spot, promo code, paid mention ή άλλο branded message χρειάζεται προηγούμενη έγκριση από Deseo Radio / ILUMA Digital Agency. '
        . 'Για ωριαίο show προτείνονται έως <strong style="color:#fff;">1–2 σύντομες sponsor αναφορές</strong>, κατόπιν έγκρισης.');

    $body .= $section('BEFORE UPLOAD', 'Ένας τελευταίος έλεγχος',
        'Πριν πατήσεις Upload, βεβαιώσου ότι ανεβάζεις το <strong style="color:#fff;">final on-air master</strong>: σωστό MP3 192 kbps Stereo, χωρίς IDs άλλων stations, χωρίς μη εγκεκριμένα commercial messages, χωρίς μεγάλα κενά ή distortion και με τα Deseo Imaging Spots σωστά τοποθετημένα όπου απαιτείται.');

    $credentials = '<tr><td style="padding:8px 0 0;">'
        . '<div style="padding:23px;border:1px solid #5a171e;border-radius:20px;background:#160b0d;">'
        . '<div style="color:#ff4650;font:800 9px Arial,sans-serif;letter-spacing:.15em;text-transform:uppercase;">MYLIVE ACCESS</div>'
        . '<div style="margin:8px 0 7px;color:#fff;font:800 23px/1.24 Arial,sans-serif;">Τα προσωρινά στοιχεία πρόσβασής σου</div>'
        . '<div style="margin-bottom:19px;color:#b1b1b7;font:400 14px/1.7 Arial,sans-serif;">Χρησιμοποίησέ τα για την πρώτη σύνδεση. Αμέσως μετά, το MyLive θα σου ζητήσει να δημιουργήσεις τον δικό σου προσωπικό κωδικό πρόσβασης.</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="d-credentials" style="border:1px solid #2c2c31;border-radius:15px;background:#0b0b0d;">'
        . '<tr><td style="padding:15px 16px;border-bottom:1px solid #252529;color:#7f7f86;font:800 10px Arial,sans-serif;letter-spacing:.09em;text-transform:uppercase;width:34%;">Email</td>'
        . '<td class="d-credential-value" style="padding:15px 16px;border-bottom:1px solid #252529;color:#fff;font:700 17px Arial,sans-serif;">' . $e($email) . '</td></tr>'
        . '<tr><td style="padding:15px 16px;color:#7f7f86;font:800 10px Arial,sans-serif;letter-spacing:.09em;text-transform:uppercase;">Temporary password</td>'
        . '<td class="d-credential-value" style="padding:15px 16px;color:#fff;font:800 19px Arial,sans-serif;letter-spacing:.03em;">' . $e($temporaryPassword) . '</td></tr>'
        . '</table>'
        . '<div style="margin-top:17px;color:#c9aeb1;font:400 12px/1.65 Arial,sans-serif;">'
        . '<strong style="color:#fff;">Στην πρώτη σύνδεση:</strong> δημιούργησε τον δικό σου password και αποθήκευσέ τον στον browser / password manager μαζί με το email σου, ώστε να έχεις εύκολη πρόσβαση στο MyLive κάθε εβδομάδα.'
        . '</div>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" style="margin-top:18px;"><tr><td style="border-radius:999px;background:#ff2b36;">'
        . '<a href="https://deseoradio.com/mylive/" style="display:inline-block;padding:14px 23px;color:#080808;text-decoration:none;font:800 10px Arial,sans-serif;letter-spacing:.08em;text-transform:uppercase;">OPEN MYLIVE</a>'
        . '</td></tr></table>'
        . '</div></td></tr>';

    $body .= $credentials;

    $subject = $reset
        ? 'Deseo Radio MyLive · Νέα στοιχεία πρόσβασης & Season 6 οδηγίες'
        : 'Deseo Radio Season 6 · MyLive access & οδηγίες DJ';

    $html = deseo_mylive_email_shell(
        'SEASON 6 · MYLIVE',
        $reset ? 'Το νέο MyLive access σου.' : 'Καλώς ήρθες στο MyLive.',
        $reset
            ? $artist . ', εκδώσαμε νέο προσωρινό κωδικό πρόσβασης. Παρακάτω θα βρεις ξανά συγκεντρωμένες και τις οδηγίες της Season 6.'
            : $artist . ', εδώ θα βρεις όλα όσα χρειάζεσαι για το DJ Set delivery, το προσωπικό σου imaging, τα promotional assets, τα βασικά στατιστικά του show σου και την πρόσβασή σου στο MyLive.',
        $body
    );

    $text = "DESEO RADIO · SEASON 6 · MYLIVE\n\n"
        . "Artist: {$artist}\n"
        . "Weekly slot: {$slot}\n\n"
        . "DJ SET: MP3 192 kbps Stereo · ιδανική διάρκεια 58–59 λεπτά.\n"
        . "Manual cut: 8-second fade out.\n"
        . "MyLive: κάνει αυτόματα episode numbering και filename (EP001, EP002...).\n"
        . "MyLive dashboard: βλέπεις την εκτιμώμενη απήχηση του show σου, πόσα episodes έχεις ανεβάσει και πόσα προσωπικά assets έχεις διαθέσιμα.\n"
        . "Audience stats: εμφανίζονται για τον τελευταίο ολοκληρωμένο μήνα, όταν υπάρχουν διαθέσιμα στοιχεία.\n"
        . "Promo tracks: επιτρέπονται μόνο αν τα έχεις λάβει απευθείας από τον δημιουργό ή νόμιμο δικαιούχο και έχεις άδεια για online / internet radio μετάδοση, αποκλειστικά μέσα στο δικό σου DJ Set.\n"
        . "Exclusive set: Personal DJ Imaging στην αρχή και _30 Imaging περίπου στο 30ό λεπτό.\n"
        . "Artwork και branded DJ spots: διαθέσιμα μέσα από το MyLive.\n"
        . "My Rewards: έχεις προσωπικό referral link. Αν μια επιχείρηση κλείσει νέα διαφημιστική καμπάνια μέσω της σύστασής σου, κερδίζεις 20% και βλέπεις το status, το ποσό και την πληρωμή μέσα στο MyLive.\n"
        . "Social: ανακοίνωση της συμμετοχής, ιδανικά με Collaborator Post / mention Deseo Radio.\n"
        . "Sponsor material: απαιτεί προηγούμενη έγκριση.\n\n"
        . "MYLIVE ACCESS\n"
        . "URL: https://deseoradio.com/mylive/\n"
        . "Email: {$email}\n"
        . "Temporary password: {$temporaryPassword}\n\n"
        . "Με την πρώτη σύνδεση θα δημιουργήσεις τον προσωπικό σου κωδικό. Αποθήκευσέ τον μαζί με το email σου στον browser / password manager.\n\n"
        . "Deseo Radio · Powered by ILUMA Digital Agency";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}


function deseo_mylive_password_reset_email(array $account, string $resetToken): array {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $artist = trim((string)($account['artist_name'] ?? 'DJ'));
    $email = trim((string)($account['email'] ?? ''));
    $resetUrl = 'https://deseoradio.com/mylive/?reset=' . rawurlencode($resetToken);

    $body = '<tr><td style="padding:0 0 20px;">'
        . '<div style="padding:23px;border-radius:20px;background:#ff2b36;color:#080808;">'
        . '<div style="font:800 9px Arial,sans-serif;letter-spacing:.14em;text-transform:uppercase;">PASSWORD RESET</div>'
        . '<div style="margin-top:8px;font:800 28px/1.12 Arial,sans-serif;letter-spacing:-.025em;">Άλλαξε τον κωδικό σου.</div>'
        . '<div style="margin-top:8px;font:700 13px/1.55 Arial,sans-serif;">Ο σύνδεσμος ισχύει για 60 λεπτά και χρησιμοποιείται μόνο μία φορά.</div>'
        . '</div></td></tr>';

    $body .= deseo_mylive_email_section(
        'MYLIVE ACCOUNT',
        'Το email του account σου',
        '<strong style="color:#fff;">' . $e($email) . '</strong>'
    );

    $body .= deseo_mylive_email_section(
        'SECURITY',
        'Δεν στέλνουμε temporary password',
        'Πάτησε το κουμπί παρακάτω και όρισε απευθείας τον νέο προσωπικό σου κωδικό. '
        . 'Ο παλιός κωδικός θα πάψει να ισχύει μόλις ολοκληρώσεις την αλλαγή.'
    );

    $body .= deseo_mylive_email_section(
        'DIDN’T REQUEST THIS?',
        'Δεν χρειάζεται να κάνεις τίποτα',
        'Αν δεν ζήτησες αλλαγή κωδικού, αγνόησε αυτό το email. Ο υπάρχων κωδικός σου παραμένει ενεργός.'
    );

    $subject = 'Deseo Radio MyLive · Reset password';

    $html = deseo_mylive_email_shell(
        'DESEO RADIO · MYLIVE · SECURITY',
        'Reset your password.',
        $artist . ', λάβαμε αίτημα αλλαγής κωδικού για το MyLive account σου.',
        $body,
        'CHANGE PASSWORD',
        $resetUrl
    );

    $text = "DESEO RADIO · MYLIVE · PASSWORD RESET\n\n"
        . "{$artist}, λάβαμε αίτημα αλλαγής κωδικού για το MyLive account σου.\n\n"
        . "Email: {$email}\n"
        . "Το link ισχύει για 60 λεπτά και χρησιμοποιείται μόνο μία φορά.\n\n"
        . "Change password: {$resetUrl}\n\n"
        . "Αν δεν ζήτησες αλλαγή κωδικού, αγνόησε αυτό το email.\n\n"
        . "Deseo Radio · Powered by ILUMA Digital Agency";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}


function deseo_mylive_reward_created_email(array $account, array $reward): array {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $artist = trim((string)($account['artist_name'] ?? 'DJ'));
    $business = trim((string)($reward['business_name'] ?? 'Referral'));
    $campaignValue = (float)($reward['campaign_value'] ?? 0);
    $rewardPercent = (float)($reward['reward_percent'] ?? 20);
    $rewardAmount = (float)($reward['reward_amount'] ?? 0);
    $status = strtolower(trim((string)($reward['status'] ?? 'new')));
    $statusLabel = match ($status) {
        'in_discussion' => 'In Discussion',
        'confirmed' => 'Confirmed',
        'reward_ready' => 'Reward Ready',
        'paid' => 'Paid',
        'closed' => 'Closed',
        default => 'New',
    };
    $referralDate = trim((string)($reward['referred_at'] ?? ''));
    $referralDateLabel = $referralDate !== ''
        ? date('d.m.Y', strtotime($referralDate))
        : date('d.m.Y');
    $contactName = trim((string)($reward['contact_name'] ?? ''));
    $contactEmail = trim((string)($reward['contact_email'] ?? ''));
    $contactPhone = trim((string)($reward['contact_phone'] ?? ''));
    $djNote = trim((string)($reward['dj_note'] ?? ''));

    $money = static fn(float $value): string => '€' . number_format($value, 2, ',', '.');
    $percent = rtrim(rtrim(number_format($rewardPercent, 2, '.', ''), '0'), '.') . '%';

    $body = '<tr><td style="padding:0 0 20px;">'
        . '<div style="padding:23px;border-radius:20px;background:#ff2b36;color:#080808;">'
        . '<div style="font:800 9px Arial,sans-serif;letter-spacing:.14em;text-transform:uppercase;">NEW MY REWARD</div>'
        . '<div style="margin-top:8px;font:800 31px/1.08 Arial,sans-serif;letter-spacing:-.025em;">' . $e($money($rewardAmount)) . '</div>'
        . '<div style="margin-top:7px;font:700 13px/1.5 Arial,sans-serif;">' . $e($business) . ' · ' . $e($statusLabel) . '</div>'
        . '</div></td></tr>';

    $body .= '<tr><td style="padding:0 0 22px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #27272b;border-radius:18px;background:#09090b;">'
        . '<tr><td style="padding:14px 17px;border-bottom:1px solid #222226;color:#717178;font:800 9px Arial,sans-serif;letter-spacing:.1em;text-transform:uppercase;width:42%;">Business</td>'
        . '<td style="padding:14px 17px;border-bottom:1px solid #222226;color:#fff;font:700 14px Arial,sans-serif;">' . $e($business) . '</td></tr>'
        . '<tr><td style="padding:14px 17px;border-bottom:1px solid #222226;color:#717178;font:800 9px Arial,sans-serif;letter-spacing:.1em;text-transform:uppercase;">Referral date</td>'
        . '<td style="padding:14px 17px;border-bottom:1px solid #222226;color:#fff;font:700 14px Arial,sans-serif;">' . $e($referralDateLabel) . '</td></tr>'
        . '<tr><td style="padding:14px 17px;border-bottom:1px solid #222226;color:#717178;font:800 9px Arial,sans-serif;letter-spacing:.1em;text-transform:uppercase;">Campaign value</td>'
        . '<td style="padding:14px 17px;border-bottom:1px solid #222226;color:#fff;font:700 14px Arial,sans-serif;">' . $e($money($campaignValue)) . '</td></tr>'
        . '<tr><td style="padding:14px 17px;border-bottom:1px solid #222226;color:#717178;font:800 9px Arial,sans-serif;letter-spacing:.1em;text-transform:uppercase;">Your share</td>'
        . '<td style="padding:14px 17px;border-bottom:1px solid #222226;color:#fff;font:700 14px Arial,sans-serif;">' . $e($percent) . '</td></tr>'
        . '<tr><td style="padding:14px 17px;border-bottom:1px solid #222226;color:#717178;font:800 9px Arial,sans-serif;letter-spacing:.1em;text-transform:uppercase;">Your reward</td>'
        . '<td style="padding:14px 17px;border-bottom:1px solid #222226;color:#fff;font:800 16px Arial,sans-serif;">' . $e($money($rewardAmount)) . '</td></tr>'
        . '<tr><td style="padding:14px 17px;color:#717178;font:800 9px Arial,sans-serif;letter-spacing:.1em;text-transform:uppercase;">Status</td>'
        . '<td style="padding:14px 17px;color:#ff5b65;font:800 13px Arial,sans-serif;">' . $e($statusLabel) . '</td></tr>'
        . '</table></td></tr>';

    if ($contactName !== '' || $contactEmail !== '' || $contactPhone !== '') {
        $contactLines = [];
        if ($contactName !== '') $contactLines[] = '<strong style="color:#fff;">' . $e($contactName) . '</strong>';
        if ($contactEmail !== '') $contactLines[] = $e($contactEmail);
        if ($contactPhone !== '') $contactLines[] = $e($contactPhone);

        $body .= deseo_mylive_email_section(
            'REFERRAL CONTACT',
            'Στοιχεία επικοινωνίας',
            implode('<br>', $contactLines)
        );
    }

    if ($djNote !== '') {
        $body .= deseo_mylive_email_section(
            'UPDATE FROM ILUMA',
            'Σημείωση για το Reward σου',
            nl2br($e($djNote))
        );
    }

    $body .= deseo_mylive_email_section(
        'MY REWARDS',
        'Όλα συγκεντρωμένα στο MyLive',
        'Μέσα από το <strong style="color:#fff;">My Rewards</strong> μπορείς να παρακολουθείς το status κάθε referral, το campaign value, το ποσοστό σου, το Reward amount και την πορεία μέχρι την πληρωμή.'
    );

    $subject = 'Deseo Radio MyLive · Νέο Reward · ' . $business;

    $html = deseo_mylive_email_shell(
        'DESEO RADIO · MYLIVE · MY REWARDS',
        'Έχεις νέο Reward.',
        $artist . ', προστέθηκε νέο referral στο My Rewards σου. Παρακάτω θα βρεις όλες τις λεπτομέρειες.',
        $body,
        'VIEW MY REWARDS',
        'https://deseoradio.com/mylive/?section=rewards#rewards'
    );

    $text = "DESEO RADIO · MYLIVE · MY REWARDS\n\n"
        . "{$artist}, έχεις νέο Reward.\n\n"
        . "Business: {$business}\n"
        . "Referral date: {$referralDateLabel}\n"
        . "Campaign value: " . $money($campaignValue) . "\n"
        . "Your share: {$percent}\n"
        . "Your reward: " . $money($rewardAmount) . "\n"
        . "Status: {$statusLabel}\n"
        . ($contactName !== '' ? "Contact: {$contactName}\n" : '')
        . ($contactEmail !== '' ? "Email: {$contactEmail}\n" : '')
        . ($contactPhone !== '' ? "Phone: {$contactPhone}\n" : '')
        . ($djNote !== '' ? "\nUpdate from ILUMA:\n{$djNote}\n" : '')
        . "\nView My Rewards: https://deseoradio.com/mylive/?section=rewards#rewards\n\n"
        . "Deseo Radio · Powered by ILUMA Digital Agency";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}
