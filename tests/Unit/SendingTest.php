<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Pipeline\Status;
use App\Tests\Support\TestCase;

final class SendingTest extends TestCase
{
    public function testApprovedEmailIsQueuedWithComplianceFooterAndNotSentUntilProcessed(): void
    {
        $leadId = $this->lead();
        $this->queueEmailFor($leadId);

        self::assertSame([], $this->mailer->sent, 'approval queues, it does not send');
        $message = $this->db->one('SELECT * FROM messages') ?? [];
        self::assertSame('queued', $message['status']);
        $body = (string) $message['body'];
        self::assertStringContainsString('Sam Seller, Founder', $body);
        self::assertStringContainsString('Seller Ltd', $body);
        self::assertStringContainsString('1 High Street, London, EC1A 1AA, UK', $body);
        self::assertStringContainsString('https://outreach.example.test/u/' . $message['unsubscribe_token'], $body);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $message['unsubscribe_token']);

        $summary = $this->app->outbox()->process();

        self::assertSame(1, $summary['sent']);
        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->last();
        self::assertNotNull($email);
        self::assertSame('info@crescent.example', $email->to);
        self::assertSame($body, $email->body, 'exactly what was logged is what was sent');
        self::assertSame('<https://outreach.example.test/u/' . $message['unsubscribe_token'] . '>, <mailto:sam@seller.example?subject=unsubscribe>', $email->headers['List-Unsubscribe']);
        self::assertSame('List-Unsubscribe=One-Click', $email->headers['List-Unsubscribe-Post']);
        $lead = $this->app->leads()->find($leadId) ?? [];
        self::assertSame(Status::CONTACTED, $lead['status']);
        self::assertNotNull($lead['contacted_at']);
        self::assertSame('sent', ($this->db->one('SELECT status FROM drafts') ?? [])['status']);
    }

    public function testDailyCapIsEnforced(): void
    {
        $this->app->settings()->set('daily_send_cap', 3);
        for ($i = 1; $i <= 5; $i++) {
            $this->queueEmailFor($this->lead(['name' => 'Agency ' . $i, 'website' => "https://agency{$i}.example", 'email' => "info@agency{$i}.example"]));
        }

        $first = $this->app->outbox()->process();
        self::assertSame(3, $first['sent']);
        self::assertSame(0, $first['remaining_cap']);

        $second = $this->app->outbox()->process();
        self::assertSame(0, $second['sent']);
        self::assertStringContainsString('Daily send cap reached', $second['message']);
        self::assertCount(3, $this->mailer->sent);

        // Next local day the rest go out.
        $this->clock->advance('+1 day');
        self::assertSame(2, $this->app->outbox()->process()['sent']);
    }

    public function testDailyCapFollowsTheLocalDay(): void
    {
        $this->app->settings()->set('daily_send_cap', 1);
        $this->queueEmailFor($this->lead(['website' => 'https://a.example', 'email' => 'a@a.example']));
        $this->queueEmailFor($this->lead(['website' => 'https://b.example', 'email' => 'b@b.example']));
        // 22:30 UTC on 14 Oct is 23:30 in London (BST): still the 14th locally.
        $this->clock->advance('+12 hours 30 minutes');
        $lateEvening = $this->app->outbox()->process();
        self::assertSame(1, $lateEvening['sent']);
        // 23:30 UTC is 00:30 on the 15th in London: a new day.
        $this->clock->advance('+1 hour');
        $afterMidnight = $this->app->outbox()->process();
        self::assertSame(1, $afterMidnight['sent']);
    }

    public function testCapCannotBeRaisedAboveHardMaximum(): void
    {
        $this->app->settings()->set('daily_send_cap', 100000);
        self::assertSame(200, $this->app->settings()->int('daily_send_cap'));
    }

    public function testSuppressedAddressCannotBeApprovedOrQueued(): void
    {
        $this->app->suppression()->add('email', 'INFO@crescent.example', 'asked');
        $leadId = $this->lead();
        $draftId = $this->app->drafts()->store($leadId, 'email', 'en', 'Subject', 'Short body here.', 'obs', false);

        self::assertStringContainsString('Suppressed', (string) $this->app->review()->approve($draftId));
        self::assertSame('pending', ($this->app->review()->find($draftId) ?? [])['status']);
        self::assertSame([], $this->db->all('SELECT * FROM messages'));
    }

    public function testSuppressedDomainBlocksEveryAddressAtIt(): void
    {
        $this->app->suppression()->add('domain', 'https://www.crescent.example', 'company asked');

        self::assertTrue($this->app->suppression()->isEmailSuppressed('sales@crescent.example'));
        self::assertFalse($this->app->suppression()->isEmailSuppressed('sales@other.example'));
    }

    public function testSuppressionIsCheckedAgainRightBeforeSending(): void
    {
        $leadId = $this->lead();
        $this->queueEmailFor($leadId);
        // They unsubscribe from something else (or ask by phone) after queueing.
        $this->app->suppression()->add('email', 'info@crescent.example', 'asked by phone');

        $summary = $this->app->outbox()->process();

        self::assertSame(0, $summary['sent']);
        self::assertSame(1, $summary['skipped']);
        self::assertSame([], $this->mailer->sent);
        self::assertSame('skipped', ($this->db->one('SELECT status FROM messages') ?? [])['status']);
    }

    public function testDoNotContactWithdrawsQueuedMessagesAndSuppresses(): void
    {
        $leadId = $this->lead(['phone' => '020 7946 0958']);
        $this->queueEmailFor($leadId);
        $this->app->leads()->setStatus($leadId, Status::DO_NOT_CONTACT);

        self::assertSame(0, $this->app->outbox()->process()['sent']);
        self::assertTrue($this->app->suppression()->isEmailSuppressed('info@crescent.example'));
        self::assertTrue($this->app->suppression()->isPhoneSuppressed('+44 20 7946 0958'));
        self::assertSame(3, $this->app->suppression()->count(), 'email, domain and phone');
    }

    public function testSendingIsBlockedWithoutSenderIdentityAndAddress(): void
    {
        $this->buildApp(false, [], self::product(['sender' => ['name' => 'Sam', 'email' => 'sam@seller.example']]));
        $leadId = $this->lead();
        $draftId = $this->app->drafts()->store($leadId, 'email', 'en', 'Subject', 'Short body here.', 'obs', false);

        $error = (string) $this->app->review()->approve($draftId);
        self::assertStringContainsString('company, address', $error);
        self::assertStringContainsString('Sending is blocked', $this->app->outbox()->process()['message']);
    }

    public function testSmtpFailureIsLoggedAndRetryable(): void
    {
        $this->buildApp(false, [], null, new \App\Mail\ArrayMailer('info@crescent.example'));
        $this->queueEmailFor($this->lead());

        $summary = $this->app->outbox()->process();
        self::assertSame(1, $summary['failed']);
        $message = $this->db->one('SELECT * FROM messages') ?? [];
        self::assertSame('failed', $message['status']);
        self::assertSame('Simulated SMTP failure', $message['error']);

        $this->app->outbox()->retry((int) $message['id']);
        self::assertSame('queued', ($this->db->one('SELECT status FROM messages') ?? [])['status']);
    }

    public function testOnlyOneFollowUpByDefault(): void
    {
        $leadId = $this->lead();
        $this->queueEmailFor($leadId);
        $this->app->outbox()->process();
        $first = $this->app->drafts()->store($leadId, 'email', 'en', 'Re: Your Umrah packages', 'Just checking this reached you. If not relevant, tell me and I will not write again. Sam', 'follow-up', true);
        self::assertNull($this->app->review()->approve($first));
        $second = $this->app->drafts()->store($leadId, 'email', 'en', 'Re: Re: Your Umrah packages', 'One more time.', 'follow-up', true);

        self::assertStringContainsString('follow-up limit (1)', (string) $this->app->review()->approve($second));
    }

    public function testLinkedInAndWhatsAppAreNeverSentAutomatically(): void
    {
        $leadId = $this->lead(['phone' => '07700 900123']);
        $wa = $this->app->drafts()->store($leadId, 'whatsapp', 'en', '', 'Salaam, this is Sam from Seller Ltd.', 'obs', false);
        self::assertNull($this->app->review()->approve($wa));

        $this->app->outbox()->process();
        self::assertSame([], $this->mailer->sent);
        self::assertSame([], $this->db->all('SELECT * FROM messages'), 'approval alone logs nothing');

        self::assertNull($this->app->outbox()->markManualSent($this->app->review()->find($wa) ?? []));
        $log = $this->db->one('SELECT * FROM messages') ?? [];
        self::assertSame('whatsapp', $log['channel']);
        self::assertSame('sent', $log['status']);
        self::assertSame(Status::CONTACTED, ($this->app->leads()->find($leadId) ?? [])['status']);
    }
}
