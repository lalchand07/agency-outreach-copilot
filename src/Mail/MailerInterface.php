<?php

declare(strict_types=1);

namespace App\Mail;

interface MailerInterface
{
    /** @throws MailException */
    public function send(Email $email): void;
}
