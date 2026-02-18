<?php

namespace Tests\Unit;

use App\Services\CdrTransformService;
use Tests\TestCase;

/**
 * Tests pour le service de transformation CDR.
 *
 * Ces tests vérifient:
 * - La validation des colonnes contre la whitelist
 * - La configuration du timestamp unit
 * - Les règles de mapping
 */
class CdrTransformServiceTest extends TestCase
{
    protected CdrTransformService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CdrTransformService();
    }

    /**
     * Test: colonnes valides OCC doivent passer la validation.
     */
    public function test_occ_valid_columns_pass_validation(): void
    {
        $validHeaders = [
            'DATASOURCE',
            'A_MSISDN',
            'B_MSISDN',
            'ORIG_START_TIME',
            'APN',
            'CALL_TYPE',
            'EVENT_TYPE',
            'CHARGING_ID',
            'SERVICE_ID',
            'SUBSCRIBER_TYPE',
            'ROAMING_TYPE',
            'PARTNER',
            'FILTER_CODE',
            'FLEX_FLD1',
            'FLEX_FLD2',
            'FLEX_FLD3',
            'EVENT_COUNT',
            'DATA_VOLUME',
            'CHARGE_AMOUNT',
        ];

        $result = $this->service->validateColumns($validHeaders, 'occ');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['unknown_columns']);
    }

    /**
     * Test: colonnes inconnues doivent être rejetées.
     */
    public function test_occ_unknown_columns_fail_validation(): void
    {
        $invalidHeaders = [
            'DATASOURCE',
            'A_MSISDN',
            'UNKNOWN_FIELD_1',
            'INVALID_COL',
        ];

        $result = $this->service->validateColumns($invalidHeaders, 'occ');

        $this->assertFalse($result['valid']);
        $this->assertCount(2, $result['unknown_columns']);
        $this->assertContains('UNKNOWN_FIELD_1', $result['unknown_columns']);
        $this->assertContains('INVALID_COL', $result['unknown_columns']);
    }

    /**
     * Test: colonnes techniques (SOURCE_FILE, SOURCE_DIR, LOAD_TS) doivent être autorisées.
     */
    public function test_technical_columns_are_allowed(): void
    {
        $headersWithTechnical = [
            'DATASOURCE',
            'A_MSISDN',
            'SOURCE_FILE',
            'SOURCE_DIR',
            'LOAD_TS',
        ];

        $result = $this->service->validateColumns($headersWithTechnical, 'occ');

        $this->assertTrue($result['valid']);
    }

    /**
     * Test: configuration timestamp_unit par défaut.
     */
    public function test_timestamp_unit_default_is_seconds(): void
    {
        $unit = config('cdr.timestamp_unit');

        $this->assertEquals('seconds', $unit);
    }

    /**
     * Test: mapping OCC contient toutes les colonnes obligatoires.
     */
    public function test_occ_mapping_has_required_fields(): void
    {
        $mapping = config('cdr.occ_mapping');

        $requiredFields = [
            'DATASOURCE',
            'A_MSISDN',
            'ORIG_START_TIME',
            'APN',
            'CALL_TYPE',
            'EVENT_TYPE',
            'CHARGING_ID',
            'SERVICE_ID',
            'SUBSCRIBER_TYPE',
            'ROAMING_TYPE',
            'PARTNER',
            'FILTER_CODE',
            'FLEX_FLD1',
            'FLEX_FLD2',
            'FLEX_FLD3',
        ];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $mapping, "Required field {$field} missing in mapping");
            $this->assertTrue($mapping[$field]['required'], "Field {$field} should be required");
        }
    }

    /**
     * Test: clés de déduplication OCC sont définies.
     */
    public function test_occ_dedup_keys_are_configured(): void
    {
        $dedupKeys = config('cdr.occ_dedup_keys');

        $this->assertIsArray($dedupKeys);
        $this->assertNotEmpty($dedupKeys);
        $this->assertContains('CHARGING_ID', $dedupKeys);
    }

    /**
     * Test: tables TMP et DETAIL sont définies.
     */
    public function test_table_names_are_configured(): void
    {
        $tables = config('cdr.tables');

        $this->assertArrayHasKey('occ', $tables);
        $this->assertEquals('RA_T_TMP_OCC', $tables['occ']['tmp']);
        $this->assertEquals('RA_T_OCC_CDR_DETAIL', $tables['occ']['detail']);
    }

    /**
     * Test: batch_size est configurable.
     */
    public function test_batch_size_is_configurable(): void
    {
        $batchSize = config('cdr.batch_size');

        $this->assertIsInt($batchSize);
        $this->assertGreaterThan(0, $batchSize);
    }

    /**
     * Test: stratégie de nettoyage TMP est définie.
     */
    public function test_tmp_cleanup_strategy_is_configured(): void
    {
        $strategy = config('cdr.tmp_cleanup_strategy');

        $this->assertContains($strategy, ['on_success', 'on_error', 'never']);
    }

    /**
     * Test: transformation MMG lance une exception (non implémentée).
     */
    public function test_mmg_transformation_throws_not_implemented(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('MMG transformation not implemented yet');

        $this->service->transformMmgTmpToDetail('test_file.csv');
    }
}
