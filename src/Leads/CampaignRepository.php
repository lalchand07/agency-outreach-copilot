<?php

declare(strict_types=1);

namespace App\Leads;

use App\Db\Database;
use App\Support\Clock;

final class CampaignRepository
{
    public const LANGUAGES = ['en' => 'English', 'ur' => 'Urdu', 'ar' => 'Arabic'];
    public const CHANNELS = ['email' => 'Email', 'linkedin' => 'LinkedIn (manual)', 'whatsapp' => 'WhatsApp (manual)'];
    private const JSON = ['cities', 'keywords', 'languages', 'channels'];

    public function __construct(private Database $db, private Clock $clock)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map([$this, 'decode'], $this->db->all(
            'SELECT c.*, (SELECT COUNT(*) FROM leads l WHERE l.campaign_id = c.id) AS lead_count FROM campaigns c ORDER BY c.id DESC'
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->one('SELECT * FROM campaigns WHERE id = ?', [$id]);

        return $row === null ? null : $this->decode($row);
    }

    /** @param array<string, mixed> $input */
    public function save(array $input, ?int $id = null): int
    {
        $row = self::clean($input);
        $row['updated_at'] = $this->clock->nowString();
        if ($id === null) {
            $row['created_at'] = $row['updated_at'];

            return $this->db->insert('campaigns', $row);
        }
        $this->db->update('campaigns', $row, 'id = :id', [':id' => $id]);

        return $id;
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM campaigns WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    public static function clean(array $input): array
    {
        $list = static function (mixed $value): array {
            $items = is_array($value) ? $value : preg_split('/[\n,]+/', (string) $value);

            return array_values(array_unique(array_filter(array_map(static fn ($v): string => trim((string) $v), $items ?: []), static fn (string $v): bool => $v !== '')));
        };
        $languages = array_values(array_intersect($list($input['languages'] ?? ['en']), array_keys(self::LANGUAGES)));
        $channels = array_values(array_intersect($list($input['channels'] ?? ['email']), array_keys(self::CHANNELS)));

        return [
            'name' => mb_substr(trim((string) ($input['name'] ?? '')), 0, 190) ?: 'Untitled campaign',
            'country' => mb_substr(trim((string) ($input['country'] ?? '')), 0, 80),
            'cities' => (string) json_encode(array_slice($list($input['cities'] ?? []), 0, 50), JSON_UNESCAPED_UNICODE),
            'keywords' => (string) json_encode(array_slice($list($input['keywords'] ?? []), 0, 30), JSON_UNESCAPED_UNICODE),
            'languages' => (string) json_encode($languages !== [] ? $languages : ['en']),
            'channels' => (string) json_encode($channels !== [] ? $channels : ['email']),
            'ideal_customer' => mb_substr(trim((string) ($input['ideal_customer'] ?? '')), 0, 2000),
            'notes' => mb_substr(trim((string) ($input['notes'] ?? '')), 0, 2000),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        foreach (self::JSON as $col) {
            $decoded = json_decode((string) ($row[$col] ?? '[]'), true);
            $row[$col] = is_array($decoded) ? $decoded : [];
        }
        $row['ideal_customer'] = (string) ($row['ideal_customer'] ?? '');
        $row['notes'] = (string) ($row['notes'] ?? '');

        return $row;
    }
}
