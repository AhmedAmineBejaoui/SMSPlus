<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service de transformation des données CDR de TMP vers DETAIL.
 *
 * Responsabilités:
 * - Valider les colonnes contre la whitelist (config/cdr.php)
 * - Nettoyer et transformer les données (TRIM, UPPER, clean_msisdn, etc.)
 * - Convertir les types (VARCHAR -> NUMBER, DATE)
 * - Dédupliquer via CHARGING_ID (ou fallback CALL_REFERENCE/RECORD_ID)
 * - Insérer dans les tables DETAIL via MERGE
 * - Vérifier la cohérence (rows inserted vs rows TMP)
 * - Nettoyer TMP si SUCCESS
 */
class CdrTransformService
{
    /**
     * Transforme les données OCC de TMP vers DETAIL pour un fichier donné.
     *
     * @param string $fileName Nom du fichier à traiter (SOURCE_FILE)
     * @return array ['inserted' => int, 'rejected' => int, 'tmpRows' => int]
     * @throws \Exception Si erreur critique
     */
    public function transformOccTmpToDetail(string $fileName): array
    {
        DB::disableQueryLog();

        $tmpTable = config('cdr.tables.occ.tmp');
        $detailTable = config('cdr.tables.occ.detail');
        $mapping = config('cdr.occ_mapping');
        $dedupKeys = config('cdr.occ_dedup_keys');

        $pdo = DB::connection()->getPdo();

        // 1. Compter les lignes dans TMP pour ce fichier (via PDO pour performance)
        $tmpRows = (int) $pdo->query(
            "SELECT COUNT(*) FROM {$tmpTable} WHERE SOURCE_FILE = " . $pdo->quote($fileName)
        )->fetchColumn();

        if ($tmpRows === 0) {
            Log::channel('cdr')->warning("transformOccTmpToDetail: No rows in TMP for file {$fileName}");
            return ['inserted' => 0, 'rejected' => 0, 'tmpRows' => 0];
        }

        Log::channel('cdr')->info("transformOccTmpToDetail: Processing {$tmpRows} rows for file {$fileName}");

        // 2. Construire la requête MERGE pour insérer dans DETAIL avec déduplication
        $mergeQuery = $this->buildOccMergeQuery($tmpTable, $detailTable, $mapping, $dedupKeys, $fileName);

        // 3. Compter combien de lignes TMP passeront le filtre (estimation inserted)
        $whereClause = $this->buildWhereClause($mapping, config('cdr.timestamp_unit', 'seconds'));
        $validRowsCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM {$tmpTable} tmp WHERE tmp.SOURCE_FILE = " . $pdo->quote($fileName) . " AND {$whereClause}"
        )->fetchColumn();

        // 4. Exécuter le MERGE dans une transaction
        try {
            DB::beginTransaction();

            // Exécuter le MERGE
            DB::statement($mergeQuery);

            // Estimation: inserted = validRows (les lignes qui passent le filtre)
            // Note: le MERGE peut aussi filtrer des doublons (déjà dans DETAIL), donc c'est une estimation haute
            $insertedCount = $validRowsCount;

            // 5. Vérifier cohérence
            $rejectedCount = $tmpRows - $validRowsCount;

            if ($rejectedCount > 0) {
                Log::channel('cdr')->warning(
                    "transformOccTmpToDetail: {$rejectedCount}/{$tmpRows} rows rejected for file {$fileName}"
                );
            }

            // 6. Nettoyer TMP si stratégie = on_success (via PDO pour performance)
            $cleanupStrategy = config('cdr.tmp_cleanup_strategy', 'on_success');
            if ($cleanupStrategy === 'on_success') {
                $pdo->exec(
                    "DELETE FROM {$tmpTable} WHERE SOURCE_FILE = " . $pdo->quote($fileName)
                );
                Log::channel('cdr')->info("transformOccTmpToDetail: Cleaned TMP for file {$fileName}");
            }

            DB::commit();

            Log::channel('cdr')->info(
                "transformOccTmpToDetail SUCCESS: {$insertedCount} inserted, {$rejectedCount} rejected, {$tmpRows} tmpRows for file {$fileName}"
            );

            return [
                'inserted' => $insertedCount,
                'rejected' => $rejectedCount,
                'tmpRows' => $tmpRows,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::channel('cdr')->error("transformOccTmpToDetail ERROR for file {$fileName}: ".$e->getMessage());

            // Cleanup TMP si stratégie = on_error (via PDO pour performance)
            $cleanupStrategy = config('cdr.tmp_cleanup_strategy', 'on_success');
            if ($cleanupStrategy === 'on_error') {
                $pdo->exec(
                    "DELETE FROM {$tmpTable} WHERE SOURCE_FILE = " . $pdo->quote($fileName)
                );
            }

            throw new \Exception("Transform TMP->DETAIL failed for {$fileName}: ".$e->getMessage());
        } finally {
            DB::enableQueryLog();
        }
    }

