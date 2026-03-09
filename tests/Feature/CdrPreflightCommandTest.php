<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Tests\TestCase;

class CdrPreflightCommandTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_preflight_succeeds_for_valid_occ_csv(): void
    {
        $csvPath = $this->createTempCsv(
            "DATASOURCE,A_MSISDN,ORIG_START_TIME,APN,CALL_TYPE,EVENT_TYPE,CHARGING_ID,SERVICE_ID,SUBSCRIBER_TYPE,ROAMING_TYPE,PARTNER,FILTER_CODE,FLEX_FLD1,FLEX_FLD2,FLEX_FLD3\n" .
            "SRC,21611111111,1700000000,internet,data,usage,chg-1,svc-1,PREPAID,HOME,partner,flt,f1,f2,f3\n"
        );

        $this->artisan('cdr:preflight', [
            'file' => $csvPath,
            '--type' => 'occ',
            '--mode' => 'detail',
        ])
            ->expectsOutputToContain('Preflight OK')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_preflight_fails_on_unknown_header_columns(): void
    {
        $csvPath = $this->createTempCsv(
            "DATASOURCE,A_MSISDN,UNKNOWN_COL\n" .
            "SRC,21611111111,value\n"
        );

        $this->artisan('cdr:preflight', [
            'file' => $csvPath,
            '--type' => 'occ',
            '--mode' => 'detail',
        ])
            ->expectsOutputToContain('Unknown columns detected')
            ->expectsOutputToContain('UNKNOWN_COL')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_preflight_fails_on_row_column_count_mismatch(): void
    {
        $csvPath = $this->createTempCsv(
            "DATASOURCE,A_MSISDN\n" .
            "SRC,21611111111\n" .
            "SRC_ONLY\n"
        );

        $this->artisan('cdr:preflight', [
            'file' => $csvPath,
            '--type' => 'occ',
            '--mode' => 'detail',
        ])
            ->expectsOutputToContain('Row structure errors: 1')
            ->expectsOutputToContain('wrong column count')
            ->assertExitCode(Command::FAILURE);
    }

    private function createTempCsv(string $content): string
    {
        $dir = storage_path('framework/testing');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = tempnam($dir, 'cdr_preflight_');
        if ($path === false) {
            $this->fail('Unable to create temporary CSV file.');
        }

        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
