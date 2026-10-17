<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\App;
use App\Config\Env;
use App\Config\ProductProfile;
use App\Db\Database;
use App\Db\Migrator;
use App\Mail\ArrayMailer;
use App\Support\Clock;
use App\Support\Sleeper;
use PhpAiGuard\AiGuard;

/**
 * Builds an App on an in-memory SQLite database with fake HTTP, AI, mailer and clock.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected App $app;
    protected Database $db;
    protected FakeHttpClient $web;
    protected ArrayMailer $mailer;
    protected Clock $clock;
    protected Sleeper $sleeper;
    protected ?FakeAi $fakeAi = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildApp();
    }

    /** @param array<string, string> $env */
    protected function buildApp(bool $withAi = false, array $env = [], ?ProductProfile $product = null, ?ArrayMailer $mailer = null): void
    {
        $root = dirname(__DIR__, 2);
        $this->db = Database::sqlite(':memory:');
        (new Migrator($this->db, $root . '/migrations'))->migrate();
        $this->web = new FakeHttpClient();
        $this->mailer = $mailer ?? new ArrayMailer();
        $this->clock = new Clock('Europe/London', new \DateTimeImmutable('2026-10-14 10:00:00', new \DateTimeZone('UTC')));
        $this->sleeper = new Sleeper(false);
        $this->fakeAi = $withAi ? new FakeAi() : null;

        $this->app = new App($root, new Env($env + [
            'APP_URL' => 'https://outreach.example.test',
            'ADMIN_USERNAME' => 'admin',
            'ADMIN_PASSWORD_HASH' => password_hash('correct horse battery', PASSWORD_DEFAULT, ['cost' => 4]),
            'CRAWLER_CONTACT' => 'https://seller.example/bot',
        ]), [
            'db' => $this->db,
            'http' => $this->web,
            'mailer' => $this->mailer,
            'clock' => $this->clock,
            'sleeper' => $this->sleeper,
            'ai' => $this->fakeAi?->ai,
            'product' => $product ?? self::product(),
        ]);
    }

    protected function ai(): FakeAi
    {
        if ($this->fakeAi === null) {
            throw new \LogicException('Call buildApp(true) first.');
        }

        return $this->fakeAi;
    }

    /** @param array<string, mixed> $overrides */
    public static function product(array $overrides = []): ProductProfile
    {
        return ProductProfile::fromArray($overrides + [
            'product_name' => 'TBM',
            'one_line_pitch' => 'Back office for travel agencies.',
            'key_benefits' => ['Bookings, invoices and supplier payments in one place'],
            'ideal_customer' => 'Independent travel agencies with 2-50 staff selling Umrah.',
            'proof_points' => [],
            'offer' => 'A free demo and free data import.',
            'sender' => [
                'name' => 'Sam Seller', 'title' => 'Founder', 'company' => 'Seller Ltd', 'email' => 'sam@seller.example',
                'address' => '1 High Street, London, EC1A 1AA, UK', 'website' => 'https://seller.example',
            ],
            'languages' => ['en', 'ur', 'ar'],
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function lead(array $data = []): int
    {
        return $this->app->leads()->create($data + ['name' => 'Crescent Travel', 'website' => 'https://crescent.example', 'email' => 'info@crescent.example', 'country' => 'UK'], 'manual');
    }

    protected function campaign(): int
    {
        return $this->app->campaigns()->save([
            'name' => 'UK Umrah', 'country' => 'UK', 'cities' => 'London', 'keywords' => 'Umrah travel agency',
            'languages' => ['en'], 'channels' => ['email', 'linkedin', 'whatsapp'], 'ideal_customer' => '',
        ]);
    }

    /** A pending email draft, approved and queued through the real review flow. */
    protected function queueEmailFor(int $leadId, string $body = 'Hello, a short note about your Umrah packages. Would a demo help? Sam'): int
    {
        $draftId = $this->app->drafts()->store($leadId, 'email', 'en', 'Your Umrah packages', $body, 'obs', false);
        $error = $this->app->review()->approve($draftId);
        self::assertNull($error, (string) $error);

        return $draftId;
    }

    /** A small travel agency website. */
    protected function agencySite(string $origin = 'https://crescent.example'): void
    {
        $this->web->text($origin . '/robots.txt', "User-agent: *\nDisallow: /private/\n");
        $this->web->html($origin . '/', <<<HTML
<!doctype html><html lang="en"><head><title>Crescent Travel | Umrah Packages from London</title>
<meta name="description" content="Family-run Umrah and Hajj specialists in East London."></head>
<body><nav><a href="/about-us">About us</a> <a href="/contact">Contact</a> <a href="/umrah-packages">Umrah packages</a>
<a href="/private/admin">Admin</a> <a href="https://facebook.com/crescent">Facebook</a> <a href="/login">Login</a></nav>
<h1>Umrah packages for December 2026 from £1,150 per person</h1>
<p>We are an ATOL protected travel agency. ATOL number 12345.</p>
<p>Chat to us on <a href="https://wa.me/447700900123">WhatsApp</a>.</p>
<script>var tracking = "ignore me";</script>
</body></html>
HTML);
        $this->web->html($origin . '/about-us', '<html><head><title>About Crescent Travel</title></head><body><p>Founded in 2009, our team of 6 has helped over 4,000 pilgrims perform Umrah.</p></body></html>');
        $this->web->html($origin . '/contact', '<html><head><title>Contact</title></head><body><p>Email info@crescent.example or call <a href="tel:+442079460958">020 7946 0958</a>.</p>
<form action="/enquiry"><input name="name"><input name="email"><input name="phone"><textarea name="message"></textarea><button>Get a quote</button></form></body></html>');
        $this->web->html($origin . '/umrah-packages', '<html><head><title>Umrah Packages</title></head><body><p>7 nights Makkah and Madinah, 4 star hotels, flights from Heathrow, visa included.</p></body></html>');
    }
}