    /**
     * Construit la requête MERGE pour OCC (INSERT avec déduplication).
     *
     * Logique:
     * - SELECT depuis TMP avec transformations (TRIM, UPPER, clean_msisdn, conversions NUMBER/DATE)
     * - Filtrer les lignes invalides (champs obligatoires vides, conversions impossibles)
     * - MERGE INTO DETAIL ON (dedup_key) WHEN NOT MATCHED THEN INSERT
     * - Calculer START_HOUR depuis START_DATE
     *
     * @param string $tmpTable
     * @param string $detailTable
     * @param array $mapping
     * @param array $dedupKeys
     * @param string $fileName
     * @return string SQL MERGE
     */
    private function buildOccMergeQuery(
        string $tmpTable,
        string $detailTable,
        array $mapping,
        array $dedupKeys,
        string $fileName
    ): string {
        $timestampUnit = config('cdr.timestamp_unit', 'seconds');

        // Liste des colonnes DETAIL (sauf START_HOUR qui est calculé)
        $detailColumns = [];
        $selectExpressions = [];

        foreach ($mapping as $csvCol => $config) {
            $detailCol = $config['detail_column'] ?? null;
            if ($detailCol === null) {
                continue; // colonnes de fallback (CALL_REFERENCE, RECORD_ID) ne vont pas dans DETAIL
            }

            $detailColumns[] = $detailCol;

            // Expression SELECT avec transformation
            $selectExpressions[] = $this->buildSelectExpression($csvCol, $config, $timestampUnit);
        }

        // Ajouter START_HOUR calculé depuis START_DATE
        $detailColumns[] = 'START_HOUR';
        $selectExpressions[] = $this->buildStartHourExpression($mapping, $timestampUnit);

        // Ajouter ORIG_START_TIME (colonne VARCHAR dans DETAIL pour traçabilité)
        if (!in_array('ORIG_START_TIME', $detailColumns)) {
            $detailColumns[] = 'ORIG_START_TIME';
            $selectExpressions[] = "TRIM(tmp.ORIG_START_TIME) AS ORIG_START_TIME";
        }

        $detailColList = implode(', ', $detailColumns);
        $selectList = implode(",\n            ", $selectExpressions);

        // Construire la clé de déduplication (COALESCE des dedup_keys)
        $dedupKeyExpr = $this->buildDedupKeyExpression($dedupKeys);

        // Construire la clause WHERE pour filtrer les lignes invalides
        $whereClause = $this->buildWhereClause($mapping, $timestampUnit);

        // Construire le MERGE
        $sql = <<<SQL
MERGE INTO {$detailTable} dest
USING (
    SELECT
        {$dedupKeyExpr} AS DEDUP_KEY,
        {$selectList}
    FROM {$tmpTable} tmp
    WHERE tmp.SOURCE_FILE = '{$fileName}'
        AND {$whereClause}
) src
ON (dest.CHARGING_ID = src.DEDUP_KEY)
WHEN NOT MATCHED THEN
    INSERT ({$detailColList})
    VALUES ({$detailColList})
SQL;

        return $sql;
    }

