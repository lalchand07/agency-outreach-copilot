<?php

declare(strict_types=1);

namespace App;

use App\Ai\AiFactory;
use App\Config\Env;
use App\Config\ProductProfile;
use App\Config\Settings;
use App\Dashboard\Stats;
use App\Db\Database;
use App\Db\Migrator;
use App\Drafting\DraftGenerator;
use App\Drafting\DraftPromptBuilder;
use App\Drafting\ReviewService;
use App\Export\CsvExporter;
use App\Leads\CampaignRepository;
use App\Leads\CsvImporter;
use App\Leads\Deduplicator;
use App\Leads\LeadRepository;
use App\Leads\LeadSourcing;
use App\Leads\PlacesClient;
use App\Mail\MailerInterface;
use App\Mail\SmtpMailer;
use App\Net\CurlHttpClient;
use App\Net\HttpClientInterface;
use App\Outreach\EmailComposer;
use App\Outreach\Outbox;
use App\Outreach\Suppression;
use App\Outreach\Unsubscribe;
use App\Pipeline\FollowUps;
use App\Pipeline\Retention;
use App\Research\Researcher;
use App\Research\WebsiteFetcher;
use App\Scoring\LeadScorer;
use App\Scoring\ScoringPromptBuilder;
use App\Support\Clock;
use App\Support\Sleeper;
use PhpAiGuard\AiGuard;

/**
 * A small service container. Everything is created lazily; tests pass
 * replacements (database, HTTP client, AI, mailer, clock) through $overrides.
 */
final class App
{
    /** @var array<string, object|null> */
    private array $services = [];

    /** @param array<string, object|null> $overrides */
    public function __construct(public readonly string $root, public readonly Env $env, array $overrides = [])
    {
        $this->services = $overrides;
    }

    public static function boot(string $root): self
    {
        $env = Env::load($root . '/.env');
        date_default_timezone_set('UTC');

        return new self($root, $env);
    }

    /**
     * @param callable(): ?object $factory
     */
    private function get(string $id, callable $factory): ?object
    {
        if (!array_key_exists($id, $this->services)) {
            $this->services[$id] = $factory();
        }

        return $this->services[$id];
    }

    public function isProduction(): bool
    {
        return $this->env->get('APP_ENV', 'local') === 'production';
    }

    public function appUrl(): string
    {
        return rtrim($this->env->get('APP_URL', 'http://127.0.0.1:8080'), '/');
    }

    /** Path prefix when installed in a sub-folder, e.g. "/outreach" (empty at the web root). */
    public function basePath(): string
    {
        return rtrim((string) parse_url($this->appUrl(), PHP_URL_PATH), '/');
    }

    public function clock(): Clock
    {
        /** @var Clock */
        return $this->get('clock', fn (): Clock => new Clock($this->env->get('APP_TIMEZONE', 'UTC')));
    }

    public function db(): Database
    {
        /** @var Database */
        return $this->get('db', function (): Database {
            if ($this->env->get('DB_DRIVER', 'sqlite') === 'mysql') {
                $db = Database::mysql($this->env->get('DB_HOST', 'localhost'), $this->env->int('DB_PORT', 3306), $this->env->get('DB_NAME'), $this->env->get('DB_USER'), $this->env->get('DB_PASSWORD'));
            } else {
                $path = $this->env->get('DB_PATH', 'var/app.sqlite');
                $db = Database::sqlite($path === ':memory:' || $path[0] === '/' ? $path : $this->root . '/' . $path);
            }
            (new Migrator($db, $this->root . '/migrations'))->migrate();

            return $db;
        });
    }

    public function settings(): Settings
    {
        /** @var Settings */
        return $this->get('settings', fn (): Settings => new Settings($this->db()));
    }

    public function product(): ProductProfile
    {
        /** @var ProductProfile */
        return $this->get('product', fn (): ProductProfile => ProductProfile::load($this->root . '/config'));
    }

    public function ai(): ?AiGuard
    {
        /** @var AiGuard|null */
        return $this->get('ai', fn (): ?AiGuard => AiFactory::fromEnv($this->env, $this->root));
    }

    public function http(): HttpClientInterface
    {
        /** @var HttpClientInterface */
        return $this->get('http', fn (): HttpClientInterface => new CurlHttpClient((float) $this->env->int('CRAWLER_TIMEOUT', 10)));
    }

    public function sleeper(): Sleeper
    {
        /** @var Sleeper */
        return $this->get('sleeper', fn (): Sleeper => new Sleeper());
    }

    public function mailer(): MailerInterface
    {
        /** @var MailerInterface */
        return $this->get('mailer', fn (): MailerInterface => new SmtpMailer($this->env));
    }

    public function suppression(): Suppression
    {
        /** @var Suppression */
        return $this->get('suppression', fn (): Suppression => new Suppression($this->db(), $this->clock()));
    }

