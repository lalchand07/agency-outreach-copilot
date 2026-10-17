<?php

declare(strict_types=1);

namespace App\Drafting;

use App\Db\Database;
use App\Leads\LeadRepository;
use App\Outreach\Outbox;
use App\Outreach\Suppression;
use App\Pipeline\Status;
use App\Support\Clock;

/**
 * Human review of drafts. Approval applies to one specific draft text: editing
 * an approved draft sends it back to "pending".
 */
final class ReviewService
{
    public function __construct(
        private Database $db,
        private LeadRepository $leads,
        private Outbox $outbox,
        private Suppression $suppression,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->one('SELECT * FROM drafts WHERE id = ?', [$id]);
        if ($row !== null) {
            foreach (['body', 'observation', 'warnings'] as $col) {
                $row[$col] = (string) ($row[$col] ?? '');
            }
        }

        return $row;
    }

    /**
     * Pending drafts grouped by lead, best score first.
     *
     * @return list<array{lead: array<string, mixed>, drafts: list<array<string, mixed>>}>
     */
    public function queue(int $limit = 50, ?int $campaignId = null): array
    {
        $sql = "SELECT d.lead_id FROM drafts d JOIN leads l ON l.id = d.lead_id WHERE d.status = 'pending'";
        $params = [];
        if ($campaignId !== null) {
            $sql .= ' AND l.campaign_id = ?';
            $params[] = $campaignId;
        }
        $sql .= ' GROUP BY d.lead_id, l.score ORDER BY COALESCE(l.score, -1) DESC, d.lead_id ASC LIMIT ' . max(1, $limit);
        $groups = [];
        foreach ($this->db->all($sql, $params) as $row) {
            $lead = $this->leads->find((int) $row['lead_id']);
            if ($lead === null) {
                continue;
            }
            $groups[] = ['lead' => $lead, 'drafts' => $this->forLead((int) $row['lead_id'], ['pending'])];
        }

        return $groups;
    }

    public function pendingCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM drafts WHERE status = 'pending'");
    }

    /**
     * @param list<string> $statuses
     *
     * @return list<array<string, mixed>>
     */
    public function forLead(int $leadId, array $statuses = []): array
    {
        $sql = 'SELECT * FROM drafts WHERE lead_id = ?';
        $params = [$leadId];
        if ($statuses !== []) {
            $sql .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            $params = array_merge($params, $statuses);
        }
        // Newest generation first; within it email, LinkedIn, WhatsApp.
        $order = "CASE channel WHEN 'email' THEN 1 WHEN 'linkedin' THEN 2 ELSE 3 END";

        return array_map(static function (array $row): array {
            foreach (['body', 'observation', 'warnings'] as $col) {
                $row[$col] = (string) ($row[$col] ?? '');
            }

            return $row;
        }, $this->db->all($sql . ' ORDER BY created_at DESC, ' . $order . ', id DESC', $params));
    }

    /**
     * Approves one draft. Emails are queued for sending; LinkedIn/WhatsApp wait
     * in the outbox for the user to send by hand. Returns an error or null.
     */
    public function approve(int $id): ?string
    {
        $draft = $this->find($id);
        if ($draft === null || $draft['status'] !== 'pending') {
            return 'Only pending drafts can be approved.';
        }
        $violations = DraftLimits::violations((string) $draft['channel'], (string) $draft['subject'], (string) $draft['body'], (bool) $draft['is_followup']);
        if ($violations !== []) {
            return 'Fix before approving: ' . implode(' ', $violations);
        }
        $lead = $this->leads->find((int) $draft['lead_id']);
        if ($lead === null || in_array($lead['status'], [Status::DO_NOT_CONTACT, Status::NOT_INTERESTED], true)) {
            return 'This lead can no longer be contacted.';
        }
        if ($draft['channel'] === 'email') {
            $hit = $this->suppression->matchesLead($lead);
            if ($hit !== null) {
                return 'Suppressed (' . $hit . '). Not approved.';
            }
        }
        $this->db->update('drafts', ['status' => 'approved', 'decided_at' => $this->clock->nowString()], 'id = :id', [':id' => $id]);
        $this->leads->log((int) $draft['lead_id'], 'approved', ucfirst((string) $draft['channel']) . ' draft #' . $id . ' approved');

        if ($draft['channel'] === 'email') {
            $draft['status'] = 'approved';
            $error = $this->outbox->queueEmail($draft);
            if ($error !== null) {
                $this->db->update('drafts', ['status' => 'pending', 'decided_at' => null], 'id = :id', [':id' => $id]);

                return $error;
            }
        } else {
            $this->leads->advance((int) $draft['lead_id'], Status::APPROVED);
        }

        return null;
    }

    public function reject(int $id): void
    {
        $draft = $this->find($id);
        if ($draft === null || !in_array($draft['status'], ['pending', 'approved'], true)) {
            return;
        }
        $this->db->update('drafts', ['status' => 'rejected', 'decided_at' => $this->clock->nowString()], 'id = :id', [':id' => $id]);
        $this->leads->log((int) $draft['lead_id'], 'rejected', ucfirst((string) $draft['channel']) . ' draft #' . $id . ' rejected');
    }

    /** Saves an edit. The draft goes (back) to pending and must be approved again. */
    public function edit(int $id, string $subject, string $body): ?string
    {
        $draft = $this->find($id);
        if ($draft === null || !in_array($draft['status'], ['pending', 'approved'], true)) {
            return 'This draft can no longer be edited.';
        }
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        $body = trim(str_replace("\r\n", "\n", $body));
        $warnings = array_merge(
            DraftLimits::violations((string) $draft['channel'], $subject, $body, (bool) $draft['is_followup']),
            DraftLimits::warnings($subject, $body)
        );
        $this->db->update('drafts', [
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            'status' => 'pending',
            'edited' => 1,
            'warnings' => implode("\n", $warnings),
            'decided_at' => null,
        ], 'id = :id', [':id' => $id]);

        return null;
    }
}
