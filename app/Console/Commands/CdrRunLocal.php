<?php

namespace App\Console\Commands;

use App\Services\CdrTransformService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CdrRunLocal extends Command
{
    protected $signature = 'cdr:run-local
                            {--mmg-path= : Path to local MMG directory}
                            {--occ-path= : Path to local OCC directory}';

    protected $description = 'Upload CDR files from local computer -> validate -> load Oracle staging -> verify -> OUT/ERR';

    public function handle(): int
    {
        $local = Storage::disk('local');

        // Convert Windows paths to WSL paths
        $defaultMmgPath = '/mnt/c/Users/Ahmed Amin Bejoui/Desktop/CDR MMG';
        $defaultOccPath = '/mnt/c/Users/Ahmed Amin Bejoui/Desktop/CDR OCC';

        $sources = [
            'MMG' => $this->option('mmg-path') ?: $defaultMmgPath,
            'OCC' => $this->option('occ-path') ?: $defaultOccPath,
        ];

        foreach ($sources as $sourceDir => $localPath) {
            $this->info("=== SOURCE {$sourceDir} ({$localPath}) ===");

            // Check if directory exists
            if (!is_dir($localPath)) {
                $this->warn("Directory does not exist: {$localPath}");
                continue;
            }

            // Get all CSV files from the directory
            $localFiles = glob($localPath . '/*.csv');
            if (empty($localFiles)) {
                $this->warn("No CSV files found in: {$localPath}");
                continue;
            }
            $this->line("Found " . count($localFiles) . " CSV files to process.");

            foreach ($localFiles as $localFilePath) {
                $fileName = basename($localFilePath);
                $fileSize = filesize($localFilePath);
                $this->line("PROCESSING: {$fileName} size={$fileSize}");

                if (!$fileSize || $fileSize <= 0) {
                    $this->warn("Skip (size 0): {$fileName}");
                    continue;
                }

                // Anti-doublon : déjà SUCCESS => SKIP
                $already = DB::table('LOAD_AUDIT')
                    ->where('SOURCE_DIR', $sourceDir)
                    ->where('FILE_NAME', $fileName)
                    ->where('FILE_SIZE', $fileSize)
                    ->where('STATUS', 'SUCCESS')
                    ->exists();

                if ($already) {
                    $this->line("SKIP already SUCCESS: {$fileName}");
                    continue;
                }

                // Upsert to tolerate reruns after interrupted executions.
                DB::table('LOAD_AUDIT')->updateOrInsert(
                    [
                        'SOURCE_DIR' => $sourceDir,
                        'FILE_NAME'  => $fileName,
                        'FILE_SIZE'  => $fileSize,
                    ],
                    [
                        'STATUS'  => 'DOWNLOADED',
                        'LOAD_TS' => now(),
                        'MESSAGE' => 'LOCAL_UPLOAD',
                    ]
                );

                // Copy to IN directory
                $inPath = "cdr/IN/{$sourceDir}/{$fileName}";

                try {
                    $this->copyLocalToStorage($local, $localFilePath, $inPath, $fileSize);
                } catch (\Throwable $e) {
                    $this->failToErr($local, $sourceDir, $inPath, $fileName, $fileSize, "COPY_ERROR: ".$e->getMessage());
                    continue;
                }

                // Read/validate header only; strict row validation is done during load
                try {
                    $headerCols = $this->readCsvHeader($local->path($inPath));
                    $this->line("  CSV header validated: cols=" . count($headerCols));
                } catch (\Throwable $e) {
                    $this->failToErr($local, $sourceDir, $inPath, $fileName, $fileSize, "CSV_INVALID: ".$e->getMessage());
                    continue;
                }

                // Valider colonnes contre whitelist (mode TMP: dynamique depuis Oracle)
                $transformService = new CdrTransformService();
                $sourceType = strtolower($sourceDir); // 'mmg' ou 'occ'
                $validation = $transformService->validateColumns($headerCols, $sourceType, 'tmp');
                if (!$validation['valid']) {
                    $unknownCols = implode(', ', $validation['unknown_columns']);
                    $this->failToErr($local, $sourceDir, $inPath, $fileName, $fileSize, "WHITELIST_ERROR: Unknown columns: {$unknownCols}");
                    continue;
                }

                // Créer/assurer table staging selon header
                $table = $sourceDir === 'MMG' ? 'RA_T_TMP_MMG' : 'RA_T_TMP_OCC';

                // Table staging déjà existante, on suppose la structure correcte
                try {
                    $oracleCols = $this->getOracleColsFromHeader($table, $headerCols);
                } catch (\Throwable $e) {
                    $this->failToErr($local, $sourceDir, $inPath, $fileName, $fileSize, "DDL_ERROR: ".$e->getMessage());
                    continue;
                }

                // Rerun idempotent: purge stale TMP rows for same file before loading
                $staleRows = (int) DB::table($table)
                    ->where('SOURCE_FILE', $fileName)
                    ->where('SOURCE_DIR', $sourceDir)
                    ->delete();
                if ($staleRows > 0) {
                    $this->warn("TMP cleanup before reload: {$fileName} removed={$staleRows}");
                }

                // Load DB (batch insert)
                try {
                    $this->line("  Loading into {$table} ...");
                    DB::beginTransaction();
                    $rowsDb = $this->loadCsvToOracle($local->path($inPath), $table, $oracleCols, $sourceDir, $fileName);
                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $this->failToErr($local, $sourceDir, $inPath, $fileName, $fileSize, "LOAD_ERROR: ".$e->getMessage());
                    continue;
                }

                $rowsCsv = $rowsDb;
                $rowsDbCount = $rowsDb;

                // Transformer TMP -> DETAIL
                try {
                    $transformService = new CdrTransformService();
                    if ($sourceDir === 'OCC') {
                        $transformStats = $transformService->transformOccTmpToDetail($fileName);
                    } elseif ($sourceDir === 'MMG') {
                        $transformStats = $transformService->transformMmgTmpToDetail($fileName);
                    } else {
                        throw new \Exception("Unknown source type: {$sourceDir}");
                    }

                    $this->info("  TMP->DETAIL: inserted={$transformStats['inserted']}, rejected={$transformStats['rejected']}");
                    Log::channel('cdr')->info("Transform SUCCESS for {$fileName}", $transformStats);

                    // SUCCESS -> déplacer en OUT
                    DB::table('LOAD_AUDIT')
                        ->where('SOURCE_DIR', $sourceDir)
                        ->where('FILE_NAME', $fileName)
                        ->where('FILE_SIZE', $fileSize)
                        ->update([
                            'STATUS'   => 'SUCCESS',
                            'ROWS_CSV' => $rowsCsv,
                            'ROWS_DB'  => $rowsDbCount,
                            'LOAD_TS'  => now(),
                            'MESSAGE'  => "TMP:{$transformStats['tmpRows']} DETAIL:{$transformStats['inserted']} REJECTED:{$transformStats['rejected']}",
                        ]);
                } catch (\Throwable $e) {
                    Log::channel('cdr')->error("Transform FAILED for {$fileName}: ".$e->getMessage());
                    $this->failToErr($local, $sourceDir, $inPath, $fileName, $fileSize, "TRANSFORM_ERROR: ".$e->getMessage());
                    continue;
                }

                $outPath = "cdr/OUT/{$sourceDir}/{$fileName}";
                $local->move($inPath, $outPath);

                $this->info("SUCCESS: {$fileName} rows={$rowsDbCount} -> OUT/{$sourceDir}");
            }
        }

        return self::SUCCESS;
    }

    private function copyLocalToStorage($local, string $localFilePath, string $storagePath, int $expectedSize): void
    {
        // Faster than reading full file into memory with file_get_contents.
        $targetPath = $local->path($storagePath);
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException("Cannot create directory: {$targetDir}");
        }
        if (!copy($localFilePath, $targetPath)) {
            throw new \RuntimeException("Cannot copy local file: {$localFilePath}");
        }

        // Verify size
        clearstatcache(true, $targetPath);
        $localSize = filesize($targetPath);
        if ($localSize !== $expectedSize) {
            @unlink($targetPath);
            throw new \RuntimeException("Size mismatch: storage={$localSize} expected={$expectedSize}");
        }
    }

    private function readCsvHeader(string $fullPath): array
    {
        $delimiter = env('CSV_DELIMITER', ',');
        $enclosure = env('CSV_ENCLOSURE', '"');

        $fh = fopen($fullPath, 'r');
        if (!$fh) throw new \RuntimeException("Cannot open file");

        $firstLine = fgets($fh);
        fclose($fh);
        if ($firstLine === false) throw new \RuntimeException("Empty file");

        $firstLine = rtrim($firstLine, "\r\n");
        $header = str_getcsv($firstLine, $delimiter, $enclosure);
        if (count($header) < 1) throw new \RuntimeException("Invalid header");

        return $header;
    }

    private function getOracleColsFromHeader(string $table, array $headerCols): array
    {
        $oracleCols = [];
        $used = [];
        foreach ($headerCols as $col) {
            $name = $this->sanitizeOracleColumnName($col, $used);
            $used[] = $name;
            $oracleCols[] = $name;
        }
        return $oracleCols;
    }

    /**
     * Charge un CSV dans Oracle via INSERT ALL avec bind variables.
     *
     * Optimisations vs l'ancien DB::table()->insert() :
     *  - SQL préparé une seule fois (parsed once par Oracle, réutilisé)
     *  - Bind variables au lieu de littéraux (shared pool Oracle)
     *  - SYSDATE côté serveur au lieu de Carbon PHP
     *  - Query log désactivé (économie mémoire)
     *  - PDO direct sans couche query builder
     */
    private function loadCsvToOracle(string $fullPath, string $table, array $oracleCols, string $sourceDir, string $fileName): int
    {
        $delimiter = env('CSV_DELIMITER', ',');
        $enclosure = env('CSV_ENCLOSURE', '"');
        $batchSize = max(200, (int) env('CDR_BATCH_SIZE', 2000));
        $progressEvery = (int) env('CDR_PROGRESS_EVERY', 50000);

        DB::disableQueryLog();

        $fh = fopen($fullPath, 'r');
        if (!$fh) throw new \RuntimeException("Cannot open file");

        fgets($fh); // skip header
        $expectedCols = count($oracleCols);

        // Colonnes bindées (CSV + SOURCE_FILE + SOURCE_DIR) + LOAD_TS via SYSDATE
        $bindCols  = array_merge($oracleCols, ['SOURCE_FILE', 'SOURCE_DIR']);
        $allCols   = array_merge($bindCols, ['LOAD_TS']);
        $colList   = implode(', ', $allCols);
        $nBindCols = count($bindCols);

        $pdo = DB::connection()->getPdo();

        // Pré-compiler INSERT ALL pour le batch standard (réutilisé à chaque lot)
        $fullBatchSql  = $this->buildInsertAllSql($table, $colList, $nBindCols, $batchSize);
        $fullBatchStmt = $pdo->prepare($fullBatchSql);

        $batch = [];
        $count = 0;

        $lineNo = 1; // header
        while (($line = fgets($fh)) !== false) {
            $lineNo++;
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;

            // Keep strict CSV rule while loading to avoid a second full-file pass.
            if ((substr_count($line, $enclosure) % 2) !== 0) {
                throw new \RuntimeException("Broken line (unbalanced quotes) at data line {$lineNo}");
            }

            $vals = str_getcsv($line, $delimiter, $enclosure);
            if (count($vals) !== $expectedCols) {
                throw new \RuntimeException("Wrong column count at data line {$lineNo} got=".count($vals)." expected={$expectedCols}");
            }

            $row = [];
            foreach ($oracleCols as $i => $colName) {
                $row[] = isset($vals[$i]) ? (string)$vals[$i] : null;
            }
            $row[] = $fileName;   // SOURCE_FILE
            $row[] = $sourceDir;  // SOURCE_DIR

            $batch[] = $row;
            $count++;

            if (count($batch) >= $batchSize) {
                $this->executeBulkInsert($fullBatchStmt, $batch, $nBindCols);
                $batch = [];
            }

            if ($progressEvery > 0 && $count % $progressEvery === 0) {
                $this->info("  ... {$count} rows loaded");
            }
        }

        // Lot résiduel (taille variable → statement dédié)
        if ($batch) {
            $partialSql  = $this->buildInsertAllSql($table, $colList, $nBindCols, count($batch));
            $partialStmt = $pdo->prepare($partialSql);
            $this->executeBulkInsert($partialStmt, $batch, $nBindCols);
        }

        fclose($fh);
        DB::enableQueryLog();
        return $count;
    }

    /**
     * Construit INSERT ALL avec bind variables nommées + SYSDATE.
     */
    private function buildInsertAllSql(string $table, string $colList, int $nBindCols, int $nRows): string
    {
        $parts = [];
        for ($r = 0; $r < $nRows; $r++) {
            $ph = [];
            for ($c = 0; $c < $nBindCols; $c++) {
                $ph[] = ":p{$r}_{$c}";
            }
            $ph[] = 'SYSDATE';
            $parts[] = "INTO {$table} ({$colList}) VALUES (" . implode(',', $ph) . ")";
        }
        return "INSERT ALL " . implode(' ', $parts) . " SELECT 1 FROM DUAL";
    }

    /**
     * Exécute un INSERT ALL préparé avec les valeurs du lot.
     */
    private function executeBulkInsert(\PDOStatement $stmt, array $batch, int $nBindCols): void
    {
        $params = [];
        foreach ($batch as $r => $row) {
            for ($c = 0; $c < $nBindCols; $c++) {
                $params[":p{$r}_{$c}"] = $row[$c];
            }
        }
        $stmt->execute($params);
    }

    private function failToErr($local, string $sourceDir, string $inPath, string $fileName, int $fileSize, string $message): void
    {
        DB::table('LOAD_AUDIT')
            ->where('SOURCE_DIR', $sourceDir)
            ->where('FILE_NAME', $fileName)
            ->where('FILE_SIZE', $fileSize)
            ->update([
                'STATUS'  => 'ERROR',
                'LOAD_TS' => now(),
                'MESSAGE' => substr($message, 0, 4000),
            ]);

        $errPath = "cdr/ERR/{$sourceDir}/{$fileName}";
        if ($local->exists($inPath)) {
            $local->move($inPath, $errPath);
        }

        $this->error("ERROR: {$fileName} => ERR/{$sourceDir} | {$message}");
    }

    private function sanitizeOracleColumnName(string $raw, array $used): string
    {
        $s = strtoupper(trim($raw));
        $s = preg_replace('/[^A-Z0-9]+/', '_', $s);
        $s = trim($s, '_');

        if ($s === '' || !preg_match('/^[A-Z]/', $s)) {
            $s = 'C_' . $s;
        }

        $s = substr($s, 0, 30);
        if ($s === '') $s = 'C_COL';

        $base = $s;
        $i = 1;
        while (in_array($s, $used, true)) {
            $suffix = '_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            $s = substr($base, 0, 30 - strlen($suffix)) . $suffix;
            $i++;
        }

        return $s;
    }
}