    /**
     * Construit l'expression SELECT pour une colonne avec transformation.
     */
    private function buildSelectExpression(string $csvCol, array $config, string $timestampUnit): string
    {
        $detailCol = $config['detail_column'];
        $type = $config['type'] ?? 'string';
        $transform = $config['transform'] ?? null;
        $default = $config['default'] ?? null;

        $expr = "tmp.{$csvCol}";

        // Appliquer les transformations
        switch ($transform) {
            case 'trim':
                $expr = "TRIM({$expr})";
                break;
            case 'upper':
                $expr = "UPPER(TRIM({$expr}))";
                break;
            case 'clean_msisdn':
                // Supprimer \r \n \t et espaces
                $expr = "TRIM(REPLACE(REPLACE(REPLACE(REPLACE({$expr}, CHR(13), ''), CHR(10), ''), CHR(9), ''), ' ', ''))";
                break;
        }

        // Conversion de type
        switch ($type) {
            case 'number':
                // Conversion safe: si pas un nombre valide -> NULL
                $expr = "CASE WHEN REGEXP_LIKE({$expr}, '^[+-]?[0-9]+(\\.[0-9]+)?$') THEN TO_NUMBER({$expr}) ELSE NULL END";
                break;
            case 'timestamp':
                // Conversion timestamp epoch -> DATE
                if ($timestampUnit === 'milliseconds') {
                    $expr = "DATE '1970-01-01' + (TO_NUMBER({$expr}) / 1000 / 86400)";
                } else {
                    $expr = "DATE '1970-01-01' + (TO_NUMBER({$expr}) / 86400)";
                }
                break;
            case 'string':
                // Si max_length est défini, appliquer SUBSTR
                if (isset($config['max_length'])) {
                    $maxLen = $config['max_length'];
                    $expr = "SUBSTR({$expr}, 1, {$maxLen})";
                }
                break;
        }

        // Appliquer default si non-required et vide
        if (!($config['required'] ?? true) && $default !== null) {
            if (is_string($default)) {
                $expr = "COALESCE(NULLIF({$expr}, ''), '{$default}')";
            } elseif ($default === null) {
                $expr = "NULLIF({$expr}, '')";
            }
        }

        return "{$expr} AS {$detailCol}";
    }

    /**
     * Construit l'expression pour START_HOUR (extrait depuis START_DATE).
     */
    private function buildStartHourExpression(array $mapping, string $timestampUnit): string
    {
        // START_DATE vient de ORIG_START_TIME
        $origStartTimeExpr = "tmp.ORIG_START_TIME";

        if ($timestampUnit === 'milliseconds') {
            $dateExpr = "DATE '1970-01-01' + (TO_NUMBER({$origStartTimeExpr}) / 1000 / 86400)";
        } else {
            $dateExpr = "DATE '1970-01-01' + (TO_NUMBER({$origStartTimeExpr}) / 86400)";
        }

        return "EXTRACT(HOUR FROM {$dateExpr}) AS START_HOUR";
    }

    /**
     * Construit l'expression de déduplication (COALESCE des dedup_keys).
     */
    private function buildDedupKeyExpression(array $dedupKeys): string
    {
        $expressions = [];
        foreach ($dedupKeys as $key) {
            $expressions[] = "NULLIF(TRIM(tmp.{$key}), '')";
        }
        return "COALESCE(" . implode(', ', $expressions) . ")";
    }

    /**
     * Construit la clause WHERE pour filtrer les lignes invalides.
     *
     * Règles:
     * - Champs obligatoires (required=true) ne doivent pas être vides/NULL
     * - ORIG_START_TIME doit être un nombre valide
     * - DEDUP_KEY (CHARGING_ID ou fallback) ne doit pas être NULL
     */
    private function buildWhereClause(array $mapping, string $timestampUnit): string
    {
        $conditions = [];

        foreach ($mapping as $csvCol => $config) {
            if ($config['required'] ?? false) {
                $conditions[] = "TRIM(tmp.{$csvCol}) IS NOT NULL";
                $conditions[] = "TRIM(tmp.{$csvCol}) != ''";
            }

            // Validation spécifique pour timestamp
            if (($config['type'] ?? null) === 'timestamp') {
                $conditions[] = "REGEXP_LIKE(tmp.{$csvCol}, '^[0-9]+$')";
            }
        }

        // Dedup key ne doit pas être vide
        $dedupKeys = config('cdr.occ_dedup_keys');
        $dedupKeyExpr = $this->buildDedupKeyExpression($dedupKeys);
        $conditions[] = "{$dedupKeyExpr} IS NOT NULL";

        return implode("\n        AND ", $conditions);
    }

