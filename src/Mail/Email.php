<?php

declare(strict_types=1);

namespace App\Mail;

final class Email
{
    /**
     * @param array<string, string> $headers extra headers (List-Unsubscribe, …)
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $replyTo = '',
        public readonly array $headers = [],
    ) {
    }
}
