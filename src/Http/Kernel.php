<?php

declare(strict_types=1);

namespace App\Http;

use App\App;
use App\Http\Controller\AuthController;
use App\Http\Controller\CampaignController;
use App\Http\Controller\DashboardController;
use App\Http\Controller\ImportController;
use App\Http\Controller\LeadController;
use App\Http\Controller\OutboxController;
use App\Http\Controller\ReviewController;
use App\Http\Controller\SettingsController;
use App\Http\Controller\UnsubscribeController;
use App\View\View;

/**
 * Turns a Request into a Response: routing, login, CSRF, security headers.
 */
final class Kernel
{
    private Router $router;
    private View $view;
    private Csrf $csrf;
    private Auth $auth;

    public function __construct(private App $app, private Session $session)
    {
        $this->csrf = new Csrf($session);
        $this->view = new View($app->root . '/templates', ['csrf' => $this->csrf, 'base' => $app->basePath(), 'session' => $session]);
        $this->auth = new Auth($session, $app->db(), $app->clock(), $app->env->get('ADMIN_USERNAME', 'admin'), $app->env->get('ADMIN_PASSWORD_HASH'));
        $this->router = $this->routes();
    }

    public function handle(Request $request): Response
    {
        $this->session->start($request->https || $this->app->isProduction());
        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            error_log('[agency-outreach-copilot] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $message = $this->app->isProduction() ? 'Something went wrong. The details were written to the PHP error log.' : get_class($e) . ': ' . $e->getMessage();
            $response = new Response($this->view->render('error', ['title' => 'Error', 'message' => $message], 'layout_bare'), 500, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return $this->secure($response);
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);
        if ($match === false) {
            return new Response('Method not allowed', 405, ['Content-Type' => 'text/plain']);
        }
        if ($match === null) {
            return new Response($this->view->render('error', ['title' => 'Not found', 'message' => 'That page does not exist.'], 'layout_bare'), 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        $isUnsubscribe = str_starts_with($request->path, '/u/');
        if (!$match['public'] && !$this->auth->check()) {
            return Response::redirect($this->app->basePath() . '/login', $request->isHtmx());
        }
        if ($request->method === 'POST' && !$isUnsubscribe && !$this->csrf->isValid($request)) {
            return new Response($this->view->render('error', ['title' => 'Session expired', 'message' => 'The form expired or came from another site. Go back, reload the page and try again.'], 'layout_bare'), 419, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        if ($this->auth->check()) {
            $this->view->share('flashes', $this->session->takeFlashes());
            $report = $this->session->get('_report');
            $this->session->remove('_report');
            $this->view->share('report', is_array($report) ? $report : null);
        }

        return ($match['handler'])($request, $match['params']);
    }

    private function secure(Response $response): Response
    {
        $response->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cache-Control' => 'no-store',
            // Tailwind's CDN build injects a <style> element, hence 'unsafe-inline' for styles only.
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'",
        ];

        return $response;
    }

    private function routes(): Router
    {
        $r = new Router();
        $c = fn (string $class): object => new $class($this->app, $this->view, $this->session, $this->csrf);
        $auth = $c(AuthController::class);
        assert($auth instanceof AuthController);
        $dash = $c(DashboardController::class);
        assert($dash instanceof DashboardController);
        $camp = $c(CampaignController::class);
        assert($camp instanceof CampaignController);
        $leads = $c(LeadController::class);
        assert($leads instanceof LeadController);
        $import = $c(ImportController::class);
        assert($import instanceof ImportController);
        $review = $c(ReviewController::class);
        assert($review instanceof ReviewController);
        $outbox = $c(OutboxController::class);
        assert($outbox instanceof OutboxController);
        $settings = $c(SettingsController::class);
        assert($settings instanceof SettingsController);
        $unsub = $c(UnsubscribeController::class);
        assert($unsub instanceof UnsubscribeController);

        $r->add('GET', '/login', fn (Request $q): Response => $auth->form($q, $this->auth), true);
        $r->add('POST', '/login', fn (Request $q): Response => $auth->login($q, $this->auth), true);
        $r->add('POST', '/logout', fn (Request $q): Response => $auth->logout($q, $this->auth));

        $r->add('GET', '/u/{token}', [$unsub, 'show'], true);
        $r->add('POST', '/u/{token}', [$unsub, 'confirm'], true);

        $r->add('GET', '/', [$dash, 'index']);
        $r->add('GET', '/export.csv', [$dash, 'export']);

        $r->add('GET', '/campaigns', [$camp, 'index']);
        $r->add('GET', '/campaigns/new', [$camp, 'create']);
        $r->add('POST', '/campaigns', [$camp, 'store']);
        $r->add('GET', '/campaigns/{id}', [$camp, 'show']);
        $r->add('GET', '/campaigns/{id}/edit', [$camp, 'edit']);
        $r->add('POST', '/campaigns/{id}', [$camp, 'update']);
        $r->add('POST', '/campaigns/{id}/delete', [$camp, 'delete']);
        $r->add('GET', '/campaigns/{id}/places', [$camp, 'places']);
        $r->add('POST', '/campaigns/{id}/places', [$camp, 'places']);
        $r->add('POST', '/campaigns/{id}/places/add', [$camp, 'placesAdd']);
        $r->add('POST', '/campaigns/{id}/process', [$camp, 'process']);

        $r->add('GET', '/import', [$import, 'form']);
        $r->add('POST', '/import', [$import, 'upload']);
        $r->add('POST', '/import/confirm', [$import, 'confirm']);

        $r->add('GET', '/leads', [$leads, 'index']);
        $r->add('GET', '/leads/new', [$leads, 'create']);
        $r->add('POST', '/leads', [$leads, 'store']);
        $r->add('GET', '/leads/{id}', [$leads, 'show']);
        $r->add('POST', '/leads/{id}', [$leads, 'update']);
        $r->add('POST', '/leads/{id}/status', [$leads, 'status']);
        $r->add('POST', '/leads/{id}/research', [$leads, 'research']);
        $r->add('POST', '/leads/{id}/score', [$leads, 'score']);
        $r->add('POST', '/leads/{id}/draft', [$leads, 'draft']);
        $r->add('POST', '/leads/{id}/manual-draft', [$leads, 'manualDraft']);
        $r->add('POST', '/leads/{id}/delete', [$leads, 'delete']);

        $r->add('GET', '/review', [$review, 'index']);
        $r->add('POST', '/drafts/{id}/approve', [$review, 'approve']);
        $r->add('POST', '/drafts/{id}/reject', [$review, 'reject']);
        $r->add('POST', '/drafts/{id}/edit', [$review, 'edit']);
        $r->add('POST', '/drafts/{id}/regenerate', [$review, 'regenerate']);
        $r->add('POST', '/drafts/{id}/sent', [$outbox, 'markSent']);

        $r->add('GET', '/outbox', [$outbox, 'index']);
        $r->add('POST', '/outbox/process', [$outbox, 'process']);
        $r->add('POST', '/messages/{id}/cancel', [$outbox, 'cancel']);
        $r->add('POST', '/messages/{id}/retry', [$outbox, 'retry']);

        $r->add('GET', '/settings', [$settings, 'index']);
        $r->add('POST', '/settings', [$settings, 'save']);
        $r->add('POST', '/settings/followups', [$settings, 'followups']);
        $r->add('POST', '/settings/retention', [$settings, 'retention']);
        $r->add('GET', '/suppression', [$settings, 'suppression']);
        $r->add('POST', '/suppression', [$settings, 'suppressionAdd']);
        $r->add('POST', '/suppression/{id}/delete', [$settings, 'suppressionDelete']);

        return $r;
    }
}
