<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Drafting\DraftLimits;
use App\Http\Request;
use App\Http\Response;
use App\Pipeline\Status;

final class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = array_filter([
            'status' => $request->input('status'),
            'campaign_id' => $request->int('campaign_id'),
            'q' => $request->input('q'),
            'due' => $request->input('due') === '1',
        ]);
        $page = max(1, $request->int('page', 1));
        $leads = $this->app->leads();

        return $this->page('leads/index', [
            'title' => 'Leads',
            'leads' => $leads->search($filters, 50, ($page - 1) * 50),
            'total' => $leads->count($filters),
            'page' => $page,
            'filters' => $filters,
            'campaigns' => $this->app->campaigns()->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->page('leads/new', [
            'title' => 'Add a lead',
            'campaigns' => $this->app->campaigns()->all(),
            'campaignId' => $request->int('campaign_id'),
            'error' => '',
            'old' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->fields($request);
        $result = $this->app->sourcing()->addManual($data);
        if ($result['id'] === null) {
            return $this->page('leads/new', [
                'title' => 'Add a lead',
                'campaigns' => $this->app->campaigns()->all(),
                'campaignId' => (int) ($data['campaign_id'] ?? 0),
                'error' => (string) $result['error'],
                'old' => $data,
            ], 422);
        }

        return $this->back($request, '/leads/' . $result['id'], 'success', 'Lead added.');
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $lead = $this->app->leads()->find((int) $params['id']);
        if ($lead === null) {
            return $this->notFound();
        }
        $campaign = $this->campaignFor($lead['campaign_id'] !== null ? (int) $lead['campaign_id'] : null);

        return $this->page('leads/show', [
            'title' => (string) ($lead['name'] !== '' ? $lead['name'] : $lead['website']),
            'lead' => $lead,
            'campaign' => $campaign,
            'campaigns' => $this->app->campaigns()->all(),
            'research' => $this->app->leads()->latestResearch((int) $lead['id']),
            'drafts' => $this->app->review()->forLead((int) $lead['id']),
            'messages' => $this->app->db()->all('SELECT * FROM messages WHERE lead_id = ? ORDER BY id DESC', [(int) $lead['id']]),
            'activity' => $this->app->leads()->activity((int) $lead['id']),
            'suppressed' => $this->app->suppression()->matchesLead($lead),
            'aiEnabled' => $this->app->ai() !== null,
        ]);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if ($this->app->leads()->find($id) === null) {
            return $this->notFound();
        }
        $this->app->leads()->update($id, $this->fields($request));

        return $this->back($request, '/leads/' . $id, 'success', 'Lead saved.');
    }

    /** @param array<string, string> $params */
    public function status(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $status = $request->input('status');
        if ($this->app->leads()->find($id) === null || !Status::isValid($status)) {
            return $this->notFound();
        }
        $this->app->leads()->setStatus($id, $status, 'set by user');
        $next = $request->input('next_action_at');
        if ($next !== '') {
            $this->app->leads()->update($id, ['next_action_at' => $next]);
        }

        return $this->back($request, '/leads/' . $id, 'success', 'Status set to ' . Status::label($status) . '.');
    }

    /** @param array<string, string> $params */
    public function research(Request $request, array $params): Response
    {
        $lead = $this->app->leads()->find((int) $params['id']);
        if ($lead === null) {
            return $this->notFound();
        }
        @set_time_limit(120);
        $campaign = $this->campaignFor($lead['campaign_id'] !== null ? (int) $lead['campaign_id'] : null) ?? [];
        $result = $this->app->researcher()->research($lead, $campaign);
        if ($result['ok']) {
            $score = $this->app->scorer()->score($this->app->leads()->find((int) $lead['id']) ?? $lead, $campaign);
            $result['message'] .= sprintf(' Score: %d.', $score['score']);
        }

        return $this->back($request, '/leads/' . $lead['id'], $result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** @param array<string, string> $params */
    public function score(Request $request, array $params): Response
    {
        $lead = $this->app->leads()->find((int) $params['id']);
        if ($lead === null) {
            return $this->notFound();
        }
        $campaign = $this->campaignFor($lead['campaign_id'] !== null ? (int) $lead['campaign_id'] : null) ?? [];
        $score = $this->app->scorer()->score($lead, $campaign);

        return $this->back($request, '/leads/' . $lead['id'], 'success', sprintf('Score %d: %s', $score['score'], $score['explanation']));
    }

    /** @param array<string, string> $params */
    public function draft(Request $request, array $params): Response
    {
        $lead = $this->app->leads()->find((int) $params['id']);
        if ($lead === null) {
            return $this->notFound();
        }
        @set_time_limit(120);
        $campaign = $this->campaignFor($lead['campaign_id'] !== null ? (int) $lead['campaign_id'] : null) ?? [];
        $language = $request->input('language', (string) (($campaign['languages'][0] ?? 'en')));
        $channels = $request->list('channels') ?: (array) ($campaign['channels'] ?? ['email']);
        $result = $this->app->drafts()->generate($lead, $channels, $language, mb_substr($request->input('instruction'), 0, 300), $request->input('fresh') === '1');

        return $this->back($request, '/leads/' . $lead['id'], $result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** @param array<string, string> $params */
    public function manualDraft(Request $request, array $params): Response
    {
        $lead = $this->app->leads()->find((int) $params['id']);
        if ($lead === null) {
            return $this->notFound();
        }
        $channel = $request->input('channel', 'email');
        if (!in_array($channel, ['email', 'linkedin', 'whatsapp'], true)) {
            return $this->back($request, '/leads/' . $lead['id'], 'error', 'Unknown channel.');
        }
        $subject = $request->input('subject');
        $body = str_replace("\r\n", "\n", (string) ($request->post['body'] ?? ''));
        $this->app->drafts()->store((int) $lead['id'], $channel, $request->input('language', 'en'), $subject, trim($body), 'Written by hand', $request->input('followup') === '1');
        $this->app->leads()->advance((int) $lead['id'], Status::DRAFTED);
        $problems = DraftLimits::violations($channel, $subject, $body, $request->input('followup') === '1');

        return $this->back($request, '/leads/' . $lead['id'], $problems === [] ? 'success' : 'error', $problems === [] ? 'Draft saved for review.' : 'Draft saved, but fix before approving: ' . implode(' ', $problems));
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        $this->app->leads()->delete((int) $params['id']);

        return $this->back($request, '/leads', 'success', 'Lead deleted.');
    }

    /** @return array<string, mixed> */
    private function fields(Request $request): array
    {
        $data = [];
        foreach (['name', 'website', 'email', 'phone', 'contact_name', 'linkedin_url', 'city', 'country', 'language', 'next_action_at', 'campaign_id'] as $field) {
            if (array_key_exists($field, $request->post)) {
                $data[$field] = $request->input($field);
            }
        }
        if (array_key_exists('notes', $request->post)) {
            $data['notes'] = str_replace("\r\n", "\n", (string) $request->post['notes']);
        }

        return $data;
    }
}
