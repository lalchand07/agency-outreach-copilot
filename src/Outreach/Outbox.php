<?php

declare(strict_types=1);

namespace App\Outreach;

use App\Config\ProductProfile;
use App\Config\Settings;
use App\Db\Database;
use App\Leads\LeadRepository;
use App\Mail\MailerInterface;
use App\Mail\MailException;
use App\Pipeline\Status;
use App\Support\Clock;

/**
 * The send queue and send log. Approved emails are queued here and sent by
 * process(), which enforces the daily cap and re-checks suppression just
 * before each send. LinkedIn and WhatsApp are never sent automatically: the
 * user sends them by hand and records it with markManualSent().
 */
final class Outbox
{
    public function __construct(
        private Database $db,
        private LeadRepository $leads,
        private Suppression $suppression,
        private Settings $settings,
        private EmailComposer $composer,
        private ProductProfile $product,
        private MailerInterface $mailer,
        private Clock $clock,
    ) {
    }

    /**
     * Queues an approved email draft. Returns an error message, or null on success.
     *
     * @param array<string, mixed> $draft
     */
    public function queueEmail(array $draft): ?string
    {
        if ($draft['channel'] !== 'email' || $draft['status'] !== 'approved') {
            return 'Only approved email drafts can be queued.';
        }
        $missing = $this->product->missingSenderFields();
        if ($missing !== []) {
            return 'Set the sender ' . implode(', ', $missing) . ' in config/product.php first: every email must say who sent it and give a postal address.';
        }
        $lead = $this->leads->find((int) $draft['lead_id']);
        if ($lead === null) {
            return 'Lead not found.';
        }
        if (in_array($lead['status'], [Status::DO_NOT_CONTACT, Status::NOT_INTERESTED], true)) {
            return 'This lead is ' . Status::label((string) $lead['status']) . '.';
        }
        $recipient = (string) $lead['email'];
        if ($recipient === '') {
            return 'This lead has no email address.';
        }
        $hit = $this->suppression->matchesLead($lead);
        if ($hit !== null) {
            return 'Suppressed (' . $hit . '): not queued.';
        }
        if ((int) $draft['is_followup'] === 1) {
            $sentFollowups = (int) $this->db->value("SELECT COUNT(*) FROM messages WHERE lead_id = ? AND channel = 'email' AND is_followup = 1 AND status IN ('queued', 'sent')", [$lead['id']]);
            if ($sentFollowups >= $this->settings->int('max_followups')) {
                return sprintf('The follow-up limit (%d) for this lead has been reached.', $this->settings->int('max_followups'));
            }
        }

        $token = bin2hex(random_bytes(16));
        $this->db->transaction(function () use ($draft, $lead, $recipient, $token): void {
            $this->db->insert('messages', [
                'draft_id' => (int) $draft['id'],
                'lead_id' => (int) $lead['id'],
                'channel' => 'email',
                'recipient' => $recipient,
                'subject' => (string) $draft['subject'],
                'body' => $this->composer->composeBody((string) $draft['body'], $recipient, $token, (string) $draft['language']),
                'status' => 'queued',
                'is_followup' => (int) $draft['is_followup'],
                'unsubscribe_token' => $token,
                'error' => '',
                'queued_at' => $this->clock->nowString(),
            ]);
            $this->db->update('drafts', ['status' => 'queued'], 'id = :id', [':id' => (int) $draft['id']]);
        });
        $this->leads->advance((int) $lead['id'], Status::APPROVED);
        $this->leads->log((int) $lead['id'], 'queued', 'Email queued: ' . (string) $draft['subject']);

        return null;
    }

    public function sentToday(): int
    {
        [$from, $to] = $this->clock->todayRangeUtc();

        return (int) $this->db->value("SELECT COUNT(*) FROM messages WHERE channel = 'email' AND status = 'sent' AND sent_at >= ? AND sent_at < ?", [$from, $to]);
    }

    public function remainingToday(): int
    {
        return max(0, $this->settings->int('daily_send_cap') - $this->sentToday());
    }

    /**
     * Sends queued emails until the queue is empty or the daily cap is reached.
     *
     * @return array{sent: int, failed: int, skipped: int, remaining_cap: int, message: string}
     */
    public function process(?int $limit = null): array
    {
        $summary = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'remaining_cap' => $this->remainingToday(), 'message' => ''];
        $missing = $this->product->missingSenderFields();
        if ($missing !== []) {
            $summary['message'] = 'Sending is blocked until the sender ' . implode(', ', $missing) . ' are set in config/product.php.';

            return $summary;
        }
        $budget = $summary['remaining_cap'];
        if ($limit !== null) {
            $budget = min($budget, $limit);
        }
        if ($budget <= 0) {
            $summary['message'] = 'Daily send cap reached; the queue will continue tomorrow.';

            return $summary;
        }