    public function leads(): LeadRepository
    {
        /** @var LeadRepository */
        return $this->get('leads', fn (): LeadRepository => new LeadRepository($this->db(), $this->clock(), $this->suppression()));
    }

    public function campaigns(): CampaignRepository
    {
        /** @var CampaignRepository */
        return $this->get('campaigns', fn (): CampaignRepository => new CampaignRepository($this->db(), $this->clock()));
    }

    public function fetcher(): WebsiteFetcher
    {
        /** @var WebsiteFetcher */
        return $this->get('fetcher', fn (): WebsiteFetcher => new WebsiteFetcher(
            $this->http(),
            $this->sleeper(),
            $this->env->get('CRAWLER_CONTACT'),
            $this->env->int('CRAWLER_MIN_DELAY_MS', 1000),
            (float) $this->env->int('CRAWLER_TIMEOUT', 10),
        ));
    }

    public function places(): PlacesClient
    {
        /** @var PlacesClient */
        return $this->get('places', fn (): PlacesClient => new PlacesClient($this->http(), $this->env->get('GOOGLE_PLACES_API_KEY')));
    }

    public function sourcing(): LeadSourcing
    {
        return new LeadSourcing($this->leads(), new Deduplicator($this->db()), $this->fetcher());
    }

    public function csvImporter(): CsvImporter
    {
        return new CsvImporter($this->leads(), new Deduplicator($this->db()));
    }

    public function researcher(): Researcher
    {
        /** @var Researcher */
        return $this->get('researcher', fn (): Researcher => new Researcher(
            $this->db(),
            $this->leads(),
            $this->fetcher(),
            $this->ai(),
            $this->clock(),
            maxPages: $this->settings()->int('research_max_pages'),
        ));
    }

    public function scorer(): LeadScorer
    {
        /** @var LeadScorer */
        return $this->get('scorer', fn (): LeadScorer => new LeadScorer($this->leads(), new ScoringPromptBuilder($this->product()), $this->ai()));
    }

    public function drafts(): DraftGenerator
    {
        /** @var DraftGenerator */
        return $this->get('drafts', fn (): DraftGenerator => new DraftGenerator($this->db(), $this->leads(), new DraftPromptBuilder($this->product()), $this->ai(), $this->clock()));
    }

    public function composer(): EmailComposer
    {
        /** @var EmailComposer */
        return $this->get('composer', fn (): EmailComposer => new EmailComposer($this->product(), $this->appUrl(), $this->env->get('SMTP_REPLY_TO')));
    }

    public function outbox(): Outbox
    {
        /** @var Outbox */
        return $this->get('outbox', fn (): Outbox => new Outbox($this->db(), $this->leads(), $this->suppression(), $this->settings(), $this->composer(), $this->product(), $this->mailer(), $this->clock()));
    }

    public function review(): ReviewService
    {
        /** @var ReviewService */
        return $this->get('review', fn (): ReviewService => new ReviewService($this->db(), $this->leads(), $this->outbox(), $this->suppression(), $this->clock()));
    }

    public function unsubscribe(): Unsubscribe
    {
        return new Unsubscribe($this->db(), $this->suppression(), $this->leads());
    }

    public function followUps(): FollowUps
    {
        return new FollowUps($this->db(), $this->leads(), $this->drafts(), $this->settings(), $this->clock());
    }

    public function retention(): Retention
    {
        return new Retention($this->db(), $this->settings(), $this->suppression(), $this->clock());
    }

    public function stats(): Stats
    {
        return new Stats($this->db(), $this->leads(), $this->clock(), $this->ai());
    }

    public function exporter(): CsvExporter
    {
        return new CsvExporter($this->db());
    }

    /**
     * Research → score → draft for one lead, as one action.
     *
     * @return list<string> messages for the user
     */
    public function processLead(int $leadId, bool $draft = true): array
    {
        $messages = [];
        $lead = $this->leads()->find($leadId);
        if ($lead === null) {
            return ['Lead not found.'];
        }
        $campaign = $lead['campaign_id'] !== null ? ($this->campaigns()->find((int) $lead['campaign_id']) ?? []) : [];
        $research = $this->researcher()->research($lead, $campaign);
        $messages[] = $research['message'];
        if (!$research['ok']) {
            return $messages;
        }
        $lead = $this->leads()->find($leadId) ?? $lead;
        $score = $this->scorer()->score($lead, $campaign);
        $messages[] = sprintf('Score %d: %s', $score['score'], $score['explanation']);
        if ($draft) {
            $languages = (array) ($campaign['languages'] ?? ['en']);
            $language = (string) ($lead['language'] !== '' ? $lead['language'] : ($languages[0] ?? 'en'));
            $result = $this->drafts()->generate($this->leads()->find($leadId) ?? $lead, (array) ($campaign['channels'] ?? ['email']), $language);
            $messages[] = $result['message'];
        }

        return $messages;
    }
}
