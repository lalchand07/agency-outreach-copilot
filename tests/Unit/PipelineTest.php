<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Pipeline\Status;
use App\Tests\Support\TestCase;

final class PipelineTest extends TestCase
{
    public function testRetentionDeletesDetailsButKeepsSuppressionAndAnonymisesTheLog(): void
    {
        $this->app->settings()->set('retention_days', 30);
        $gone = $this->lead(['notes' => 'Said no thanks on the phone']);
        $this->queueEmailFor($gone);
        $this->app->outbox()->process();
        $this->app->leads()->setStatus($gone, Status::NOT_INTERESTED);
        $keep = $this->lead(['website' => 'https://keep.example', 'email' => 'hi@keep.example']);

        $this->clock->advance('+29 days');
        self::assertSame(0, $this->app->retention()->run(), 'not yet');

        $this->clock->advance('+2 days');
        self::assertSame(1, $this->app->retention()->run());

        self::assertNull($this->app->leads()->find($gone));
        self::assertNotNull($this->app->leads()->find($keep));
        self::assertSame([], $this->db->all('SELECT * FROM drafts WHERE lead_id = ?', [$gone]));
        self::assertTrue($this->app->suppression()->isEmailSuppressed('info@crescent.example'), 'never contacted again');
        $log = $this->db->one('SELECT * FROM messages') ?? [];
        self::assertSame('sha256:' . hash('sha256', 'info@crescent.example'), $log['recipient']);
        self::assertSame('', $log['body']);
        self::assertNotNull($log['sent_at'], 'the fact that something was sent, and when, is kept');
    }

    public function testFollowUpIsDraftedAfterNDaysWithoutReplyAndStillNeedsApproval(): void
    {
        $this->buildApp(true);
        $leadId = $this->lead();
        $this->queueEmailFor($leadId);
        $this->app->outbox()->process();

        self::assertSame([], $this->app->followUps()->due());
        $this->clock->advance('+6 days');
        self::assertCount(1, $this->app->followUps()->due());

        $this->ai()->reply(['email_subject' => 'Re: Your Umrah packages', 'email_body' => 'Hello again. Just checking my note reached you. If it is not relevant, let me know and I will not write again. Sam']);
        $report = $this->app->followUps()->draftDue();

        self::assertSame(1, $report['created']);
        $draft = $this->db->one('SELECT * FROM drafts WHERE is_followup = 1') ?? [];
        self::assertSame('pending', $draft['status']);
        self::assertSame([], $this->app->followUps()->due(), 'no second draft while one is pending');
        self::assertCount(1, $this->mailer->sent, 'nothing extra was sent');
        self::assertSame('followup', $this->ai()->ai->reporter()->month('2026-10', 'feature')[0]['group']);
    }

    public function testRepliedLeadsGetNoFollowUp(): void
    {
        $leadId = $this->lead();
        $this->queueEmailFor($leadId);
        $this->app->outbox()->process();
        $this->app->leads()->setStatus($leadId, Status::REPLIED);
        $this->clock->advance('+10 days');

        self::assertSame([], $this->app->followUps()->due());
    }

    public function testMaxFollowUpsZeroDisablesThem(): void
    {
        $this->app->settings()->set('max_followups', 0);
        $leadId = $this->lead();
        $this->queueEmailFor($leadId);
        $this->app->outbox()->process();
        $this->clock->advance('+10 days');

        self::assertSame([], $this->app->followUps()->due());
    }

    public function testStatusChangesAreLoggedAndAutomaticMovesNeverGoBackwards(): void
    {
        $leadId = $this->lead();
        $this->app->leads()->setStatus($leadId, Status::CONTACTED);
        $this->app->leads()->advance($leadId, Status::DRAFTED);

        self::assertSame(Status::CONTACTED, ($this->app->leads()->find($leadId) ?? [])['status']);
        self::assertStringContainsString('New → Contacted', (string) $this->app->leads()->activity($leadId)[0]['detail']);
    }

    public function testDashboardStats(): void
    {
        $a = $this->lead(['website' => 'https://a.example', 'email' => 'a@a.example']);
        $b = $this->lead(['website' => 'https://b.example', 'email' => 'b@b.example']);
        $this->queueEmailFor($a);
        $this->queueEmailFor($b);
        $this->app->outbox()->process();
        $this->app->leads()->setStatus($a, Status::REPLIED);

        $stats = $this->app->stats();
        self::assertSame(['contacted' => 2, 'replied' => 1, 'rate' => 50.0], $stats->replyRate());
        self::assertSame(['email' => 2, 'manual' => 0], $stats->sentPerDay(14)['2026-10-14']);
        self::assertSame(1, $stats->leadsByStatus()['replied']);
        self::assertFalse($stats->aiSpend()['enabled']);
    }

    public function testAiSpendComesFromPhpAiGuard(): void
    {
        $this->buildApp(true);
        $this->ai()->reply(['score' => 50, 'explanation' => 'Average fit for this campaign.']);
        $leadId = $this->lead();
        $this->db->insert('research', ['lead_id' => $leadId, 'pages' => '[]', 'facts' => '{}', 'result' => '{"services":["umrah"]}', 'dropped' => '[]', 'error' => '', 'created_at' => '2026-10-14 10:00:00']);
        $this->app->scorer()->score($this->app->leads()->find($leadId) ?? [], []);

        $spend = $this->app->stats()->aiSpend();
        self::assertTrue($spend['enabled']);
        // 100 input tokens × $1/M + 50 output × $5/M (test pricing)
        self::assertEqualsWithDelta(0.00035, $spend['total'], 0.0000001);
        self::assertSame('scoring', $spend['by_feature'][0]['group']);
        self::assertEqualsWithDelta(5.0 - 0.00035, (float) $spend['remaining'], 0.00001);
    }
}