        $queued = $this->db->all("SELECT * FROM messages WHERE channel = 'email' AND status = 'queued' ORDER BY id ASC LIMIT " . (int) $budget);
        foreach ($queued as $message) {
            $id = (int) $message['id'];
            // Re-check right before sending: an unsubscribe may have arrived since queueing.
            if ($this->remainingToday() <= 0) {
                break;
            }
            $lead = $message['lead_id'] !== null ? $this->leads->find((int) $message['lead_id']) : null;
            $reason = null;
            if ($this->suppression->isEmailSuppressed((string) $message['recipient'])) {
                $reason = 'Recipient is on the suppression list';
            } elseif ($lead === null) {
                $reason = 'Lead was deleted';
            } elseif (in_array($lead['status'], [Status::DO_NOT_CONTACT, Status::NOT_INTERESTED, Status::REPLIED, Status::CUSTOMER], true)) {
                $reason = 'Lead status is now ' . Status::label((string) $lead['status']);
            }
            if ($reason !== null) {
                $this->db->update('messages', ['status' => 'skipped', 'error' => $reason], 'id = :id', [':id' => $id]);
                $summary['skipped']++;
                continue;
            }

            try {
                $this->mailer->send($this->composer->toEmail((string) $message['recipient'], (string) $message['subject'], (string) $message['body'], (string) $message['unsubscribe_token']));
            } catch (MailException $e) {
                $this->db->update('messages', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)], 'id = :id', [':id' => $id]);
                $summary['failed']++;
                continue;
            }
            $now = $this->clock->nowString();
            $this->db->update('messages', ['status' => 'sent', 'sent_at' => $now, 'error' => ''], 'id = :id', [':id' => $id]);
            if ($message['draft_id'] !== null) {
                $this->db->update('drafts', ['status' => 'sent'], 'id = :id', [':id' => (int) $message['draft_id']]);
            }
            $this->leads->advance((int) $lead['id'], Status::CONTACTED);
            $this->leads->setFields((int) $lead['id'], ['contacted_at' => $now]);
            $this->leads->log((int) $lead['id'], 'sent', 'Email sent to ' . $message['recipient'] . ': ' . $message['subject']);
            $summary['sent']++;
        }
        $summary['remaining_cap'] = $this->remainingToday();
        $summary['message'] = sprintf('%d sent, %d failed, %d skipped. %d left in today\'s cap.', $summary['sent'], $summary['failed'], $summary['skipped'], $summary['remaining_cap']);

        return $summary;
    }

    /**
     * Records that the user sent an approved LinkedIn/WhatsApp draft by hand.
     *
     * @param array<string, mixed> $draft
     */
    public function markManualSent(array $draft): ?string
    {
        if (!in_array($draft['channel'], ['linkedin', 'whatsapp'], true) || $draft['status'] !== 'approved') {
            return 'Only approved LinkedIn or WhatsApp drafts can be marked as sent.';
        }
        $lead = $this->leads->find((int) $draft['lead_id']);
        if ($lead === null) {
            return 'Lead not found.';
        }
        if ($draft['channel'] === 'whatsapp' && $this->suppression->isPhoneSuppressed((string) $lead['phone'], (string) $lead['country'])) {
            return 'This phone number is on the suppression list.';
        }
        $now = $this->clock->nowString();
        $this->db->insert('messages', [
            'draft_id' => (int) $draft['id'],
            'lead_id' => (int) $lead['id'],
            'channel' => (string) $draft['channel'],
            'recipient' => $draft['channel'] === 'whatsapp' ? (string) $lead['phone'] : ((string) $lead['linkedin_url'] !== '' ? (string) $lead['linkedin_url'] : (string) $lead['name']),
            'subject' => '',
            'body' => (string) $draft['body'],
            'status' => 'sent',
            'is_followup' => (int) $draft['is_followup'],
            'unsubscribe_token' => null,
            'error' => '',
            'queued_at' => $now,
            'sent_at' => $now,
        ]);
        $this->db->update('drafts', ['status' => 'sent'], 'id = :id', [':id' => (int) $draft['id']]);
        $this->leads->advance((int) $lead['id'], Status::CONTACTED);
        if (empty($lead['contacted_at'])) {
            $this->leads->setFields((int) $lead['id'], ['contacted_at' => $now]);
        }
        $this->leads->log((int) $lead['id'], 'sent', ucfirst((string) $draft['channel']) . ' message sent manually');

        return null;
    }

    public function cancel(int $messageId): void
    {
        $message = $this->db->one("SELECT * FROM messages WHERE id = ? AND status IN ('queued', 'failed')", [$messageId]);
        if ($message === null) {
            return;
        }
        $this->db->update('messages', ['status' => 'skipped', 'error' => 'Cancelled by user'], 'id = :id', [':id' => $messageId]);
        if ($message['draft_id'] !== null) {
            $this->db->update('drafts', ['status' => 'approved'], 'id = :id', [':id' => (int) $message['draft_id']]);
        }
    }

    public function retry(int $messageId): void
    {
        $this->db->run("UPDATE messages SET status = 'queued', error = '' WHERE id = ? AND status = 'failed'", [$messageId]);
    }

    /** @return list<array<string, mixed>> */
    public function messages(string $status = '', int $limit = 200): array
    {
        $sql = 'SELECT m.*, l.name AS lead_name FROM messages m LEFT JOIN leads l ON l.id = m.lead_id';
        $params = [];
        if ($status !== '') {
            $sql .= ' WHERE m.status = ?';
            $params[] = $status;
        }

        return $this->db->all($sql . ' ORDER BY m.id DESC LIMIT ' . max(1, $limit), $params);
    }
}
