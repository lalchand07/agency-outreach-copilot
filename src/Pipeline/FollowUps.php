<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Config\Settings;
use App\Db\Database;
use App\Drafting\DraftGenerator;
use App\Leads\LeadRepository;
use App\Support\Clock;

/**
 * Finds contacted leads with no reply after N days and drafts the follow-up.
 * The follow-up is only a draft: it still needs approval like any other.
 */
final class FollowUps
{
    public function __construct(
        private Database $db,
        private LeadRepository $leads,
        private DraftGenerator $drafts,
        private Settings $settings,
        private Clock $clock,
    ) {
    }

    /**
     * Leads due a follow-up: status "contacted", first email sent more than N
     * days ago, fewer follow-ups sent/queued than the maximum, no pending
     * follow-up draft and not suppressed.
     *
     * @return list<array{lead: array<string, mixed>, message: array<string, mixed>}>
     */
    public function due(): array
    {
        $max = $this->settings->int('max_followups');
        if ($max <= 0) {
            return [];
        }
        $cutoff = $this->clock->now()->modify('-' . $this->settings->int('followup_after_days') . ' days')->format('Y-m-d H:i:s');
        $rows = $this->db->all(
            "SELECT l.id AS lead_id, MAX(m.id) AS message_id FROM leads l
             JOIN messages m ON m.lead_id = l.id AND m.channel = 'email' AND m.status = 'sent'
             WHERE l.status = ?
             GROUP BY l.id
             HAVING MAX(m.sent_at) <= ?",
            [Status::CONTACTED, $cutoff]
        );
        $due = [];
        foreach ($rows as $row) {
            $leadId = (int) $row['lead_id'];
            $followups = (int) $this->db->value("SELECT COUNT(*) FROM messages WHERE lead_id = ? AND channel = 'email' AND is_followup = 1 AND status IN ('queued', 'sent')", [$leadId]);
            $pending = (int) $this->db->value("SELECT COUNT(*) FROM drafts WHERE lead_id = ? AND is_followup = 1 AND status IN ('pending', 'approved', 'queued')", [$leadId]);
            if ($followups >= $max || $pending > 0) {
                continue;
            }
            $lead = $this->leads->find($leadId);
            $message = $this->db->one('SELECT * FROM messages WHERE id = ?', [(int) $row['message_id']]);
            if ($lead !== null && $message !== null) {
                $due[] = ['lead' => $lead, 'message' => $message];
            }
        }

        return $due;
    }

    /**
     * Creates follow-up drafts for everything due (up to $limit).
     *
     * @return array{created: int, failed: list<string>}
     */
    public function draftDue(int $limit = 20): array
    {
        $report = ['created' => 0, 'failed' => []];
        foreach (array_slice($this->due(), 0, $limit) as $item) {
            $language = (string) ($this->db->value('SELECT language FROM drafts WHERE id = ?', [(int) $item['message']['draft_id']]) ?? 'en');
            $result = $this->drafts->generateFollowup($item['lead'], $item['message'], $language !== '' ? $language : 'en');
            if ($result['ok']) {
                $report['created']++;
            } else {
                $report['failed'][] = (string) $item['lead']['name'] . ': ' . $result['message'];
            }
        }

        return $report;
    }
}
