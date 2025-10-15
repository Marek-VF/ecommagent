<?php
declare(strict_types=1);

namespace AuthModule;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';

function sendMail(string $recipientEmail, string $recipientName, string $subject, string $htmlBody, string $textBody): bool
{
    $config = \auth_config();
    $smtp = $config['smtp'] ?? [];
    $mailConfig = $config['mail'] ?? [];

    $mailer = new PHPMailer(true);

    try {
        $mailer->isSMTP();
        $mailer->Host       = (string)($smtp['host'] ?? 'localhost');
        $mailer->Port       = (int)($smtp['port'] ?? 587);
        $mailer->SMTPAuth   = (bool)($smtp['auth'] ?? true);
        $mailer->Username   = (string)($smtp['username'] ?? '');
        $mailer->Password   = (string)($smtp['password'] ?? '');
        $mailer->SMTPSecure = (string)($smtp['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS);

        $fromAddress = (string)($mailConfig['from_address'] ?? 'no-reply@example.com');
        $fromName    = (string)($mailConfig['from_name'] ?? 'Artikelverwaltung');

        $mailer->setFrom($fromAddress, $fromName);
        $mailer->addAddress($recipientEmail, $recipientName);
        $mailer->Subject = $subject;
        $mailer->isHTML(true);
        $mailer->Body    = $htmlBody;
        $mailer->AltBody = $textBody;

        return $mailer->send();
    } catch (MailException $exception) {
        error_log('[Mail] Versand fehlgeschlagen: ' . $exception->getMessage());

        return false;
    }
}
