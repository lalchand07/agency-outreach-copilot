<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $stats = $this->app->stats();

        return $this->page('dashboard', [
            'title' => 'Dashboard',
            'byStatus' => $stats->leadsByStatus(),
            'perDay' => $stats->sentPerDay(14),
            'reply' => $stats->replyRate(),
            'ai' => $stats->aiSpend(),
            'pendingDrafts' => $this->app->review()->pendingCount(),
            'queued' => count($this->app->outbox()->messages('queued')),
            'sentToday' => $this->app->outbox()->sentToday(),
            'cap' => $this->app->settings()->int('daily_send_cap'),
            'followupsDue' => count($this->app->followUps()->due()),
            'dueLeads' => $this->app->leads()->search(['due' => true], 10),
            'product' => $this->app->product(),
        ]);
    }

    public function export(Request $request): Response
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return new Response('Export failed', 500);
        }
        $campaign = $request->int('campaign_id');
        $this->app->exporter()->write($out, $campaign > 0 ? $campaign : null);
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return new Response("\xEF\xBB\xBF" . $csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="pipeline-' . $this->app->clock()->now()->format('Y-m-d') . '.csv"',
        ]);
    }
}
