<?php
/**
 * Shared helper functions.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Escape a string for safe HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Generate (or reuse) the CSRF token for the current session. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Compare a submitted token against the session token. */
function csrf_valid(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Trim, strip tags and cap the length of user-supplied text. */
function clean_input($value, int $maxLength = 500): string
{
    $value = is_string($value) ? $value : '';
    $value = trim(strip_tags($value));
    $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? '';
    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, $maxLength, 'UTF-8');
    } else {
        $value = substr($value, 0, $maxLength);
    }
    return $value;
}

/** Loose RU/international phone validation — just enough to catch junk input. */
function is_valid_phone(string $phone): bool
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    return strlen($digits) >= 10 && strlen($digits) <= 15;
}

function is_valid_email(string $email): bool
{
    return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Very small per-session throttle so a single spammy browser session
 * can't hammer the mail form. Not a substitute for real rate limiting,
 * but keeps native mail() from being abused without adding a database.
 */
function too_soon_since_last_submit(int $minSeconds = 12): bool
{
    $now = time();
    $last = $_SESSION['last_submit_at'] ?? 0;
    if ($now - $last < $minSeconds) {
        return true;
    }
    $_SESSION['last_submit_at'] = $now;
    return false;
}

/** True when SMTP credentials were provided via includes/config.local.php. */
function smtp_configured(): bool
{
    return defined('SMTP_HOST') && defined('SMTP_USER') && defined('SMTP_PASS')
        && SMTP_USER !== '' && SMTP_PASS !== '';
}

/** Read one (possibly multi-line) SMTP reply; returns [code, text]. */
function smtp_read($fp): array
{
    $text = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $text .= $line;
        // "250-..." continues, "250 ..." ends the reply.
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    return [(int)substr($text, 0, 3), trim($text)];
}

/**
 * Minimal SMTP client (implicit TLS + AUTH LOGIN) — no Composer needed on shared hosting.
 * Sends a single already-built message; returns true on 250 after DATA.
 */
function smtp_send(string $to, string $fromAddress, string $headers, string $body): bool
{
    $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 465;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(SMTP_HOST . ':' . $port, $errno, $errstr, 10);
    if (!$fp) {
        error_log("[lead-mail] SMTP connect failed: $errno $errstr");
        return false;
    }
    stream_set_timeout($fp, 15);

    $expect = function (array $codes, ?string $command = null) use ($fp): bool {
        if ($command !== null) {
            fwrite($fp, $command . "\r\n");
        }
        [$code, $text] = smtp_read($fp);
        if (!in_array($code, $codes, true)) {
            error_log('[lead-mail] SMTP unexpected reply: ' . $text);
            return false;
        }
        return true;
    };

    $ehloHost = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? '') ?: 'localhost';
    $ok = $expect([220])
        && $expect([250], 'EHLO ' . $ehloHost)
        && $expect([334], 'AUTH LOGIN')
        && $expect([334], base64_encode(SMTP_USER))
        && $expect([235], base64_encode(SMTP_PASS))
        && $expect([250], 'MAIL FROM:<' . $fromAddress . '>')
        && $expect([250, 251], 'RCPT TO:<' . $to . '>')
        && $expect([354], 'DATA');

    if ($ok) {
        // Normalise line endings and dot-stuff so a lone "." line can't end DATA early.
        $message = $headers . "\r\n\r\n" . $body;
        $message = preg_replace('/\r\n|\r|\n/', "\r\n", $message) ?? $message;
        $message = preg_replace('/^\./m', '..', $message) ?? $message;
        fwrite($fp, $message . "\r\n.\r\n");
        $ok = $expect([250]);
    }

    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

/**
 * Build and send a lead notification email — via SMTP when configured, otherwise
 * (or if SMTP fails) via PHP's native mail().
 *
 * @param string $subject Plain, already human-readable subject (UTF-8).
 * @param array<string,string> $lines Label => value pairs rendered in order.
 * @param string $replyToEmail Visitor's email, used as Reply-To when valid.
 */
function send_lead_email(string $subject, array $lines, string $replyToEmail = ''): bool
{
    $bodyParts = [];
    foreach ($lines as $label => $value) {
        if ($value === '') {
            continue;
        }
        $bodyParts[] = $label . ': ' . $value;
    }
    $bodyParts[] = '';
    $bodyParts[] = 'Отправлено с сайта ' . SITE_NAME . ' (' . ($_SERVER['HTTP_HOST'] ?? '') . ')';
    $bodyParts[] = 'Дата: ' . date('d.m.Y H:i');
    $body = implode("\n", $bodyParts);

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $replyTo = ($replyToEmail !== '' && is_valid_email($replyToEmail)) ? $replyToEmail : '';

    if (smtp_configured()) {
        // Yandex only accepts mail whose From matches the authenticated account.
        $smtpFrom = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?= <' . SMTP_USER . '>';
        $smtpHeaders = [
            'Date: ' . date('r'),
            'From: ' . $smtpFrom,
            'To: ' . LEAD_RECIPIENT_EMAIL,
            'Subject: ' . $encodedSubject,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', SMTP_USER)[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: PHP/' . phpversion(),
        ];
        if ($replyTo !== '') {
            $smtpHeaders[] = 'Reply-To: ' . $replyTo;
        }
        $encodedBody = chunk_split(base64_encode($body), 76, "\r\n");
        if (smtp_send(LEAD_RECIPIENT_EMAIL, SMTP_USER, implode("\r\n", $smtpHeaders), rtrim($encodedBody))) {
            return true;
        }
        error_log('[lead-mail] SMTP failed, falling back to mail() for: ' . $subject);
    }

    $fromHeader = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?= <' . MAIL_FROM_ADDRESS . '>';

    $headers = [];
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $envelopeFrom = '-f' . MAIL_FROM_ADDRESS;

    $sent = @mail(
        LEAD_RECIPIENT_EMAIL,
        $encodedSubject,
        $body,
        implode("\r\n", $headers),
        $envelopeFrom
    );

    if (!$sent) {
        error_log('[lead-mail] mail() failed for subject: ' . $subject);
    }

    return $sent;
}

/** Send a JSON response and stop execution. */
function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
