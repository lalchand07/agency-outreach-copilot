<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\Env;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Tests\Support\TestCase;

final class HttpTest extends TestCase
{
    private Session $session;
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(false);
        $this->kernel = new Kernel($this->app, $this->session);
    }

    /** @param array<string, mixed> $post */
    private function post(string $path, array $post = [], bool $withToken = true, string $ip = '203.0.113.5'): Response
    {
        if ($withToken) {
            $post['_csrf'] = (new \App\Http\Csrf($this->session))->token();
        }

        return $this->kernel->handle(new Request('POST', $path, [], $post, [], [], $ip));
    }

    private function login(): void
    {
        $response = $this->post('/login', ['username' => 'admin', 'password' => 'correct horse battery']);
        self::assertSame(303, $response->status);
    }

    public function testPagesRequireLogin(): void
    {
        $response = $this->kernel->handle(new Request('GET', '/leads'));

        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->headers['Location']);
    }

    public function testLoginAndPages(): void
    {
        $this->login();
        $id = $this->lead();
        foreach (['/', '/campaigns', '/campaigns/new', '/leads', '/leads/' . $id, '/review', '/outbox', '/suppression', '/settings', '/import'] as $path) {
            self::assertSame(200, $this->kernel->handle(new Request('GET', $path))->status, $path);
        }
        self::assertSame(404, $this->kernel->handle(new Request('GET', '/leads/999'))->status);
    }

    public function testWrongPasswordIsThrottled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->post('/login', ['username' => 'admin', 'password' => 'wrong'])->status);
        }
        $response = $this->post('/login', ['username' => 'admin', 'password' => 'correct horse battery']);

        self::assertSame(401, $response->status, 'even the right password is refused while throttled');
        self::assertStringContainsString('Too many failed attempts', $response->body);
        self::assertSame(303, $this->post('/login', ['username' => 'admin', 'password' => 'correct horse battery'], true, '198.51.100.7')->status, 'other IPs are unaffected');
    }

    public function testEveryPostNeedsTheCsrfToken(): void
    {
        $this->login();
        $response = $this->post('/campaigns', ['name' => 'Sneaky'], false);

        self::assertSame(419, $response->status);
        self::assertSame([], $this->app->campaigns()->all());

        $headerToken = (new \App\Http\Csrf($this->session))->token();
        $htmx = $this->kernel->handle(new Request('POST', '/campaigns', [], ['name' => 'Via HTMX'], [], ['x-csrf-token' => $headerToken, 'hx-request' => 'true']));
        self::assertSame(200, $htmx->status);
        self::assertArrayHasKey('HX-Redirect', $htmx->headers);
    }

    public function testLoginFormItselfNeedsCsrf(): void
    {
        self::assertSame(419, $this->post('/login', ['username' => 'admin', 'password' => 'correct horse battery'], false)->status);
    }

    public function testOutputIsEscaped(): void
    {
        $this->login();
        $id = $this->lead(['name' => '<img src=x onerror=alert(1)>']);
        $body = $this->kernel->handle(new Request('GET', '/leads/' . $id))->body;

        self::assertStringNotContainsString('<img src=x', $body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $body);
    }

    public function testSecurityHeaders(): void
    {
        $response = $this->kernel->handle(new Request('GET', '/login'));

        self::assertSame('DENY', $response->headers['X-Frame-Options']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertStringContainsString("frame-ancestors 'none'", $response->headers['Content-Security-Policy']);
    }

    public function testReviewFlowOverHttp(): void
    {
        $this->login();
        $leadId = $this->lead();
        $draftId = $this->app->drafts()->store($leadId, 'email', 'en', 'Your Umrah packages', 'Hello, a short note. Sam', 'obs', false);

        $changed = $this->post('/drafts/' . $draftId . '/approve', ['subject' => 'Your Umrah packages', 'body' => 'Edited but not saved']);
        self::assertStringContainsString('save the edit first', $this->session->takeFlashes()[0]['message'] ?? $changed->body);
        self::assertSame('pending', ($this->app->review()->find($draftId) ?? [])['status']);

        $this->post('/drafts/' . $draftId . '/approve', ['subject' => 'Your Umrah packages', 'body' => 'Hello, a short note. Sam']);
        self::assertSame('queued', ($this->app->review()->find($draftId) ?? [])['status']);
        self::assertSame([], $this->mailer->sent);

        $this->post('/outbox/process');
        self::assertCount(1, $this->mailer->sent);
    }

    public function testEnvParser(): void
    {
        $values = Env::parse("# comment\nA=1\nB=\"two words\"\nC='\$2y\$10\$abc'\nexport D=4 # trailing\nbad line\n");

        self::assertSame(['A' => '1', 'B' => 'two words', 'C' => '$2y$10$abc', 'D' => '4'], $values);
    }
}
