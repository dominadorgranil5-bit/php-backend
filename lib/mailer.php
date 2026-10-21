<?php
/** lib/mailer.php — sends email through SMTP using PHPMailer (Composer). */

use PHPMailer\PHPMailer\PHPMailer;

function send_mail(string $to, string $subject, string $html, string $text): void
{
    // Preferred path: Brevo's HTTP API (port 443). Shared hosts often block
    // outbound SMTP to non-Gmail relays ("Failed to connect to server"), while
    // HTTPS always gets through. Used whenever BREVO_API_KEY is set in .env.
    $apiKey = trim((string)config('BREVO_API_KEY', ''));
    if ($apiKey !== '') {
        send_mail_brevo_api($apiKey, $to, $subject, $html, $text);
        return;
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PHPMailer is not installed. Run "composer install" in the php-backend folder.');
    }
    require_once $autoload;

    $user = (string)config('SMTP_USER', '');
    $pass = str_replace(' ', '', (string)config('SMTP_PASS', '')); // Gmail shows app passwords with spaces
    $port = (int)config('SMTP_PORT', '587');
    $from = config('MAIL_FROM', $user) ?: $user;

    if ($user === '' || $pass === '') {
        throw new RuntimeException('SMTP_USER / SMTP_PASS are not set in .env.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = (string)config('SMTP_HOST', 'smtp.gmail.com');
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->Port       = $port;
    $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 15;

    $mail->setFrom($from, (string)config('MAIL_FROM_NAME', 'Drew\'s Cafe'));
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;
    $mail->AltBody = $text;
    $mail->send();
}

/** Sends via Brevo's transactional API (POST /v3/smtp/email) over HTTPS. Throws on failure. */
function send_mail_brevo_api(string $apiKey, string $to, string $subject, string $html, string $text): void
{
    $from = (string)config('MAIL_FROM', '');
    if ($from === '') {
        throw new RuntimeException('MAIL_FROM is not set in .env.');
    }

    $payload = [
        'sender'       => ['email' => $from, 'name' => (string)config('MAIL_FROM_NAME', 'Drew\'s Cafe')],
        'to'           => [['email' => $to]],
        'subject'      => $subject,
        'htmlContent'  => $html,
        'textContent'  => $text,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode the email payload.');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['api-key: ' . $apiKey, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            throw new RuntimeException('Brevo API request failed: ' . $err);
        }
    } else {
        // Minimal fallback for hosts without the cURL extension.
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "api-key: $apiKey\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content'       => $json,
            'timeout'       => 30,
            'ignore_errors' => true,
        ]]);
        $resp   = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
            }
        }
        if ($resp === false) {
            throw new RuntimeException('Brevo API request failed (no cURL and streams unreachable).');
        }
    }

    if ($status < 200 || $status >= 300) {
        $data = json_decode($resp, true);
        $msg  = is_array($data) && isset($data['message']) ? $data['message'] : "HTTP $status";
        throw new RuntimeException('Brevo API error: ' . $msg);
    }
}

/** Error text safe to show the client: details only when APP_DEBUG=true. */
function mail_error_detail(Throwable $e): string
{
    return config('APP_DEBUG', 'false') === 'true' ? ' (' . $e->getMessage() . ')' : '';
}
