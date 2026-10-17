<?php

declare(strict_types=1);

namespace App\Leads;

/**
 * Imports leads from a CSV file (exhibitor lists, directories you have the right to use)
 * with a user-chosen column mapping. Duplicates by domain/phone are reported, not inserted.
 */
final class CsvImporter
{
    public const FIELDS = [
        'name' => 'Business name',
        'website' => 'Website',
        'email' => 'Email',
        'phone' => 'Phone',
        'contact_name' => 'Contact person',
        'linkedin_url' => 'LinkedIn URL',
        'city' => 'City',
        'country' => 'Country',
        'notes' => 'Notes',
    ];

    /** Header names we recognise automatically, per field. */
    private const GUESSES = [
        'name' => ['name', 'company', 'company name', 'business', 'business name', 'agency', 'agency name', 'exhibitor', 'organisation', 'organization'],
        'website' => ['website', 'web', 'url', 'site', 'web site', 'homepage', 'domain'],
        'email' => ['email', 'e mail', 'email address', 'e mail address', 'mail'],
        'phone' => ['phone', 'telephone', 'tel', 'phone number', 'mobile', 'contact number', 'whatsapp'],
        'contact_name' => ['contact', 'contact name', 'contact person', 'person', 'full name'],
        'linkedin_url' => ['linkedin', 'linkedin url', 'linkedin profile'],
        'city' => ['city', 'town', 'location'],
        'country' => ['country', 'nation'],
        'notes' => ['notes', 'note', 'comments', 'description', 'stand', 'booth'],
    ];

    public const MAX_ROWS = 5000;
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private LeadRepository $leads, private Deduplicator $dedup)
    {
    }

    /**
     * Reads a CSV string into a header row and data rows. Detects ; or tab
     * separators and strips a UTF-8 BOM.
     *
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public static function parse(string $csv): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($csv, "\n") ?: '';
        $delimiter = ',';
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($firstLine, $candidate) > substr_count($firstLine, $delimiter)) {
                $delimiter = $candidate;
            }
        }
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['headers' => [], 'rows' => []];
        }
        fwrite($handle, $csv);
        rewind($handle);
        $headers = [];
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $row = array_map(static fn ($v): string => trim((string) $v), $row);
            if ($row === [''] || implode('', $row) === '') {
                continue;
            }
            if ($headers === []) {
                $headers = $row;
                continue;
            }
            $rows[] = $row;
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Suggests field → column index from the header names.
     *
     * @param list<string> $headers
     *
     * @return array<string, int>
     */
    public static function guessMapping(array $headers): array
    {
        $mapping = [];
        foreach ($headers as $index => $header) {
            $h = strtolower(trim((string) preg_replace('/[_\-]+/', ' ', $header)));
            foreach (self::GUESSES as $field => $names) {
                if (!isset($mapping[$field]) && in_array($h, $names, true)) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Applies a mapping (field → column index, -1/absent = not imported) to one row.
     *
     * @param list<string>       $row
     * @param array<string, int> $mapping
     *
     * @return array<string, string>
     */
    public static function mapRow(array $row, array $mapping): array
    {
        $out = [];
        foreach (self::FIELDS as $field => $_label) {
            $index = $mapping[$field] ?? -1;
            $out[$field] = $index >= 0 && isset($row[$index]) ? trim($row[$index]) : '';
        }
        // A website column sometimes holds an email or a bare domain; an email column sometimes "mailto:".
        $out['email'] = (string) preg_replace('/^mailto:/i', '', $out['email']);

        return $out;
    }

    /**
     * @param list<list<string>> $rows
     * @param array<string, int> $mapping
     * @param array<string, string> $defaults e.g. country/city from the campaign
     *
     * @return array{imported: int, duplicates: list<string>, invalid: list<string>}
     */
    public function import(array $rows, array $mapping, ?int $campaignId, array $defaults = []): array
    {
        $report = ['imported' => 0, 'duplicates' => [], 'invalid' => []];
        foreach ($rows as $i => $row) {
            $line = $i + 2; // header is line 1
            $data = self::mapRow($row, $mapping);
            foreach ($defaults as $field => $value) {
                if (($data[$field] ?? '') === '' && $value !== '') {
                    $data[$field] = $value;
                }
            }
            if ($data['name'] === '' && $data['website'] === '' && $data['email'] === '') {
                $report['invalid'][] = sprintf('Line %d: needs at least a name, website or email.', $line);
                continue;
            }
            if ($data['email'] !== '' && Normalizer::email($data['email']) === null) {
                $report['invalid'][] = sprintf('Line %d: "%s" is not a valid email (imported without it).', $line, $data['email']);
                $data['email'] = '';
            }
            if ($data['website'] === '' && $data['email'] !== '') {
                $domain = Deduplicator::dedupDomain($data['email']);
                if ($domain !== null) {
                    $data['website'] = 'https://' . $domain;
                }
            }
            $duplicate = $this->dedup->findDuplicate($data['website'], $data['phone'], $data['country']);
            if ($duplicate !== null) {
                $report['duplicates'][] = sprintf('Line %d (%s): %s.', $line, $data['name'] !== '' ? $data['name'] : $data['website'], $duplicate);
                continue;
            }
            $this->dedup->remember($data['website'], $data['phone'], $data['country']);
            $this->leads->create($data + ['campaign_id' => $campaignId], 'csv');
            $report['imported']++;
        }

        return $report;
    }
}
