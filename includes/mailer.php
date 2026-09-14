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

function deseo_dj_approval_email(array $booking): array {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

    $day = dj_season_day_label((int)($booking['day_of_week'] ?? 0));
    $start = dj_season_format_time((string)($booking['start_time'] ?? ''));
    $end = dj_season_format_time((string)($booking['end_time'] ?? ''));
    $artist = trim((string)($booking['artist_name'] ?? 'DJ'));
    $fullName = trim((string)($booking['full_name'] ?? ''));
    $email = trim((string)($booking['email'] ?? ''));
    $instagram = trim((string)($booking['instagram'] ?? ''));
    $website = trim((string)($booking['website'] ?? ''));
    $bio = trim((string)($booking['bio'] ?? ''));
    $workSampleUrl = trim((string)($booking['work_sample_url'] ?? ''));
    $photoPath = trim((string)($booking['photo_path'] ?? ''));
    $photoUrl = str_starts_with($photoPath, '/') ? 'https://deseoradio.com' . $photoPath : '';
    $setTypeMap = [
        'new' => 'Νέο DJ set',
        'previous' => 'Παλαιότερο / ήδη ηχογραφημένο set',
        'exclusive' => 'Set ειδικά για το Deseo Radio',
    ];
    $setType = $setTypeMap[(string)($booking['set_type'] ?? '')] ?? (string)($booking['set_type'] ?? '');

    $row = static function (string $label, string $value) use ($e): string {
        if ($value === '') return '';
        return '<tr><td style="padding:10px 0;color:#777;font:600 11px Arial,sans-serif;text-transform:uppercase;letter-spacing:.08em;border-bottom:1px solid #202024;width:34%;">' .
            $e($label) . '</td><td style="padding:10px 0;color:#f5f5f5;font:400 14px Arial,sans-serif;border-bottom:1px solid #202024;">' .
            nl2br($e($value)) . '</td></tr>';
    };

    $subject = 'Deseo Radio Season 6 · Approved · ' . $artist . ' · ' . $day . ' ' . $start;

    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#050505;color:#fff;">' .
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#050505;padding:28px 12px;"><tr><td align="center">' .
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#0d0d0f;border:1px solid #252529;border-radius:24px;overflow:hidden;">' .
        '<tr><td style="padding:30px;background:linear-gradient(135deg,#111114,#090909);border-bottom:1px solid #252529;">' .
        '<img src="https://deseoradio.com/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="190" style="display:block;max-width:190px;height:auto;margin:0 0 26px;">' .
        '<div style="font:700 11px Arial,sans-serif;color:#ff2b36;letter-spacing:.16em;text-transform:uppercase;">Season 6 · DJ Sets</div>' .
        '<h1 style="margin:10px 0 12px;font:700 34px Arial,sans-serif;line-height:1.05;color:#fff;">Your inquiry has been approved.</h1>' .
        '<p style="margin:0;color:#aaa;font:400 15px/1.65 Arial,sans-serif;">' . $e($artist) . ', σε επιλέξαμε για τη Season 6 του Deseo Radio. Η προτεινόμενη ώρα σου έχει εγκριθεί από την ομάδα μας.</p>' .
        '</td></tr>' .
        '<tr><td style="padding:28px 30px;">' .
        '<div style="padding:18px 20px;border-radius:18px;background:#ff2b36;color:#090909;margin-bottom:24px;">' .
        '<div style="font:700 10px Arial,sans-serif;letter-spacing:.14em;text-transform:uppercase;">Approved slot</div>' .
        '<div style="margin-top:5px;font:800 25px Arial,sans-serif;">' . $e($day) . ' · ' . $e($start) . '–' . $e($end) . '</div>' .
        '</div>' .
        '<p style="margin:0 0 18px;color:#8f8f94;font:400 13px/1.65 Arial,sans-serif;">Παρακάτω είναι τα στοιχεία που υπέβαλες στο Season 6 inquiry. Η έγκριση αυτή δεν αποτελεί αυτόματη δημοσίευση στο online πρόγραμμα· η ομάδα του Deseo ολοκληρώνει χειροκίνητα τον προγραμματισμό.</p>' .
        ($photoUrl !== '' ? '<img src="' . $e($photoUrl) . '" alt="' . $e($artist) . '" width="160" style="display:block;width:160px;height:160px;object-fit:cover;border-radius:18px;margin:0 0 22px;border:1px solid #252529;">' : '') .
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0">' .
        $row('Artist name', $artist) .
        $row('Ονοματεπώνυμο', $fullName) .
        $row('Email', $email) .
        $row('Social', $instagram) .
        $row('Website', $website) .
        $row('Τύπος set', $setType) .
        $row('Work sample', $workSampleUrl) .
        $row('Bio', $bio) .
        $row('Terms', (string)($booking['terms_version'] ?? '')) .
        $row('Privacy', (string)($booking['privacy_version'] ?? '')) .
        '</table>' .
        '<div style="margin-top:26px;padding:18px;border:1px solid #252529;border-radius:16px;background:#09090b;">' .
        '<div style="color:#fff;font:700 14px Arial,sans-serif;">Next step</div>' .
        '<p style="margin:7px 0 0;color:#85858b;font:400 13px/1.65 Arial,sans-serif;">Η ομάδα Deseo / ILUMA θα επικοινωνήσει μαζί σου για τις τεχνικές οδηγίες, την παράδοση του set και τα promotional assets.</p>' .
        '</div>' .
        '<p style="margin:24px 0 0;color:#5f5f65;font:400 11px/1.6 Arial,sans-serif;">Deseo Radio · An ILUMA Digital Agency project<br>radio@iluma.gr · deseoradio.com</p>' .
        '</td></tr></table></td></tr></table></body></html>';

    $text = "DESEO RADIO · SEASON 6\n\n" .
        "Your inquiry has been approved.\n" .
        "Approved slot: {$day} {$start}–{$end}\n\n" .
        "Artist name: {$artist}\n" .
        "Ονοματεπώνυμο: {$fullName}\n" .
        "Email: {$email}\n" .
        "Social: {$instagram}\n" .
        "Website: {$website}\n" .
        "Τύπος set: {$setType}\n" .
        "Work sample: {$workSampleUrl}\n" .
        "Bio: {$bio}\n" .
        "Photo: {$photoUrl}\n" .
        "Terms: " . (string)($booking['terms_version'] ?? '') . "\n" .
        "Privacy: " . (string)($booking['privacy_version'] ?? '') . "\n\n" .
        "Η ομάδα Deseo / ILUMA θα επικοινωνήσει μαζί σου για τα επόμενα βήματα.\n\n" .
        "Deseo Radio · radio@iluma.gr";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}
