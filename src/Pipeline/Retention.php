<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Config\Settings;
use App\Db\Database;
use App\Outreach\Suppression;
use App\Support\Clock;

/**
 * Data retention: N days after a lead became "not interested" or "do not
 * contact", its details (lead row, research, drafts, notes, activity) are
 * deleted. Their suppression entries stay, so they are never contacted again,
 * and the send log keeps only a hash of the recipient.
 */
final class Retention
{
    public function __construct(private Database $db, private Settings $settings, private Suppression $suppression, private Clock $clock)
    {
    }

    /** @return list<array<string, mixed>> leads that would be deleted now */
    public function candidates(): array
    {
        $cutoff = $this->clock->now()->modify('-' . $this->settings->int('retention_days') . ' days')->format('Y-m-d H:i:s');

        return $this->db->all(
            'SELECT * FROM leads WHERE status IN (?, ?) AND status_changed_at <= ? ORDER BY id',
            [Status::NOT_INTERESTED, Status::DO_NOT_CONTACT, $cutoff]
        );
    }

    /** @return int number of leads deleted */
    public function run(): int
    {
        $deleted = 0;
        foreach ($this->candidates() as $lead) {
            $this->db->transaction(function () use ($lead): void {
                $id = (int) $lead['id'];
                // Make sure they stay suppressed after their details are gone.
                $this->suppression->suppressLead($lead, Status::label((string) $lead['status']) . ' (kept after retention)');
                foreach ($this->db->all('SELECT id, recipient FROM messages WHERE lead_id = ?', [$id]) as $message) {
                    $this->db->update('messages', [
                        'recipient' => 'sha256:' . hash('sha256', strtolower((string) $message['recipient'])),
                        'subject' => '',
                        'body' => '',
                        'lead_id' => null,
                    ], 'id = :id', [':id' => (int) $message['id']]);
                }
                $this->db->run('DELETE FROM leads WHERE id = ?', [$id]);
            });
            $deleted++;
        }

        return $deleted;
    }
}
