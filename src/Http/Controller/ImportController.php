<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Leads\CsvImporter;

final class ImportController extends Controller
{
    public function form(Request $request): Response
    {
        return $this->page('import/upload', ['title' => 'Import CSV', 'campaigns' => $this->app->campaigns()->all(), 'error' => '', 'campaignId' => $request->int('campaign_id')]);
    }

    public function upload(Request $request): Response
    {
        $file = $request->files['csv'] ?? null;
        $error = self::validateUpload($file);
        if ($error !== null || !is_array($file)) {
            return $this->page('import/upload', ['title' => 'Import CSV', 'campaigns' => $this->app->campaigns()->all(), 'error' => $error ?? 'Upload failed.', 'campaignId' => $request->int('campaign_id')], 422);
        }
        $parsed = CsvImporter::parse((string) file_get_contents((string) $file['tmp_name']));
        if ($parsed['headers'] === [] || $parsed['rows'] === []) {
            return $this->page('import/upload', ['title' => 'Import CSV', 'campaigns' => $this->app->campaigns()->all(), 'error' => 'The file has no header row or no data rows.', 'campaignId' => $request->int('campaign_id')], 422);
        }
        // Keep the parsed rows server-side (outside the web root) until the mapping is confirmed.
        $dir = $this->app->root . '/var/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $id = bin2hex(random_bytes(12));
        file_put_contents($dir . '/' . $id . '.json', (string) json_encode($parsed, JSON_UNESCAPED_UNICODE));
        $this->session->set('_import', $id);

        return $this->page('import/map', [
            'title' => 'Map columns',
            'headers' => $parsed['headers'],
            'preview' => array_slice($parsed['rows'], 0, 5),
            'rowCount' => count($parsed['rows']),
            'mapping' => CsvImporter::guessMapping($parsed['headers']),
            'campaigns' => $this->app->campaigns()->all(),
            'campaignId' => $request->int('campaign_id'),
        ]);
    }

    public function confirm(Request $request): Response
    {
        $id = $this->session->get('_import');
        $path = is_string($id) && preg_match('/^[a-f0-9]{24}$/', $id) ? $this->app->root . '/var/uploads/' . $id . '.json' : '';
        if ($path === '' || !is_file($path)) {
            return $this->back($request, '/import', 'error', 'The upload expired. Please upload the file again.');
        }
        $parsed = json_decode((string) file_get_contents($path), true);
        unlink($path);
        $this->session->remove('_import');
        if (!is_array($parsed) || !is_array($parsed['rows'] ?? null)) {
            return $this->back($request, '/import', 'error', 'The upload could not be read.');
        }
        $mapping = [];
        foreach (array_keys(CsvImporter::FIELDS) as $field) {
            $mapping[$field] = $request->int('map_' . $field, -1);
        }
        $campaignId = $request->int('campaign_id');
        $campaign = $campaignId > 0 ? $this->campaignFor($campaignId) : null;
        /** @var list<list<string>> $rows */
        $rows = $parsed['rows'];
        $report = $this->app->csvImporter()->import($rows, $mapping, $campaign !== null ? (int) $campaign['id'] : null, [
            'country' => $campaign !== null ? (string) $campaign['country'] : '',
        ]);
        $this->session->set('_report', ['title' => 'CSV import', 'imported' => $report['imported'], 'duplicates' => $report['duplicates'], 'skipped' => $report['invalid']]);

        return $this->back(
            $request,
            $campaign !== null ? '/campaigns/' . $campaign['id'] : '/leads',
            'success',
            sprintf('%d lead(s) imported, %d duplicate(s) skipped, %d problem(s).', $report['imported'], count($report['duplicates']), count($report['invalid']))
        );
    }

    /**
     * Only CSV files: checked by extension, size and content (no binary, no PHP/HTML).
     */
    public static function validateUpload(mixed $file): ?string
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
            return 'Choose a CSV file to upload.';
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return (int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE ? 'The file is too large.' : 'The upload failed.';
        }
        if ((int) $file['size'] > CsvImporter::MAX_BYTES) {
            return 'The file is larger than 5 MB.';
        }
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            return 'Only .csv files can be imported.';
        }
        if (PHP_SAPI !== 'cli' && !is_uploaded_file((string) $file['tmp_name'])) {
            return 'The upload failed.';
        }
        $head = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, 4096);
        if (str_contains($head, "\0") || preg_match('/<\?php|<script|<html/i', $head)) {
            return 'That does not look like a CSV file.';
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo !== false ? (string) finfo_file($finfo, (string) $file['tmp_name']) : '';
            if ($mime !== '' && !in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/comma-separated-values', 'application/octet-stream'], true)) {
                return 'That does not look like a CSV file (' . $mime . ').';
            }
        }

        return null;
    }
}
