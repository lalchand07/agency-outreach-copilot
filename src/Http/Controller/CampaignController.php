<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Leads\PlacesClient;
use App\Pipeline\Status;

final class CampaignController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->page('campaigns/index', ['title' => 'Campaigns', 'campaigns' => $this->app->campaigns()->all()]);
    }

    public function create(Request $request): Response
    {
        return $this->page('campaigns/form', ['title' => 'New campaign', 'campaign' => null, 'product' => $this->app->product()]);
    }

    public function store(Request $request): Response
    {
        $id = $this->app->campaigns()->save($this->input($request));

        return $this->back($request, '/campaigns/' . $id, 'success', 'Campaign saved.');
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $campaign = $this->campaignFor((int) $params['id']);
        if ($campaign === null) {
            return $this->notFound();
        }
        $leads = $this->app->leads();

        return $this->page('campaigns/show', [
            'title' => (string) $campaign['name'],
            'campaign' => $campaign,
            'leads' => $leads->search(['campaign_id' => (int) $campaign['id']], 200),
            'newCount' => $leads->count(['campaign_id' => (int) $campaign['id'], 'status' => Status::NEW]),
            'product' => $this->app->product(),
            'aiEnabled' => $this->app->ai() !== null,
        ]);
    }

    /** @param array<string, string> $params */
    public function edit(Request $request, array $params): Response
    {
        $campaign = $this->campaignFor((int) $params['id']);
        if ($campaign === null) {
            return $this->notFound();
        }

        return $this->page('campaigns/form', ['title' => 'Edit campaign', 'campaign' => $campaign, 'product' => $this->app->product()]);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if ($this->campaignFor($id) === null) {
            return $this->notFound();
        }
        $this->app->campaigns()->save($this->input($request), $id);

        return $this->back($request, '/campaigns/' . $id, 'success', 'Campaign saved.');
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        $this->app->campaigns()->delete((int) $params['id']);

        return $this->back($request, '/campaigns', 'success', 'Campaign deleted. Its leads were kept without a campaign.');
    }

    /** @param array<string, string> $params */
    public function places(Request $request, array $params): Response
    {
        $campaign = $this->campaignFor((int) $params['id']);
        if ($campaign === null) {
            return $this->notFound();
        }
        $query = $request->input('query');
        $city = $request->input('city');
        $results = null;
        if ($request->isPost() && $query !== '') {
            $results = $this->app->places()->textSearch(
                trim($query . ($city !== '' ? ' in ' . $city : '') . ((string) $campaign['country'] !== '' ? ', ' . $campaign['country'] : '')),
                PlacesClient::regionCode((string) $campaign['country']),
                'en',
                $request->input('page_token') ?: null
            );
        }

        return $this->page('campaigns/places', [
            'title' => 'Find businesses: ' . $campaign['name'],
            'campaign' => $campaign,
            'query' => $query !== '' ? $query : (string) (($campaign['keywords'][0] ?? '')),
            'city' => $city !== '' ? $city : (string) (($campaign['cities'][0] ?? '')),
            'results' => $results,
            'configured' => $this->app->places()->isConfigured(),
        ]);
    }

    /** @param array<string, string> $params */
    public function placesAdd(Request $request, array $params): Response
    {
        $campaign = $this->campaignFor((int) $params['id']);
        if ($campaign === null) {
            return $this->notFound();
        }
        $selected = [];
        $websites = (array) ($request->post['website'] ?? []);
        foreach ($request->list('place_id') as $placeId) {
            $selected[] = ['place_id' => $placeId, 'website' => is_string($websites[$placeId] ?? null) ? $websites[$placeId] : ''];
        }
        if ($selected === []) {
            return $this->back($request, '/campaigns/' . $campaign['id'] . '/places', 'error', 'Select at least one business.');
        }
        @set_time_limit(120);
        $report = $this->app->sourcing()->addFromPlaces($selected, $campaign, $request->input('city'));
        $this->session->set('_report', ['title' => 'Google Places import'] + $report);

        return $this->back($request, '/campaigns/' . $campaign['id'], $report['imported'] > 0 ? 'success' : 'info', sprintf('%d lead(s) added, %d duplicate(s), %d skipped.', $report['imported'], count($report['duplicates']), count($report['skipped'])));
    }

    /**
     * Research, score and draft the next few "new" leads. Kept small so it
     * finishes within shared-hosting time limits; use bin/console for bulk.
     *
     * @param array<string, string> $params
     */
    public function process(Request $request, array $params): Response
    {
        $campaign = $this->campaignFor((int) $params['id']);
        if ($campaign === null) {
            return $this->notFound();
        }
        @set_time_limit(300);
        $batch = max(1, min(5, $request->int('batch', 3)));
        $done = [];
        foreach ($this->app->leads()->search(['campaign_id' => (int) $campaign['id'], 'status' => Status::NEW], $batch) as $lead) {
            $messages = $this->app->processLead((int) $lead['id'], $request->input('draft', '1') === '1');
            $done[] = ($lead['name'] !== '' ? $lead['name'] : $lead['website']) . ': ' . implode(' ', $messages);
        }
        if ($done === []) {
            return $this->back($request, '/campaigns/' . $campaign['id'], 'info', 'No new leads to process.');
        }
        $this->session->set('_report', ['title' => 'Processed leads', 'imported' => count($done), 'duplicates' => [], 'skipped' => $done]);

        return $this->back($request, '/campaigns/' . $campaign['id'], 'success', sprintf('Processed %d lead(s). Review the drafts in the review queue.', count($done)));
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return [
            'name' => $request->input('name'),
            'country' => $request->input('country'),
            'cities' => $request->input('cities'),
            'keywords' => $request->input('keywords'),
            'languages' => $request->list('languages'),
            'channels' => $request->list('channels'),
            'ideal_customer' => $request->input('ideal_customer'),
            'notes' => $request->input('notes'),
        ];
    }
}
