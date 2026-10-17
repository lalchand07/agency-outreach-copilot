<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Db\Database;
use App\Leads\LeadRepository;
use App\Support\Clock;
use PhpAiGuard\AiGuard;
use PhpAiGuard\Report\Reporter;

/**
 * Numbers for the dashboard.
 */
final class Stats
{
    public function __construct(private Database $db, private LeadRepository $leads, private Clock $clock, private ?AiGuard $ai)
    {
    }

    /** @return array<string, int> */
    public function leadsByStatus(): array
    {
        return $this->leads->countByStatus();
    }

    /**
     * Messages sent per local day for the last $days days (oldest first).
     *
     * @return array<string, array{email: int, manual: int}>
     */
    public function sentPerDay(int $days = 14): array
    {
        $out = [];
        $today = $this->clock->now()->setTimezone($this->clock->timezone());
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[$today->modify("-{$i} days")->format('Y-m-d')] = ['email' => 0, 'manual' => 0];
        }
        $since = $this->clock->now()->modify('-' . ($days + 1) . ' days')->format('Y-m-d H:i:s');
        foreach ($this->db->all("SELECT channel, sent_at FROM messages WHERE status = 'sent' AND sent_at >= ?", [$since]) as $row) {
            $day = $this->clock->localDate((string) $row['sent_at']);
            if (isset($out[$day])) {
                $out[$day][$row['channel'] === 'email' ? 'email' : 'manual']++;
            }
        }

        return $out;
    }

    /**
     * Reply rate: leads that replied (or went further) out of leads contacted.
     *
     * @return array{contacted: int, replied: int, rate: float}
     */
    public function replyRate(): array
    {
        $counts = $this->leadsByStatus();
        $replied = $counts['replied'] + $counts['demo_booked'] + $counts['trial'] + $counts['customer'] + $counts['not_interested'];
        $contacted = $replied + $counts['contacted'];
        // Unsubscribed leads that were contacted also count as contacted.
        $contacted += (int) $this->db->value("SELECT COUNT(*) FROM leads WHERE status = 'do_not_contact' AND contacted_at IS NOT NULL");

        return ['contacted' => $contacted, 'replied' => $replied, 'rate' => $contacted > 0 ? round($replied / $contacted * 100, 1) : 0.0];
    }

    /**
     * AI spend this month from php-ai-guard's usage log.
     *
     * @return array{enabled: bool, total: float, remaining: float|null, by_feature: list<array<string, mixed>>, error: string|null}
     */
    public function aiSpend(): array
    {
        if ($this->ai === null) {
            return ['enabled' => false, 'total' => 0.0, 'remaining' => null, 'by_feature' => [], 'error' => null];
        }
        try {
            $month = $this->clock->now()->setTimezone($this->clock->timezone())->format('Y-m');
            $rows = $this->ai->reporter()->month($month, 'feature');
            $totals = Reporter::totals($rows);

            return [
                'enabled' => true,
                'total' => $totals['cost_usd'],
                'remaining' => $this->ai->remainingBudget('global', null, 'monthly'),
                'by_feature' => $rows,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return ['enabled' => true, 'total' => 0.0, 'remaining' => null, 'by_feature' => [], 'error' => $e->getMessage()];
        }
    }
}
