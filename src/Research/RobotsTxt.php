<?php

declare(strict_types=1);

namespace App\Research;

/**
 * robots.txt rules following RFC 9309:
 *  - the group for our product token is used, else the "*" group, else everything is allowed;
 *  - several groups for the same agent are merged;
 *  - the longest matching rule wins, and "allow" wins a tie;
 *  - "*" matches any sequence of characters and "$" anchors the end;
 *  - /robots.txt itself is always allowed.
 */
final class RobotsTxt
{
    /**
     * @param list<array{agents: list<string>, rules: list<array{allow: bool, pattern: string}>, delay: float|null}> $groups
     */
    private function __construct(private array $groups, private bool $allowAll = false, private bool $disallowAll = false)
    {
    }

    public static function allowAll(): self
    {
        return new self([], true);
    }

    public static function disallowAll(): self
    {
        return new self([], false, true);
    }

    /**
     * Builds the policy from an HTTP outcome. Per RFC 9309: 2xx → parse,
     * 4xx → no restrictions, 5xx or unreachable (null) → assume complete disallow.
     */
    public static function fromResponse(?int $status, string $body): self
    {
        if ($status === null || $status >= 500 || $status === 0) {
            return self::disallowAll();
        }
        if ($status >= 400) {
            return self::allowAll();
        }
        if ($status >= 300) {
            return self::disallowAll(); // unresolved redirect loop
        }

        return self::parse($body);
    }

    public static function parse(string $content): self
    {
        $groups = [];
        $current = null;
        $lastWasAgent = false;
        // RFC 9309 asks crawlers to parse at least 500 KiB.
        foreach (preg_split('/\R/', substr($content, 0, 512_000)) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'user-agent') {
                if (!$lastWasAgent || $current === null) {
                    if ($current !== null) {
                        $groups[] = $current;
                    }
                    $current = ['agents' => [], 'rules' => [], 'delay' => null];
                }
                $current['agents'][] = strtolower($value);
                $lastWasAgent = true;
                continue;
            }
            $lastWasAgent = false;
            if ($current === null) {
                continue; // rules before any user-agent line are ignored
            }
            if ($key === 'allow' || $key === 'disallow') {
                if ($value === '') {
                    continue; // "Disallow:" with no path means nothing is disallowed
                }
                $current['rules'][] = ['allow' => $key === 'allow', 'pattern' => $value];
            } elseif ($key === 'crawl-delay' && is_numeric($value)) {
                $current['delay'] = (float) $value;
            }
        }
        if ($current !== null) {
            $groups[] = $current;
        }

        return new self($groups);
    }

    public function isAllowed(string $userAgentToken, string $pathAndQuery): bool
    {
        if ($this->allowAll) {
            return true;
        }
        if ($pathAndQuery === '' || $pathAndQuery[0] !== '/') {
            $pathAndQuery = '/' . $pathAndQuery;
        }
        if ($pathAndQuery === '/robots.txt') {
            return true;
        }
        if ($this->disallowAll) {
            return false;
        }

        $best = null;
        foreach ($this->rulesFor($userAgentToken) as $rule) {
            if (!self::matches($rule['pattern'], $pathAndQuery)) {
                continue;
            }
            $length = strlen($rule['pattern']);
            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null || $best['allow'];
    }

    /** Crawl-delay in seconds for our agent, if the site sets one. */
    public function crawlDelay(string $userAgentToken): ?float
    {
        $delay = null;
        foreach ($this->groupsFor($userAgentToken) as $group) {
            if ($group['delay'] !== null) {
                $delay = max($delay ?? 0.0, $group['delay']);
            }
        }

        return $delay;
    }

    /** @return list<array{allow: bool, pattern: string}> */
    private function rulesFor(string $token): array
    {
        $rules = [];
        foreach ($this->groupsFor($token) as $group) {
            $rules = array_merge($rules, $group['rules']);
        }

        return $rules;
    }

    /** @return list<array{agents: list<string>, rules: list<array{allow: bool, pattern: string}>, delay: float|null}> */
    private function groupsFor(string $token): array
    {
        $token = strtolower($token);
        $specific = [];
        $wildcard = [];
        foreach ($this->groups as $group) {
            foreach ($group['agents'] as $agent) {
                if ($agent === '*') {
                    $wildcard[] = $group;
                    break;
                }
                if ($agent !== '' && $agent === $token) {
                    $specific[] = $group;
                    break;
                }
            }
        }

        return $specific !== [] ? $specific : $wildcard;
    }

    public static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $regex = '#^' . implode('.*', array_map(static fn (string $part): string => preg_quote(self::decode($part), '#'), explode('*', $pattern))) . ($anchored ? '$' : '') . '#';

        return (bool) preg_match($regex, self::decode($path));
    }

    /** Decodes percent-encoded unreserved characters so "/%7Ejoe" and "/~joe" compare equal. */
    private static function decode(string $value): string
    {
        return (string) preg_replace_callback('/%([0-9A-Fa-f]{2})/', static function (array $m): string {
            $char = chr((int) hexdec($m[1]));

            return preg_match('/[A-Za-z0-9\-._~]/', $char) ? $char : '%' . strtoupper($m[1]);
        }, $value);
    }
}
