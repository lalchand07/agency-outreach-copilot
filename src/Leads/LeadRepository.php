<?php

declare(strict_types=1);

namespace App\Leads;

use App\Db\Database;
use App\Outreach\Suppression;
use App\Pipeline\Status;
use App\Support\Clock;

final class LeadRepository
{
    /** Columns a user may edit directly. */
    public const EDITABLE = ['name', 'website', 'email', 'phone', 'contact_name', 'linkedin_url', 'city', 'country', 'language', 'notes', 'next_action_at', 'campaign_id'];

    public function __construct(private Database $db, private Clock $clock, private Suppression $suppression)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, string $source): int
    {
        $now = $this->clock->nowString();
        $row = $this->normalise($data) + [
            'source' => $source,
            'status' => Status::NEW,
            'status_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'score_reason' => '',
        ];
        if (isset($data['place_id']) && is_string($data['place_id']) && $data['place_id'] !== '') {
            $row['place_id'] = $data['place_id'];
        }
        $id = $this->db->insert('leads', $row);
        $this->log($id, 'created', 'Added from ' . $source);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->one('SELECT l.*, c.name AS campaign_name FROM leads l LEFT JOIN campaigns c ON c.id = l.campaign_id WHERE l.id = ?', [$id]);
        if ($row !== null) {
            foreach (['notes', 'score_reason'] as $col) {
                $row[$col] = (string) ($row[$col] ?? '');
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $row = $this->normalise(array_intersect_key($data, array_flip(self::EDITABLE)));
        $row['updated_at'] = $this->clock->nowString();
        $this->db->update('leads', $row, 'id = :id', [':id' => $id]);
    }

    /** @param array<string, mixed> $fields internal fields (score, researched_at, …) */
    public function setFields(int $id, array $fields): void
    {
        $fields['updated_at'] = $this->clock->nowString();
        $this->db->update('leads', $fields, 'id = :id', [':id' => $id]);
    }

    public function setStatus(int $id, string $status, string $detail = ''): void
    {
        if (!Status::isValid($status)) {
            throw new \InvalidArgumentException('Unknown status ' . $status);
        }
        $lead = $this->find($id);
        if ($lead === null || $lead['status'] === $status) {
            return;
        }
        $now = $this->clock->nowString();
        $fields = ['status' => $status, 'status_changed_at' => $now, 'updated_at' => $now];
        if ($status === Status::CONTACTED && empty($lead['contacted_at'])) {
            $fields['contacted_at'] = $now;
        }
        $this->db->update('leads', $fields, 'id = :id', [':id' => $id]);
        $this->log($id, 'status', Status::label((string) $lead['status']) . ' → ' . Status::label($status) . ($detail !== '' ? ' (' . $detail . ')' : ''));

        if ($status === Status::DO_NOT_CONTACT) {
            $this->suppression->suppressLead($lead, 'Marked do not contact');
            // Withdraw anything still waiting to go out.
            $this->db->run("UPDATE messages SET status = 'skipped', error = 'Lead marked do not contact' WHERE lead_id = ? AND status = 'queued'", [$id]);
            $this->db->run("UPDATE drafts SET status = 'rejected', decided_at = ? WHERE lead_id = ? AND status IN ('pending', 'approved')", [$now, $id]);
        }
    }

    /** Moves a lead forward to $status, but never backwards (e.g. drafting a contacted lead). */
    public function advance(int $id, string $status): void
    {
        $lead = $this->find($id);
        if ($lead !== null && !in_array($lead['status'], Status::CLOSED, true) && Status::rank((string) $lead['status']) < Status::rank($status)) {
            $this->setStatus($id, $status);
        }
    }

    /**
     * @param array{status?: string, campaign_id?: int, q?: string, due?: bool} $filters
     *
     * @return list<array<string, mixed>>
     */
    public function search(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->where($filters);
        $sql = 'SELECT l.*, c.name AS campaign_name FROM leads l LEFT JOIN campaigns c ON c.id = l.campaign_id'
            . $where . ' ORDER BY COALESCE(l.score, -1) DESC, l.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        return $this->db->all($sql, $params);
    }

    /** @param array{status?: string, campaign_id?: int, q?: string, due?: bool} $filters */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->where($filters);

        return (int) $this->db->value('SELECT COUNT(*) FROM leads l' . $where, $params);
    }

    /** @return array<string, int> */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(array_keys(Status::ALL), 0);
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM leads GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return array<string, mixed>|null */
    public function latestResearch(int $leadId): ?array
    {
        $row = $this->db->one('SELECT * FROM research WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$leadId]);
        if ($row === null) {
            return null;
        }
        foreach (['pages', 'facts', 'result', 'dropped'] as $col) {
            $decoded = json_decode((string) ($row[$col] ?? ''), true);
            $row[$col] = is_array($decoded) ? $decoded : [];
        }
        $row['error'] = (string) ($row['error'] ?? '');

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function activity(int $leadId): array
    {
        return $this->db->all('SELECT * FROM activity WHERE lead_id = ? ORDER BY id DESC LIMIT 50', [$leadId]);
    }

    public function log(?int $leadId, string $type, string $detail = ''): void
    {
        $this->db->insert('activity', ['lead_id' => $leadId, 'type' => $type, 'detail' => mb_substr($detail, 0, 1000), 'created_at' => $this->clock->nowString()]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM leads WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $row = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, self::EDITABLE, true)) {
                continue;
            }
            if ($key === 'campaign_id') {
                $row[$key] = $value === null || $value === '' ? null : (int) $value;
                continue;
            }
            if ($key === 'next_action_at') {
                $date = trim((string) $value);
                $row[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date . ' 09:00:00' : (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date) ? $date : null);
                continue;
            }
            $row[$key] = mb_substr(trim((string) $value), 0, $key === 'notes' ? 5000 : 500);
        }
        if (array_key_exists('website', $row)) {
            $website = (string) $row['website'];
            if ($website !== '' && !preg_match('#^https?://#i', $website)) {
                $website = 'https://' . $website;
            }
            $row['website'] = preg_match('#^https?://#i', $website) ? $website : '';
            $row['domain'] = Deduplicator::dedupDomain($row['website']);
        }
        if (array_key_exists('email', $row)) {
            $row['email'] = Normalizer::email((string) $row['email']) ?? '';
        }
        if (array_key_exists('phone', $row)) {
            $row['phone_normalized'] = Normalizer::phone((string) $row['phone'], (string) ($row['country'] ?? $data['country'] ?? ''));
        }

        return $row;
    }

    /**
     * @param array{status?: string, campaign_id?: int, q?: string, due?: bool} $filters
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['status']) && Status::isValid($filters['status'])) {
            $clauses[] = 'l.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['campaign_id'])) {
            $clauses[] = 'l.campaign_id = :campaign';
            $params[':campaign'] = (int) $filters['campaign_id'];
        }
        if (!empty($filters['q'])) {
            $clauses[] = '(l.name LIKE :q1 OR l.domain LIKE :q2 OR l.email LIKE :q3 OR l.city LIKE :q4)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['q']) . '%';
            $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }
        if (!empty($filters['due'])) {
            $clauses[] = 'l.next_action_at IS NOT NULL AND l.next_action_at <= :due';
            $params[':due'] = $this->clock->nowString();
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }
}
