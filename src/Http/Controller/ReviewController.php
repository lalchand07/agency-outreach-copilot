<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;

final class ReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $campaign = $request->int('campaign_id');
        $groups = $this->app->review()->queue(30, $campaign > 0 ? $campaign : null);
        $research = [];
        foreach ($groups as $group) {
            $research[(int) $group['lead']['id']] = $this->app->leads()->latestResearch((int) $group['lead']['id']);
        }

        return $this->page('review/index', [
            'title' => 'Review queue',
            'groups' => $groups,
            'research' => $research,
            'campaigns' => $this->app->campaigns()->all(),
            'campaignId' => $campaign,
            'remaining' => $this->app->outbox()->remainingToday(),
        ]);
    }

    /** @param array<string, string> $params */
    public function approve(Request $request, array $params): Response
    {
        // Approval applies to the saved text: refuse if the reviewer changed the box without saving.
        $draft = $this->app->review()->find((int) $params['id']);
        if ($draft !== null && array_key_exists('body', $request->post)) {
            $body = trim(str_replace("\r\n", "\n", (string) $request->post['body']));
            $subject = trim($request->input('subject', (string) $draft['subject']));
            if ($body !== trim((string) $draft['body']) || ($draft['channel'] === 'email' && $subject !== trim((string) $draft['subject']))) {
                return $this->result($request, (int) $params['id'], 'You changed the text: save the edit first, then approve.', '');
            }
        }
        $error = $this->app->review()->approve((int) $params['id']);

        return $this->result($request, (int) $params['id'], $error, 'Approved.');
    }

    /** @param array<string, string> $params */
    public function reject(Request $request, array $params): Response
    {
        $this->app->review()->reject((int) $params['id']);

        return $this->result($request, (int) $params['id'], null, 'Rejected.');
    }

    /** @param array<string, string> $params */
    public function edit(Request $request, array $params): Response
    {
        $error = $this->app->review()->edit((int) $params['id'], $request->input('subject'), (string) ($request->post['body'] ?? ''));

        return $this->result($request, (int) $params['id'], $error, 'Saved. Approve it when ready.');
    }

    /** @param array<string, string> $params */
    public function regenerate(Request $request, array $params): Response
    {
        $draft = $this->app->review()->find((int) $params['id']);
        $lead = $draft !== null ? $this->app->leads()->find((int) $draft['lead_id']) : null;
        if ($draft === null || $lead === null) {
            return $this->notFound();
        }
        @set_time_limit(120);
        $campaign = $this->campaignFor($lead['campaign_id'] !== null ? (int) $lead['campaign_id'] : null) ?? [];
        $result = $this->app->drafts()->generate($lead, (array) ($campaign['channels'] ?? [$draft['channel']]), $request->input('language', (string) $draft['language']), mb_substr($request->input('instruction'), 0, 300), true);

        return $this->back($request, '/review', $result['ok'] ? 'success' : 'error', $result['ok'] ? 'New drafts generated.' : $result['message']);
    }

    private function result(Request $request, int $id, ?string $error, string $ok): Response
    {
        if ($request->isHtmx()) {
            $draft = $this->app->review()->find($id);
            if ($draft === null) {
                return new Response('', 200);
            }
            $lead = $this->app->leads()->find((int) $draft['lead_id']);

            return $this->fragment('review/_draft', ['draft' => $draft, 'lead' => $lead ?? [], 'error' => $error, 'notice' => $error === null ? $ok : '']);
        }

        return $this->back($request, '/review', $error === null ? 'success' : 'error', $error ?? $ok);
    }
}
