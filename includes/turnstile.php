<?php
declare(strict_types=1);

function deseo_turnstile_site_key(): string {
    return trim((string)(getenv('TURNSTILE_SITE_KEY') ?: ''));
}

function deseo_turnstile_secret_key(): string {
    return trim((string)(getenv('TURNSTILE_SECRET_KEY') ?: ''));
}

function deseo_turnstile_configured(): bool {
    return deseo_turnstile_site_key() !== '' && deseo_turnstile_secret_key() !== '';
}

function deseo_turnstile_validate(string $token, ?string $remoteIp = null, string $expectedAction = ''): array {
    $secret = deseo_turnstile_secret_key();
    if ($secret === '') {
        return ['success' => false, 'error-codes' => ['missing-secret']];
    }

    $token = trim($token);
    if ($token === '' || strlen($token) > 2048) {
        return ['success' => false, 'error-codes' => ['missing-input-response']];
    }

    $payload = [
        'secret' => $secret,
        'response' => $token,
    ];
    if ($remoteIp !== null && $remoteIp !== '') {
        $payload['remoteip'] = $remoteIp;
    }

    $endpoint = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $raw = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($payload, '', '&'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $raw = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                error_log('Turnstile cURL validation failed: ' . $curlError);
            }
        }
    }

    if ($raw === false) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload, '', '&'),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($endpoint, false, $context);
    }

    if (!is_string($raw) || $raw === '') {
        return ['success' => false, 'error-codes' => ['network-error']];
    }

    $result = json_decode($raw, true);
    if (!is_array($result)) {
        return ['success' => false, 'error-codes' => ['invalid-response']];
    }

    if (empty($result['success'])) {
        return $result;
    }

    if ($expectedAction !== '') {
        $actualAction = (string)($result['action'] ?? '');
        if ($actualAction !== $expectedAction) {
            return ['success' => false, 'error-codes' => ['action-mismatch']];
        }
    }

    $expectedHostname = trim((string)(getenv('TURNSTILE_HOSTNAME') ?: ''));
    if ($expectedHostname !== '') {
        $actualHostname = strtolower((string)($result['hostname'] ?? ''));
        if ($actualHostname !== strtolower($expectedHostname)) {
            return ['success' => false, 'error-codes' => ['hostname-mismatch']];
        }
    }

    return $result;
}
