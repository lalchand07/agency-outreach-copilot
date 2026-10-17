<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;

/**
 * Public pages for the unsubscribe link. GET shows a confirmation button (so
 * link scanners do not unsubscribe people by accident); POST confirms. Mail
 * clients' RFC 8058 one-click POST works too. The token is the credential, so
 * these routes have no CSRF check.
 */
final class UnsubscribeController extends Controller
{
    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $message = $this->app->unsubscribe()->lookup($params['token']);

        return $this->bare('unsubscribe', ['found' => $message !== null, 'done' => false, 'token' => $params['token']], $message !== null ? 200 : 404);
    }

    /** @param array<string, string> $params */
    public function confirm(Request $request, array $params): Response
    {
        $ok = $this->app->unsubscribe()->confirm($params['token']);

        return $this->bare('unsubscribe', ['found' => $ok, 'done' => $ok, 'token' => $params['token']], $ok ? 200 : 404);
    }

    /** @param array<string, mixed> $data */
    private function bare(string $template, array $data, int $status): Response
    {
        return new Response($this->view->render($template, $data + ['company' => $this->app->product()->sender('company')], 'layout_bare'), $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
