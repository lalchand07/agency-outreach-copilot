<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Config\Settings;
use App\Http\Request;
use App\Http\Response;

final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $product = $this->app->product();

        return $this->page('settings/index', [
            'title' => 'Settings',
            'settings' => $this->app->settings()->all(),
            'product' => $product,
            'checks' => [
                'Product profile (config/product.php)' => !$product->isExample,
                'Sender name, company, email and postal address' => $product->missingSenderFields() === [],
                'AI provider key' => $this->app->ai() !== null,
                'Google Places API key' => $this->app->places()->isConfigured(),
                'SMTP server' => $this->app->env->get('SMTP_HOST') !== '',
                'APP_URL is HTTPS (unsubscribe links)' => str_starts_with($this->app->appUrl(), 'https://'),
            ],
            'retentionCandidates' => count($this->app->retention()->candidates()),
            'followupsDue' => count($this->app->followUps()->due()),
        ]);
    }

    public function save(Request $request): Response
    {
        foreach (array_keys(Settings::DEFAULTS) as $name) {
            if (array_key_exists($name, $request->post)) {
                $this->app->settings()->set($name, $request->int($name, Settings::DEFAULTS[$name]));
            }
        }

        return $this->back($request, '/settings', 'success', 'Settings saved.');
    }

    public function followups(Request $request): Response
    {
        @set_time_limit(300);
        $report = $this->app->followUps()->draftDue(10);

        return $this->back($request, '/review', $report['failed'] === [] ? 'success' : 'error', sprintf('%d follow-up draft(s) created.%s', $report['created'], $report['failed'] !== [] ? ' Problems: ' . implode(' ', $report['failed']) : ''));
    }

    public function retention(Request $request): Response
    {
        $deleted = $this->app->retention()->run();

        return $this->back($request, '/settings', 'success', sprintf('%d lead(s) deleted under the retention policy. Their suppression entries were kept.', $deleted));
    }

    public function suppression(Request $request): Response
    {
        return $this->page('settings/suppression', ['title' => 'Suppression list', 'entries' => $this->app->suppression()->all()]);
    }

    public function suppressionAdd(Request $request): Response
    {
        $type = $request->input('type');
        $added = 0;
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', $request->input('values')) ?: [] as $value) {
            if ($value === '') {
                continue;
            }
            if (in_array($type, ['email', 'domain', 'phone'], true) && $this->app->suppression()->add($type, $value, $request->input('reason', 'Added manually'))) {
                $added++;
            } else {
                $invalid[] = $value;
            }
        }

        return $this->back($request, '/suppression', $invalid === [] ? 'success' : 'error', sprintf('%d entr%s added.', $added, $added === 1 ? 'y' : 'ies') . ($invalid !== [] ? ' Not valid: ' . implode(', ', array_slice($invalid, 0, 10)) : ''));
    }

    /** @param array<string, string> $params */
    public function suppressionDelete(Request $request, array $params): Response
    {
        $this->app->suppression()->remove((int) $params['id']);

        return $this->back($request, '/suppression', 'success', 'Entry removed. Only do this if the person asked to hear from you again.');
    }
}
