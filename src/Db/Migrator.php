<?php

declare(strict_types=1);

namespace App\Db;

/**
 * Applies migrations/<driver>/*.sql once each, in name order.
 */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** @return list<string> names applied by this run */
    public function migrate(): array
    {
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, applied_at VARCHAR(19) NOT NULL)');
        $done = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');
        $files = glob($this->dir . '/' . $this->db->driver() . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach (self::statements((string) file_get_contents($file)) as $sql) {
                $this->db->pdo()->exec($sql);
            }
            $this->db->insert('schema_migrations', ['name' => $name, 'applied_at' => gmdate('Y-m-d H:i:s')]);
            $applied[] = $name;
        }

        return $applied;
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        return array_values(array_filter(array_map('trim', explode(';', $sql)), static fn (string $s): bool => $s !== ''));
    }
}
