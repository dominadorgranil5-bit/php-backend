<?php
/** lib/mailer.php — sends email through SMTP using PHPMailer (Composer). */

use PHPMailer\PHPMailer\PHPMailer;

function send_mail(string $to, string $subject, string $html, string $text): void
{
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

/** Error text safe to show the client: details only when APP_DEBUG=true. */
function mail_error_detail(Throwable $e): string
{
    return config('APP_DEBUG', 'false') === 'true' ? ' (' . $e->getMessage() . ')' : '';
}
