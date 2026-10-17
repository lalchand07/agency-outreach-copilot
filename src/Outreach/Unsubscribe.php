<?php

declare(strict_types=1);

namespace App\Outreach;

use App\Db\Database;
use App\Leads\LeadRepository;
use App\Pipeline\Status;

/**
 * Handles the unique unsubscribe link in every email.
 */
final class Unsubscribe
{
    public function __construct(private Database $db, private Suppression $suppression, private LeadRepository $leads)
    {
    }

    /** @return array<string, mixed>|null the message the token belongs to */
    public function lookup(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        return $this->db->one('SELECT * FROM messages WHERE unsubscribe_token = ?', [$token]);
    }

    /**
     * Adds the recipient to the suppression list and marks the lead "do not contact".
     * Idempotent. Returns false for an unknown token.
     */
    public function confirm(string $token): bool
    {
        $message = $this->lookup($token);
        if ($message === null) {
            return false;
        }
        $recipient = (string) $message['recipient'];
        if (str_contains($recipient, '@')) {
            $this->suppression->add('email', $recipient, 'Unsubscribed via link');
        }
        if ($message['lead_id'] !== null) {
            $leadId = (int) $message['lead_id'];
            $this->leads->setStatus($leadId, Status::DO_NOT_CONTACT, 'unsubscribed via link');
            $this->leads->log($leadId, 'unsubscribe', 'Recipient unsubscribed via link');
        }

        return true;
    }
}
