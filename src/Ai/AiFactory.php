<?php

declare(strict_types=1);

namespace App\Ai;

use App\Config\Env;
use PhpAiGuard\AiGuard;
use PhpAiGuard\Http\HttpClientInterface;

/**
 * Builds the php-ai-guard instance every LLM call goes through.
 *
 * Tiers: "cheap" (research, scoring) and "standard" (drafting). Features are
 * mapped to tiers so php-ai-guard logs usage per feature. A global monthly
 * (and optional daily) budget is enforced before any request is sent.
 */
final class AiFactory
{
    public const FEATURES = [
        'research' => 'cheap',
        'scoring' => 'cheap',
        'drafting' => 'standard',
        'followup' => 'standard',
    ];

    /** Provider name → env var holding its key, and whether it may receive personal data. */
    private const PROVIDERS = [
        'anthropic' => ['ANTHROPIC_API_KEY', true],
        'gemini' => ['GEMINI_API_KEY', false],
        'groq' => ['GROQ_API_KEY', false],
    ];

    /**
     * Returns null when no provider key is configured (the app then works without AI).
     *
     * @param array<string, mixed> $overrides extra php-ai-guard config (tests pass http_client, storage, cache)
     */
    public static function fromEnv(Env $env, string $root, array $overrides = []): ?AiGuard
    {
        $config = self::config($env, $root);
        if ($config === null) {
            return null;
        }

        return AiGuard::create(array_merge($config, $overrides));
    }

    /** @return array<string, mixed>|null */
    public static function config(Env $env, string $root): ?array
    {
        $providers = [];
        foreach (self::PROVIDERS as $name => [$var, $allowsPersonalData]) {
            $key = $env->get($var);
            if ($key !== '') {
                $providers[$name] = ['api_key' => $key, 'allows_personal_data' => $allowsPersonalData];
            }
        }
        if ($providers === []) {
            return null;
        }

        $filter = static fn (array $targets): array => array_values(array_filter(
            $targets,
            static fn (string $t): bool => isset($providers[explode(':', $t, 2)[0]])
        ));
        $cheap = $filter($env->list('AI_CHEAP_MODELS', 'anthropic:claude-haiku-5-5'));
        $standard = $filter($env->list('AI_STANDARD_MODELS', 'anthropic:claude-sonnet-5-5,anthropic:claude-haiku-5-5'));
        if ($cheap === [] && $standard === []) {
            return null;
        }
        $cheap = $cheap !== [] ? $cheap : $standard;
        $standard = $standard !== [] ? $standard : $cheap;

        $budgets = ['global' => ['monthly' => $env->float('AI_MONTHLY_BUDGET_USD', 10.0)]];
        $daily = $env->float('AI_DAILY_BUDGET_USD');
        if ($daily !== null && $daily > 0) {
            $budgets['global']['daily'] = $daily;
        }

        $pricingFile = $env->get('AI_PRICING_FILE', 'config/ai-pricing.php');
        if ($pricingFile !== '' && $pricingFile[0] !== '/') {
            $pricingFile = $root . '/' . $pricingFile;
        }

        if ($env->get('DB_DRIVER', 'sqlite') === 'mysql') {
            $storage = [
                'driver' => 'mysql',
                'host' => $env->get('DB_HOST', 'localhost'),
                'port' => $env->int('DB_PORT', 3306),
                'database' => $env->get('DB_NAME'),
                'username' => $env->get('DB_USER'),
                'password' => $env->get('DB_PASSWORD'),
                'table_prefix' => 'ai_guard_',
                'auto_install' => true,
            ];
        } else {
            $storage = ['driver' => 'sqlite', 'path' => $root . '/var/ai-guard.sqlite'];
        }

        return [
            'providers' => $providers,
            'tiers' => ['cheap' => $cheap, 'standard' => $standard],
            'default_tier' => 'cheap',
            'features' => self::FEATURES,
            'pricing' => is_file($pricingFile) ? require $pricingFile : [],
            'budgets' => $budgets,
            'rate_limits' => ['global' => 60],
            'storage' => $storage,
            'cache' => ['driver' => 'file', 'path' => $root . '/var/ai-cache', 'ttl' => 7 * 86400],
            'privacy' => [
                // Applied to providers marked allows_personal_data = false (free tiers).
                'patterns' => ['card', 'iban', 'mrz', 'email', 'phone'],
                'on_disallowed' => 'redact',
            ],
            'retry' => ['max_retries' => 1, 'base_delay_ms' => 500, 'max_delay_ms' => 4000, 'max_retry_after' => 10],
            'timeout' => 40,
            'defaults' => ['max_tokens' => 900],
            'timezone' => $env->get('APP_TIMEZONE', 'UTC'),
        ];
    }

    /** Test helper: a config that talks to a mock HTTP client and keeps everything in memory. */
    public static function forTesting(HttpClientInterface $http): AiGuard
    {
        return AiGuard::create([
            'providers' => ['anthropic' => ['api_key' => 'test-key']],
            'tiers' => ['cheap' => ['anthropic:claude-haiku-5-5'], 'standard' => ['anthropic:claude-sonnet-5-5']],
            'default_tier' => 'cheap',
            'features' => self::FEATURES,
            'pricing' => ['anthropic' => ['*' => ['input' => 1.0, 'output' => 5.0]]],
            'budgets' => ['global' => ['monthly' => 5.0]],
            'storage' => ['driver' => 'memory'],
            'retry' => ['max_retries' => 0],
            'sleeper' => new \PhpAiGuard\Retry\RecordingSleeper(),
            'http_client' => $http,
        ]);
    }
}
