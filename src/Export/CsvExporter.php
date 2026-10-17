<?php

declare(strict_types=1);

namespace App\Export;

use App\Db\Database;
use App\Pipeline\Status;

/**
 * Pipeline export. Cells that a spreadsheet would treat as a formula are
 * prefixed with a quote (CSV injection).
 */
final class CsvExporter
{
    public const COLUMNS = [
        'id', 'campaign', 'name', 'status', 'score', 'score_reason', 'website', 'email', 'phone', 'contact_name',
        'linkedin_url', 'city', 'country', 'source', 'next_action_at', 'contacted_at', 'emails_sent', 'last_sent_at', 'notes', 'created_at',
    ];

    public function __construct(private Database $db)
    {
    }

    /** @param resource $out */
    public function write($out, ?int $campaignId = null): int
    {
        fputcsv($out, self::COLUMNS, ',', '"', '');
        $sql = "SELECT l.*, c.name AS campaign,
                (SELECT COUNT(*) FROM messages m WHERE m.lead_id = l.id AND m.status = 'sent') AS emails_sent,
                (SELECT MAX(sent_at) FROM messages m WHERE m.lead_id = l.id AND m.status = 'sent') AS last_sent_at
                FROM leads l LEFT JOIN campaigns c ON c.id = l.campaign_id";
        $params = [];
        if ($campaignId !== null) {
            $sql .= ' WHERE l.campaign_id = ?';
            $params[] = $campaignId;
        }
        $count = 0;
        foreach ($this->db->all($sql . ' ORDER BY l.id', $params) as $row) {
            $row['status'] = Status::label((string) $row['status']);
            $line = [];
            foreach (self::COLUMNS as $col) {
                $line[] = self::safe((string) ($row[$col] ?? ''));
            }
            fputcsv($out, $line, ',', '"', '');
            $count++;
        }

        return $count;
    }

    public static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
