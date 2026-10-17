<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Reads a .env file into an immutable map. Real environment variables win, so
 * hosts that set them (cPanel, SetEnv) do not need a .env file at all.
 *
 * Values are never written to putenv(), keeping secrets out of child processes.
 */
final class Env
{
    /** @param array<string, string> $values */
    public function __construct(private array $values = [])
    {
    }

    public static function load(string $path): self
    {
        $values = is_file($path) ? self::parse((string) file_get_contents($path)) : [];
        foreach (array_keys($values) as $key) {
            $real = getenv($key);
            if (is_string($real) && $real !== '') {
                $values[$key] = $real;
            }
        }

        return new self($values);
    }

    /** @return array<string, string> */
    public static function parse(string $contents): array
    {
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                continue;
            }
            $quote = $value[0] ?? '';
            if (($quote === '"' || $quote === "'") && strlen($value) >= 2 && str_ends_with($value, $quote)) {
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\"'], ["\n", '"'], $value);
                }
            } elseif (($hash = strpos($value, ' #')) !== false) {
                $value = rtrim(substr($value, 0, $hash));
            }
            $values[$key] = $value;
        }

        return $values;
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            $real = getenv($key);

            return is_string($real) && $real !== '' ? $real : $default;
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        $value = $this->get($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    /** @return list<string> */
    public function list(string $key, string $default = ''): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get($key, $default))), static fn (string $v): bool => $v !== ''));
    }
}
