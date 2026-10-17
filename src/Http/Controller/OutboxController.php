<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;

final class OutboxController extends Controller
{
    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $manual = $db->all("SELECT d.*, l.name AS lead_name, l.phone, l.country, l.linkedin_url, l.website FROM drafts d JOIN leads l ON l.id = d.lead_id WHERE d.status = 'approved' AND d.channel IN ('linkedin', 'whatsapp') ORDER BY d.id");

        return $this->page('outbox/index', [
            'title' => 'Outbox',
            'queued' => $this->app->outbox()->messages('queued'),
            'failed' => $this->app->outbox()->messages('failed', 50),
            'log' => $this->app->outbox()->messages('', 200),
            'manual' => $manual,
            'sentToday' => $this->app->outbox()->sentToday(),
            'cap' => $this->app->settings()->int('daily_send_cap'),
            'missingSender' => $this->app->product()->missingSenderFields(),
            'smtpConfigured' => $this->app->env->get('SMTP_HOST') !== '',
        ]);
    }

    public function process(Request $request): Response
    {
        @set_time_limit(300);
        $summary = $this->app->outbox()->process();

        return $this->back($request, '/outbox', $summary['failed'] > 0 ? 'error' : 'success', $summary['message']);
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $this->app->outbox()->cancel((int) $params['id']);

        return $this->back($request, '/outbox', 'success', 'Removed from the queue. The draft is still approved; reject it on the lead page if you no longer want it.');
    }

    /** @param array<string, string> $params */
    public function retry(Request $request, array $params): Response
    {
        $this->app->outbox()->retry((int) $params['id']);

        return $this->back($request, '/outbox', 'success', 'Queued again.');
    }

    /** @param array<string, string> $params */
    public function markSent(Request $request, array $params): Response
    {
        $draft = $this->app->review()->find((int) $params['id']);
        if ($draft === null) {
            return $this->notFound();
        }
        $error = $this->app->outbox()->markManualSent($draft);

        return $this->back($request, '/outbox', $error === null ? 'success' : 'error', $error ?? 'Marked as sent.');
    }
}
