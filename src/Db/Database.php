<?php

declare(strict_types=1);

namespace App\Db;

/**
 * A thin PDO wrapper: prepared statements only.
 */
final class Database
{
    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function sqlite(string $path): self
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
        }
        $db = new self(new \PDO('sqlite:' . $path));
        $db->pdo->exec('PRAGMA foreign_keys = ON');
        $db->pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $db->pdo->exec('PRAGMA journal_mode = WAL');
        }

        return $db;
    }

    public static function mysql(string $host, int $port, string $name, string $user, string $password): self
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        return new self(new \PDO($dsn, $user, $password));
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value) => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * @param array<string|int, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string|int, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $row column => value; column names come from code, never from input
     */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols))
        );
        $params = [];
        foreach ($row as $col => $value) {
            $params[':' . $col] = $value;
        }
        $this->run($sql, $params);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed>     $row
     * @param array<string|int, mixed> $whereParams
     */
    public function update(string $table, array $row, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($row as $col => $value) {
            $sets[] = $col . ' = :set_' . $col;
            $params[':set_' . $col] = $value;
        }

        return $this->run(sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where), $params + $whereParams)->rowCount();
    }

    /**
     * @template T
     *
     * @param callable(self): T $fn
     *
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
