<?php

declare(strict_types=1);

namespace App\Config;

use App\Db\Database;

/**
 * Operational settings editable in the UI (never secrets).
 */
final class Settings
{
    public const DEFAULTS = [
        'daily_send_cap' => 20,
        'followup_after_days' => 5,
        'max_followups' => 1,
        'retention_days' => 180,
        'research_max_pages' => 5,
    ];

    /** Hard upper bounds so a typo cannot turn this into a bulk mailer. */
    public const MAX = [
        'daily_send_cap' => 200,
        'followup_after_days' => 60,
        'max_followups' => 3,
        'retention_days' => 3650,
        'research_max_pages' => 5,
    ];

    /** @var array<string, int>|null */
    private ?array $cache = null;

    public function __construct(private Database $db)
    {
    }

    public function int(string $name): int
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown setting ' . $name);
        }

        return $this->all()[$name];
    }

    /** @return array<string, int> */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = self::DEFAULTS;
            foreach ($this->db->all('SELECT name, value FROM settings') as $row) {
                $name = (string) $row['name'];
                if (array_key_exists($name, self::DEFAULTS)) {
                    $this->cache[$name] = (int) $row['value'];
                }
            }
        }

        return $this->cache;
    }

    public function set(string $name, int $value): void
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown setting ' . $name);
        }
        $min = $name === 'max_followups' ? 0 : 1;
        $value = max($min, min(self::MAX[$name], $value));
        $this->db->run('DELETE FROM settings WHERE name = ?', [$name]);
        $this->db->insert('settings', ['name' => $name, 'value' => (string) $value]);
        $this->cache = null;
    }
}
