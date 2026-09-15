<?php
declare(strict_types=1);

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

function deseo_send_smtp_mail(string $toEmail, string $toName, string $subject, string $html, string $text = ''): void {
    $host = deseo_env('SMTP_HOST');
    $port = (int)deseo_env('SMTP_PORT', '465');
    $username = deseo_env('SMTP_USERNAME');
    $password = deseo_env('SMTP_PASSWORD');
    $secure = strtolower(deseo_env('SMTP_SECURE', 'ssl'));
    $fromEmail = deseo_env('SMTP_FROM_EMAIL', $username);
    $fromName = deseo_env('SMTP_FROM_NAME', 'Deseo Radio');
    $replyTo = deseo_env('SMTP_REPLY_TO', $fromEmail);

    if ($host === '' || $port < 1 || $username === '' || $password === '' || $fromEmail === '') {
        throw new RuntimeException('SMTP configuration is incomplete.');
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
        throw new RuntimeException('Could not connect to SMTP server.');
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
        $messageId = '<' . bin2hex(random_bytes(12)) . '@deseoradio.com>';
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
        '<p style="margin:7px 0 0;color:#85858b;font:400 13px/1.65 Arial,sans-serif;">' . $e($nextText) . '</p>' .
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
        'Θα επικοινωνήσουμε μαζί σου μέσω email με όλες τις πληροφορίες για το τελικό set, τις τεχνικές οδηγίες και τα promotional assets.'
    );

    $text = "DESEO RADIO · SEASON 6\n\n" .
        "Καλώς ήρθες στο Deseo Radio.\n" .
        "Σε επιλέξαμε για το σταθερό πρόγραμμα της Season 6.\n\n" .
        "Ημέρα / ώρα: {$c['slot']}\n" .
        "Artist name: {$c['artist']}\n\n" .
        "Θα σε ενημερώσουμε για τα επόμενα βήματα μέσω email.\n\n" .
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
