<?php

namespace App\Console\Commands;

use App\Services\CdrTransformService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CdrRun extends Command
{
    protected $signature = 'cdr:run';
    protected $description = 'FTP -> download -> validate -> load Oracle staging -> verify -> OUT/ERR -> delete remote';

    public function handle(): int
    {
        $ftp = Storage::disk('ftp');
        $local = Storage::disk('local');

        $sources = [
            'MMG' => env('FTP_DIR_MMG', '/home/MMG'),
            'OCC' => env('FTP_DIR_OCC', '/home/OCC'),
        ];

        foreach ($sources as $sourceDir => $remoteBase) {
            $this->info("=== SOURCE {$sourceDir} ({$remoteBase}) ===");

            $remoteFiles = $ftp->files($remoteBase);
            if (!$remoteFiles) {
                $this->warn("No files found or cannot access: {$remoteBase}");
                continue;
            }

            foreach ($remoteFiles as $remotePath) {
                $fileName = basename($remotePath);

                // on ne traite que CSV (adapte si besoin)
                if (!str_ends_with(strtolower($fileName), '.csv')) {
                    continue;
                }

                $fileSize = null;
                try { $fileSize = $ftp->size($remotePath); } catch (\Throwable $e) {}
                if (!$fileSize || $fileSize <= 0) {
                    $this->warn("Skip (size unknown/0): {$remotePath}");
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
                        'MESSAGE' => null,
                    ]
                );

                // Télécharger safe: TMP/*.part -> IN/<dir>/
                $tmpPart = "cdr/TMP/{$fileName}.part";
                $inPath  = "cdr/IN/{$sourceDir}/{$fileName}";

                try {
                    $this->downloadAtomic($ftp, $local, $remotePath, $tmpPart, $inPath, $fileSize);
                } catch (\Throwable $e) {
                    $this->failToErr($local, $sourceDir, $inPath, $fileName, $fileSize, "DOWNLOAD_ERROR: ".$e->getMessage());
                    continue;
                }

                // Validation CSV stricte (newline = record delimiter)
                try {
                    [$headerCols, $rowsCsv] = $this->validateCsvStrict($local->path($inPath));
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

                // Load DB (batch insert)
                try {
                    DB::beginTransaction();
                    $rowsDb = $this->loadCsvToOracle($local->path($inPath), $table, $oracleCols, $sourceDir, $fileName);
                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $this->failToErr($local, $sourceDir, $inPath, $fileName, $fileSize, "LOAD_ERROR: ".$e->getMessage());
                    continue;
                }

                // Vérification rows_csv vs rows_db (COUNT by SOURCE_FILE)
                $rowsDbCount = (int) DB::table($table)->where('SOURCE_FILE', $fileName)->count();
                if ($rowsDbCount !== $rowsCsv) {
                    // rollback logique : delete rows de ce fichier
                    DB::table($table)->where('SOURCE_FILE', $fileName)->delete();
                    $this->failToErr($local, $sourceDir, $inPath, $fileName, $fileSize, "COUNT_MISMATCH rows_csv={$rowsCsv} rows_db={$rowsDbCount}");
                    continue;
                }

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

                    // SUCCESS -> déplacer en OUT + supprimer FTP
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

                if (filter_var(env('FTP_DELETE_AFTER_SUCCESS', true), FILTER_VALIDATE_BOOLEAN)) {
                    try { $ftp->delete($remotePath); } catch (\Throwable $e) {}
                }

                $this->info("SUCCESS: {$fileName} rows={$rowsDbCount} -> OUT/{$sourceDir} (remote deleted)");
            }
        }

        return self::SUCCESS;
    }

    private function downloadAtomic($ftp, $local, string $remotePath, string $tmpPart, string $finalIn, int $expectedSize): void
    {
        $stream = $ftp->readStream($remotePath);
        if (!$stream) {
            throw new \RuntimeException("Cannot readStream remote: {$remotePath}");
        }

        $out = fopen($local->path($tmpPart), 'w');
        if (!$out) {
            throw new \RuntimeException("Cannot write tmp: {$tmpPart}");
        }

        stream_copy_to_stream($stream, $out);
        fclose($out);
        if (is_resource($stream)) fclose($stream);

        $localSize = filesize($local->path($tmpPart));
        if ($localSize !== $expectedSize) {
            // move tmp part to ERR
            throw new \RuntimeException("Size mismatch: local={$localSize} expected={$expectedSize}");
        }

        // Move tmp -> IN
        $local->move($tmpPart, $finalIn);
    }

    private function validateCsvStrict(string $fullPath): array
    {
        $delimiter = env('CSV_DELIMITER', ',');
        $enclosure = env('CSV_ENCLOSURE', '"');

        $fh = fopen($fullPath, 'r');
        if (!$fh) throw new \RuntimeException("Cannot open file");

        $firstLine = fgets($fh);
        if ($firstLine === false) throw new \RuntimeException("Empty file");
        $firstLine = rtrim($firstLine, "\r\n");

        $header = str_getcsv($firstLine, $delimiter, $enclosure);
        $nHeader = count($header);
        if ($nHeader < 1) throw new \RuntimeException("Invalid header");

        $rows = 0;
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;

            // Règle “newline = record delimiter” + protection minimale :
            // si quotes non équilibrés => record cassé => ERR
            if ((substr_count($line, $enclosure) % 2) !== 0) {
                fclose($fh);
                throw new \RuntimeException("Broken line (unbalanced quotes) at data line ".($rows+2));
            }

            $cols = str_getcsv($line, $delimiter, $enclosure);
            if (count($cols) !== $nHeader) {
                fclose($fh);
                throw new \RuntimeException("Wrong column count at data line ".($rows+2)." got=".count($cols)." expected={$nHeader}");
            }

            $rows++;
        }

        fclose($fh);
        return [$header, $rows];
    }

    private function ensureStagingTable(string $table, array $headerCols): array
    {
        // Création dynamique désactivée : on suppose la table déjà existante
        throw new \RuntimeException('ensureStagingTable() ne doit plus être appelée. Utilisez getOracleColsFromHeader.');
    }

    // Nouvelle méthode pour obtenir les noms de colonnes Oracle à partir du header CSV
    private function getOracleColsFromHeader(string $table, array $headerCols): array
    {
        // On suppose que les noms de colonnes du CSV correspondent à ceux de la table Oracle
        // (adapter ici si besoin de mapping ou de nettoyage)
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
        $batchSize = (int) env('CDR_BATCH_SIZE', 500);

        DB::disableQueryLog();

        $fh = fopen($fullPath, 'r');
        if (!$fh) throw new \RuntimeException("Cannot open file");

        fgets($fh); // skip header

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

        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;

            $vals = str_getcsv($line, $delimiter, $enclosure);

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

            if ($count % 50000 === 0) {
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
