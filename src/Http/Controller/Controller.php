<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\App;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\View\View;

abstract class Controller
{
    public function __construct(protected App $app, protected View $view, protected Session $session, protected Csrf $csrf)
    {
    }

    /** @param array<string, mixed> $data */
    protected function page(string $template, array $data = [], int $status = 200): Response
    {
        return new Response($this->view->render($template, $data), $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @param array<string, mixed> $data */
    protected function fragment(string $template, array $data = []): Response
    {
        return new Response($this->view->partial($template, $data), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    protected function back(Request $request, string $fallback, string $type = '', string $message = ''): Response
    {
        if ($message !== '') {
            $this->session->flash($type !== '' ? $type : 'info', $message);
        }
        $to = $request->input('_back');
        if ($to === '' || !str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            $to = $fallback;
        }

        return Response::redirect($this->url($to), $request->isHtmx());
    }

    protected function url(string $path): string
    {
        return $this->app->basePath() . $path;
    }

    protected function notFound(): Response
    {
        return $this->page('error', ['title' => 'Not found', 'message' => 'That page or record does not exist.'], 404);
    }

    /** @return array<string, mixed>|null */
    protected function campaignFor(?int $id): ?array
    {
        return $id === null ? null : $this->app->campaigns()->find($id);
    }
}
