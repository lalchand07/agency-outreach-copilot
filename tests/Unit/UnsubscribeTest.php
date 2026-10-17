<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Kernel;
use App\Http\Request;
use App\Http\Session;
use App\Pipeline\Status;
use App\Tests\Support\TestCase;

final class UnsubscribeTest extends TestCase
{
    private function token(): string
    {
        $leadId = $this->lead();
        $this->queueEmailFor($leadId);
        $this->app->outbox()->process();

        return (string) ($this->db->one('SELECT unsubscribe_token FROM messages') ?? [])['unsubscribe_token'];
    }

    public function testEveryEmailHasAUniqueToken(): void
    {
        $this->queueEmailFor($this->lead(['website' => 'https://a.example', 'email' => 'a@a.example']));
        $this->queueEmailFor($this->lead(['website' => 'https://b.example', 'email' => 'b@b.example']));
        $tokens = array_column($this->db->all('SELECT unsubscribe_token FROM messages'), 'unsubscribe_token');

        self::assertCount(2, array_unique($tokens));
    }

    public function testConfirmAddsToSuppressionAndMarksDoNotContact(): void
    {
        $token = $this->token();

        self::assertTrue($this->app->unsubscribe()->confirm($token));
        self::assertTrue($this->app->suppression()->isEmailSuppressed('info@crescent.example'));
        $lead = $this->db->one('SELECT * FROM leads') ?? [];
        self::assertSame(Status::DO_NOT_CONTACT, $lead['status']);
        self::assertTrue($this->app->unsubscribe()->confirm($token), 'idempotent');
    }

    public function testUnknownOrMalformedTokensAreRejected(): void
    {
        self::assertFalse($this->app->unsubscribe()->confirm(str_repeat('a', 32)));
        self::assertFalse($this->app->unsubscribe()->confirm("' OR 1=1 --"));
        self::assertSame(0, $this->app->suppression()->count());
    }

    public function testPublicPagesGetShowsConfirmationPostUnsubscribes(): void
    {
        $token = $this->token();
        $kernel = new Kernel($this->app, new Session(false));

        $get = $kernel->handle(new Request('GET', '/u/' . $token));
        self::assertSame(200, $get->status);
        self::assertStringContainsString('Unsubscribe me', $get->body);
        self::assertFalse($this->app->suppression()->isEmailSuppressed('info@crescent.example'), 'GET alone (link scanners) does not unsubscribe');

        // RFC 8058 one-click POST from a mail client: no session, no CSRF token.
        $post = $kernel->handle(new Request('POST', '/u/' . $token, [], ['List-Unsubscribe' => 'One-Click']));
        self::assertSame(200, $post->status);
        self::assertStringContainsString('You are unsubscribed', $post->body);
        self::assertTrue($this->app->suppression()->isEmailSuppressed('info@crescent.example'));

        self::assertSame(404, $kernel->handle(new Request('GET', '/u/' . str_repeat('0', 32)))->status);
    }

    public function testUnsubscribedLeadReceivesNothingMore(): void
    {
        $token = $this->token();
        $this->app->unsubscribe()->confirm($token);
        $leadId = (int) ($this->db->one('SELECT id FROM leads') ?? [])['id'];
        $draftId = $this->app->drafts()->store($leadId, 'email', 'en', 'Again', 'Another message.', 'obs', true);

        self::assertNotNull($this->app->review()->approve($draftId));
        self::assertCount(1, $this->mailer->sent);
    }
}
