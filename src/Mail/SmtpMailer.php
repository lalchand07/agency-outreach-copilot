<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\Env;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends plain-text email through the user's own SMTP server with PHPMailer.
 * Credentials come from .env and are never logged.
 */
final class SmtpMailer implements MailerInterface
{
    public function __construct(private Env $env)
    {
    }

    public function isConfigured(): bool
    {
        return $this->env->get('SMTP_HOST') !== '';
    }

    public function send(Email $email): void
    {
        if (!$this->isConfigured()) {
            throw new MailException('SMTP is not configured: set SMTP_HOST and friends in .env.');
        }
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->env->get('SMTP_HOST');
            $mail->Port = $this->env->int('SMTP_PORT', 587);
            $encryption = strtolower($this->env->get('SMTP_ENCRYPTION', 'tls'));
            $mail->SMTPSecure = match ($encryption) {
                'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
                'none', '' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            $mail->SMTPAutoTLS = $encryption !== 'none';
            $username = $this->env->get('SMTP_USERNAME');
            if ($username !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $username;
                $mail->Password = $this->env->get('SMTP_PASSWORD');
            }
            $mail->Timeout = 20;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $mail->setFrom($email->fromEmail, $email->fromName);
            $mail->addAddress($email->to);
            if ($email->replyTo !== '') {
                $mail->addReplyTo($email->replyTo);
            }
            foreach ($email->headers as $name => $value) {
                $mail->addCustomHeader($name, $value);
            }
            $mail->isHTML(false);
            $mail->Subject = $email->subject;
            $mail->Body = $email->body;
            $mail->send();
        } catch (PhpMailerException $e) {
            // PHPMailer messages never contain the password.
            throw new MailException('SMTP error: ' . $mail->ErrorInfo, 0, $e);
        }
    }
}
