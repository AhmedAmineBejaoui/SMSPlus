<?php

namespace App\Console\Commands;

use App\Services\CdrTransformService;
use Illuminate\Console\Command;

class CdrPreflight extends Command
{
    protected $signature = 'cdr:preflight
                            {file : Path to the CSV file}
                            {--type=occ : Source type (occ or mmg)}
                            {--mode=detail : Column validation mode (detail or tmp)}
                            {--max-errors=20 : Maximum row errors to display}';

    protected $description = 'Pre-validate a CDR CSV before loading (header whitelist + row structure)';

    public function handle(): int
    {
        $sourceType = strtolower((string) $this->option('type'));
        $mode = strtolower((string) $this->option('mode'));
        $maxErrors = (int) $this->option('max-errors');

        if (!in_array($sourceType, ['occ', 'mmg'], true)) {
            $this->error("Invalid --type: {$sourceType}. Allowed values: occ, mmg.");
            return self::FAILURE;
        }

        if (!in_array($mode, ['detail', 'tmp'], true)) {
            $this->error("Invalid --mode: {$mode}. Allowed values: detail, tmp.");
            return self::FAILURE;
        }

        if ($maxErrors < 1) {
            $this->error('Invalid --max-errors: must be >= 1.');
            return self::FAILURE;
        }

        $filePath = $this->resolveCsvPath((string) $this->argument('file'));
        if (!is_file($filePath) || !is_readable($filePath)) {
            $this->error("File not found or not readable: {$filePath}");
            return self::FAILURE;
        }

        try {
            $headerCols = $this->readCsvHeader($filePath);
        } catch (\Throwable $e) {
            $this->error('Invalid CSV header: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line("File: {$filePath}");
        $this->line('Header columns: ' . count($headerCols));

        $duplicateColumns = $this->findDuplicateColumns($headerCols);
        if (!empty($duplicateColumns)) {
            $this->error('Duplicate columns in header: ' . implode(', ', $duplicateColumns));
            return self::FAILURE;
        }

        $transformService = new CdrTransformService();
        $validation = $transformService->validateColumns($headerCols, $sourceType, $mode);

        if (!$validation['valid']) {
            $this->error('Unknown columns detected: ' . implode(', ', $validation['unknown_columns']));
            return self::FAILURE;
        }

        $this->info("Header whitelist validation passed (type={$sourceType}, mode={$mode}).");

        try {
            $scan = $this->scanRows($filePath, count($headerCols), $maxErrors);
        } catch (\Throwable $e) {
            $this->error('Unable to scan CSV rows: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('Data rows scanned: ' . $scan['data_rows']);
        $this->line('Empty lines skipped: ' . $scan['empty_rows']);

        if (!empty($scan['errors'])) {
            $this->error('Row structure errors: ' . count($scan['errors']));
            foreach ($scan['errors'] as $error) {
                $this->line("  - {$error}");
            }

            if ($scan['truncated']) {
                $this->line("  - Stopped after {$maxErrors} errors.");
            }

            return self::FAILURE;
        }

        $this->info('Preflight OK: CSV is valid for ingestion.');
        return self::SUCCESS;
    }

    private function resolveCsvPath(string $path): string
    {
        $isAbsoluteUnix = str_starts_with($path, '/');
        $isAbsoluteWindows = preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
        $isUncPath = str_starts_with($path, '\\\\');

        if ($isAbsoluteUnix || $isAbsoluteWindows || $isUncPath) {
            return $path;
        }

        return base_path($path);
    }

    private function readCsvHeader(string $fullPath): array
    {
        $delimiter = (string) env('CSV_DELIMITER', ',');
        $enclosure = (string) env('CSV_ENCLOSURE', '"');
        if ($enclosure === '') {
            $enclosure = '"';
        }

        $fh = fopen($fullPath, 'r');
        if (!$fh) {
            throw new \RuntimeException('Cannot open file.');
        }

        $firstLine = fgets($fh);
        fclose($fh);

        if ($firstLine === false) {
            throw new \RuntimeException('File is empty.');
        }

        $header = str_getcsv(rtrim($firstLine, "\r\n"), $delimiter, $enclosure);
        if (count($header) < 1) {
            throw new \RuntimeException('Invalid header.');
        }

        return $header;
    }

    private function findDuplicateColumns(array $headerCols): array
    {
        $counts = array_count_values($headerCols);
        $duplicates = [];

        foreach ($counts as $column => $count) {
            if ($count > 1) {
                $duplicates[] = $column;
            }
        }

        return $duplicates;
    }

    private function scanRows(string $fullPath, int $expectedCols, int $maxErrors): array
    {
        $delimiter = (string) env('CSV_DELIMITER', ',');
        $enclosure = (string) env('CSV_ENCLOSURE', '"');
        if ($enclosure === '') {
            $enclosure = '"';
        }

        $fh = fopen($fullPath, 'r');
        if (!$fh) {
            throw new \RuntimeException('Cannot open file.');
        }

        fgets($fh); // skip header

        $lineNo = 1;
        $dataRows = 0;
        $emptyRows = 0;
        $errors = [];
        $truncated = false;

        while (($line = fgets($fh)) !== false) {
            $lineNo++;
            $line = rtrim($line, "\r\n");

            if ($line === '') {
                $emptyRows++;
                continue;
            }

            if ((substr_count($line, $enclosure) % 2) !== 0) {
                $errors[] = "Line {$lineNo}: unbalanced quotes.";
                if (count($errors) >= $maxErrors) {
                    $truncated = true;
                    break;
                }
                continue;
            }

            $values = str_getcsv($line, $delimiter, $enclosure);
            if (count($values) !== $expectedCols) {
                $errors[] = "Line {$lineNo}: wrong column count (" . count($values) . " != {$expectedCols}).";
                if (count($errors) >= $maxErrors) {
                    $truncated = true;
                    break;
                }
                continue;
            }

            $dataRows++;
        }

        fclose($fh);

        return [
            'data_rows' => $dataRows,
            'empty_rows' => $emptyRows,
            'errors' => $errors,
            'truncated' => $truncated,
        ];
    }
}