    /**
     * Récupère les colonnes d'une table Oracle dynamiquement.
     *
     * @param string $tableName Nom de la table Oracle (ex: 'RA_T_TMP_OCC')
     * @return array Liste des noms de colonnes (uppercase)
     * @throws \Exception Si erreur de connexion ou table inexistante
     */
    public function getTableColumns(string $tableName): array
    {
        try {
            $results = DB::select(
                "SELECT COLUMN_NAME FROM USER_TAB_COLUMNS WHERE TABLE_NAME = :table_name ORDER BY COLUMN_ID",
                ['table_name' => strtoupper($tableName)]
            );

            return array_map(fn($row) => $row->column_name, $results);
        } catch (\Exception $e) {
            Log::channel('cdr')->error("getTableColumns failed for {$tableName}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Récupère le whitelist des colonnes TMP avec cache (24h).
     *
     * @param string $sourceType 'occ' ou 'mmg'
     * @return array Liste des colonnes autorisées pour TMP
     */
    public function getTmpColumnsWhitelist(string $sourceType): array
    {
        $cacheKey = "cdr.tmp_columns.{$sourceType}";
        $cacheSeconds = 86400; // 24 heures

        return cache()->remember($cacheKey, $cacheSeconds, function () use ($sourceType) {
            $tmpTable = config("cdr.tables.{$sourceType}.tmp");

            if (!$tmpTable) {
                Log::channel('cdr')->warning("getTmpColumnsWhitelist: No TMP table configured for {$sourceType}");
                return [];
            }

            try {
                $columns = $this->getTableColumns($tmpTable);
                Log::channel('cdr')->info("getTmpColumnsWhitelist: Cached " . count($columns) . " columns for {$tmpTable}");
                return $columns;
            } catch (\Exception $e) {
                Log::channel('cdr')->error("getTmpColumnsWhitelist: Failed to fetch columns for {$tmpTable}, falling back to all-accept mode");
                return []; // array vide = accepter tout (mode permissif)
            }
        });
    }

    /**
     * Valide que toutes les colonnes du CSV sont dans la whitelist.
     *
     * @param array $headerCols Colonnes du header CSV
     * @param string $sourceType 'occ' ou 'mmg'
     * @param string $mode 'tmp' (TMP stage - colonnes Oracle) ou 'detail' (DETAIL stage - config mapping)
     * @return array ['valid' => bool, 'unknown_columns' => array]
     */
    public function validateColumns(array $headerCols, string $sourceType, string $mode = 'tmp'): array
    {
        // Colonnes techniques (ajoutées automatiquement par le système)
        $technicalCols = ['SOURCE_FILE', 'SOURCE_DIR', 'LOAD_TS'];

        // Déterminer la whitelist selon le mode
        if ($mode === 'tmp') {
            // Mode TMP: utiliser les colonnes Oracle (dynamic)
            $allowedCols = $this->getTmpColumnsWhitelist($sourceType);

            // Mode permissif: accepter كل الأعمدة إذا whitelist فارغة
            if ($allowedCols === null || empty($allowedCols)) {
                Log::channel('cdr')->warning("validateColumns: TMP mode permissive (no Oracle columns available or empty whitelist)");
                return ['valid' => true, 'unknown_columns' => []];
            }
        } else {
            // Mode DETAIL: utiliser le mapping config (ancien comportement)
            $mapping = config("cdr.{$sourceType}_mapping", []);
            $allowedCols = array_keys($mapping);
        }

        $unknownCols = [];
        foreach ($headerCols as $col) {
            if (!in_array($col, $allowedCols) && !in_array($col, $technicalCols)) {
                $unknownCols[] = $col;
            }
        }

        return [
            'valid' => empty($unknownCols),
            'unknown_columns' => $unknownCols,
        ];
    }

    /**
     * Transforme MMG (à implémenter plus tard).
     */
    public function transformMmgTmpToDetail(string $fileName): array
    {
        throw new \Exception('MMG transformation not implemented yet');
    }
}
