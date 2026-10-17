<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Keeps emails in memory instead of sending them (tests and dry runs).
 */
final class ArrayMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function __construct(private ?string $failFor = null)
    {
    }

    public function send(Email $email): void
    {
        if ($this->failFor !== null && $email->to === $this->failFor) {
            throw new MailException('Simulated SMTP failure');
        }
        $this->sent[] = $email;
    }

    public function last(): ?Email
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }
}
