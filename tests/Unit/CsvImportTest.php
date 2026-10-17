<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Export\CsvExporter;
use App\Http\Controller\ImportController;
use App\Leads\CsvImporter;
use App\Tests\Support\TestCase;

final class CsvImportTest extends TestCase
{
    public function testParsesCommaSemicolonBomAndQuotes(): void
    {
        $parsed = CsvImporter::parse("\xEF\xBB\xBFCompany,Web Site,E-mail\n\"Crescent, Travel\",crescent.example,info@crescent.example\n\n");
        self::assertSame(['Company', 'Web Site', 'E-mail'], $parsed['headers']);
        self::assertSame([['Crescent, Travel', 'crescent.example', 'info@crescent.example']], $parsed['rows']);

        $semi = CsvImporter::parse("Name;Phone\nAl Noor;+971 4 000 0000\n");
        self::assertSame(['Al Noor', '+971 4 000 0000'], $semi['rows'][0]);
    }

    public function testConvertsWindows1252(): void
    {
        $parsed = CsvImporter::parse("Name\n" . mb_convert_encoding('Café Travel', 'Windows-1252', 'UTF-8') . "\n");
        self::assertSame('Café Travel', $parsed['rows'][0][0]);
    }

    public function testGuessesMappingFromHeaders(): void
    {
        $mapping = CsvImporter::guessMapping(['Exhibitor', 'Stand', 'Web Site', 'E-mail', 'Telephone', 'Town', 'Contact_Person']);

        self::assertSame(['name' => 0, 'notes' => 1, 'website' => 2, 'email' => 3, 'phone' => 4, 'city' => 5, 'contact_name' => 6], $mapping);
    }

    public function testMapRowAppliesUserMappingAndIgnoresUnmapped(): void
    {
        $row = CsvImporter::mapRow(['Crescent', 'x', 'mailto:info@crescent.example'], ['name' => 0, 'email' => 2, 'website' => -1]);

        self::assertSame('Crescent', $row['name']);
        self::assertSame('info@crescent.example', $row['email']);
        self::assertSame('', $row['website']);
        self::assertSame('', $row['phone']);
    }

    public function testImportReportsDuplicatesAndInvalidRows(): void
    {
        $this->lead(['website' => 'https://crescent.example']);
        $rows = [
            ['Crescent Travel', 'www.crescent.example', 'info@crescent.example', ''],   // duplicate of existing lead
            ['Al Noor Tours', 'alnoor.example', 'bad-email', '0300 1234567'],
            ['Al Noor Again', 'https://alnoor.example/about', '', ''],                 // duplicate within the file
            ['', '', '', ''],                                                          // empty
            ['Phone Only', '', 'hello@phoneonly.example', '0300-1234567'],            // same phone as row 2
            ['Zamzam Travel', '', 'info@zamzam.example', ''],
        ];
        $mapping = ['name' => 0, 'website' => 1, 'email' => 2, 'phone' => 3];
        $campaignId = $this->campaign();

        $report = $this->app->csvImporter()->import($rows, $mapping, $campaignId, ['country' => 'Pakistan']);

        self::assertSame(2, $report['imported']);
        self::assertCount(3, $report['duplicates']);
        self::assertStringContainsString('Line 2', $report['duplicates'][0]);
        self::assertCount(1, array_filter($report['invalid'], static fn (string $l): bool => str_contains($l, 'not a valid email')));
        $zamzam = $this->db->one("SELECT * FROM leads WHERE name = 'Zamzam Travel'") ?? [];
        self::assertSame('https://zamzam.example', $zamzam['website'], 'website derived from the email domain');
        self::assertSame('Pakistan', $zamzam['country'], 'campaign default');
        self::assertSame('csv', $zamzam['source']);
        self::assertSame($campaignId, (int) $zamzam['campaign_id']);
    }

    public function testUploadValidationAcceptsOnlyCsv(): void
    {
        $dir = sys_get_temp_dir();
        $csv = tempnam($dir, 'csv');
        file_put_contents((string) $csv, "Name,Website\nA,a.example\n");
        $php = tempnam($dir, 'php');
        file_put_contents((string) $php, "<?php echo 'x';");

        self::assertNull(ImportController::validateUpload(['name' => 'list.csv', 'tmp_name' => $csv, 'size' => 30, 'error' => UPLOAD_ERR_OK]));
        self::assertSame('Only .csv files can be imported.', ImportController::validateUpload(['name' => 'list.php', 'tmp_name' => $csv, 'size' => 30, 'error' => UPLOAD_ERR_OK]));
        self::assertSame('That does not look like a CSV file.', ImportController::validateUpload(['name' => 'evil.csv', 'tmp_name' => $php, 'size' => 30, 'error' => UPLOAD_ERR_OK]));
        self::assertSame('The file is larger than 5 MB.', ImportController::validateUpload(['name' => 'big.csv', 'tmp_name' => $csv, 'size' => 6 * 1024 * 1024, 'error' => UPLOAD_ERR_OK]));
        self::assertSame('Choose a CSV file to upload.', ImportController::validateUpload(null));
        unlink((string) $csv);
        unlink((string) $php);
    }

    public function testExportNeutralisesFormulaInjection(): void
    {
        $this->lead(['name' => '=HYPERLINK("http://evil.example")', 'notes' => '+cmd']);
        $out = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertSame(1, $this->app->exporter()->write($out));
        rewind($out);
        $csv = (string) stream_get_contents($out);

        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringContainsString("'+cmd", $csv);
        self::assertSame("'-1", CsvExporter::safe('-1'));
        self::assertSame('Crescent', CsvExporter::safe('Crescent'));
    }
}
