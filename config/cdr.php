<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CDR Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration pour le traitement des fichiers CDR (Call Detail Records).
    | Définit les mappings de colonnes, règles de validation, et paramètres
    | de transformation TMP -> DETAIL.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Timestamp Unit
    |--------------------------------------------------------------------------
    |
    | Unité du timestamp dans ORIG_START_TIME:
    | - 'seconds': timestamp epoch en secondes (ex: 1609459200)
    | - 'milliseconds': timestamp epoch en millisecondes (ex: 1609459200000)
    |
    */
    'timestamp_unit' => env('CDR_TIMESTAMP_UNIT', 'seconds'),

    /*
    |--------------------------------------------------------------------------
    | Batch Size for Inserts
    |--------------------------------------------------------------------------
    |
    | Taille des lots pour les insertions en masse (déjà utilisé dans loadCsvToOracle)
    |
    */
    'batch_size' => (int) env('CDR_BATCH_SIZE', 2000),

    /*
    |--------------------------------------------------------------------------
    | TMP Cleanup Strategy
    |--------------------------------------------------------------------------
    |
    | Stratégie de nettoyage de la table TMP après traitement:
    | - 'on_success': Supprimer uniquement si transformation réussie
    | - 'on_error': Supprimer aussi en cas d'erreur (pour ne pas bloquer)
    | - 'never': Ne jamais supprimer automatiquement (nettoyage manuel)
    |
    */
    'tmp_cleanup_strategy' => env('CDR_TMP_CLEANUP', 'on_success'),

    /*
    |--------------------------------------------------------------------------
    | TMP Whitelist Mode
    |--------------------------------------------------------------------------
    |
    | Mode de validation des colonnes pour les fichiers CSV entrants:
    | - 'dynamic': Récupère les colonnes depuis Oracle (USER_TAB_COLUMNS) avec cache 24h
    | - 'permissive': Accepte toutes les colonnes (pas de validation)
    | - 'strict': Utilise le mapping config (ancien comportement - non recommandé pour TMP)
    |
    | IMPORTANT: En mode 'dynamic', les colonnes sont fetchées depuis la table TMP Oracle.
    | Si Oracle est inaccessible, le système passe automatiquement en mode permissive.
    |
    | RECOMMANDÉ: 'dynamic' (accepte tous les champs de RA_T_TMP_OCC automatiquement)
    |
    */
    'tmp_whitelist_mode' => env('CDR_TMP_WHITELIST_MODE', 'dynamic'),

    /*
    |--------------------------------------------------------------------------
    | OCC Column Mapping (Whitelist)
    |--------------------------------------------------------------------------
    |
    | Mapping entre les colonnes CSV (header) et les colonnes de la table DETAIL.
    | Seules les colonnes listées ici sont autorisées.
    | Si une colonne dans le CSV n'est pas dans cette liste -> fichier en ERR.
    |
    | Format: 'nom_colonne_csv' => [
    |     'detail_column' => 'NOM_COLONNE_DETAIL',
    |     'required' => true/false,
    |     'type' => 'string'|'number'|'date'|'timestamp',
    |     'transform' => 'trim'|'upper'|'clean_msisdn'|null,
    |     'default' => valeur par défaut si vide (seulement si required=false)
    | ]
    |
    */
    'occ_mapping' => [
        // Colonnes obligatoires
        'DATASOURCE' => [
            'detail_column' => 'DATASOURCE',
            'required' => true,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 20,
        ],
        'A_MSISDN' => [
            'detail_column' => 'A_MSISDN',
            'required' => true,
            'type' => 'string',
            'transform' => 'clean_msisdn',
            'max_length' => 200,
        ],
        'B_MSISDN' => [
            'detail_column' => 'B_MSISDN',
            'required' => false,
            'type' => 'string',
            'transform' => 'clean_msisdn',
            'max_length' => 200,
            'default' => null,
        ],
        'ORIG_START_TIME' => [
            'detail_column' => 'START_DATE',
            'required' => true,
            'type' => 'timestamp', // convertit en DATE Oracle
            'transform' => 'trim',
        ],
        'APN' => [
            'detail_column' => 'APN',
            'required' => true,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 50,
        ],
        'CALL_TYPE' => [
            'detail_column' => 'CALL_TYPE',
            'required' => true,
            'type' => 'string',
            'transform' => 'upper',
            'max_length' => 20,
        ],
        'EVENT_TYPE' => [
            'detail_column' => 'EVENT_TYPE',
            'required' => true,
            'type' => 'string',
            'transform' => 'upper',
            'max_length' => 20,
        ],
        'CHARGING_ID' => [
            'detail_column' => 'CHARGING_ID',
            'required' => true,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 40,
        ],
        'SERVICE_ID' => [
            'detail_column' => 'SERVICE_ID',
            'required' => true,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 40,
        ],
        'SUBSCRIBER_TYPE' => [
            'detail_column' => 'SUBSCRIBER_TYPE',
            'required' => true,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 30,
        ],
        'ROAMING_TYPE' => [
            'detail_column' => 'ROAMING_TYPE',
            'required' => true,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 10,
        ],
        'PARTNER' => [
            'detail_column' => 'PARTNER',
            'required' => true,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 20,
        ],
        'FILTER_CODE' => [
            'detail_column' => 'FILTER_CODE',
            'required' => true,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 20,
        ],
        'FLEX_FLD1' => [
            'detail_column' => 'FLEX_FLD1',
            'required' => true,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 100,
        ],
        'FLEX_FLD2' => [
            'detail_column' => 'FLEX_FLD2',
            'required' => true,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 100,
        ],
        'FLEX_FLD3' => [
            'detail_column' => 'FLEX_FLD3',
            'required' => true,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 100,
        ],

        // Colonnes optionnelles (number)
        'EVENT_COUNT' => [
            'detail_column' => 'EVENT_COUNT',
            'required' => false,
            'type' => 'number',
            'default' => null,
        ],
        'DATA_VOLUME' => [
            'detail_column' => 'DATA_VOLUME',
            'required' => false,
            'type' => 'number',
            'default' => null,
        ],
        'EVENT_DURATION' => [
            'detail_column' => 'EVENT_DURATION',
            'required' => false,
            'type' => 'number',
            'default' => null,
        ],
        'CHARGE_AMOUNT' => [
            'detail_column' => 'CHARGE_AMOUNT',
            'required' => false,
            'type' => 'number',
            'default' => null,
        ],
        'DA_AMOUNT_CALC' => [
            'detail_column' => 'DA_AMOUNT_CALC',
            'required' => false,
            'type' => 'number',
            'default' => null,
        ],
        'MA_AMNT_CALC' => [
            'detail_column' => 'MA_AMNT_CALC',
            'required' => false,
            'type' => 'number',
            'default' => null,
        ],

        // Colonnes optionnelles (string)
        'KEYWORD' => [
            'detail_column' => 'KEYWORD',
            'required' => false,
            'type' => 'string',
            'transform' => 'trim',
            'max_length' => 100,
            'default' => '_N',
        ],

        // Colonnes de fallback pour déduplication
        'CALL_REFERENCE' => [
            'detail_column' => null, // pas dans DETAIL mais utilisé pour dédup
            'required' => false,
            'type' => 'string',
            'transform' => 'trim',
        ],
        'RECORD_ID' => [
            'detail_column' => null, // pas dans DETAIL mais utilisé pour dédup
            'required' => false,
            'type' => 'string',
            'transform' => 'trim',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MMG Column Mapping (Whitelist)
    |--------------------------------------------------------------------------
    |
    | À implémenter plus tard selon la structure de RA_T_MMG_CDR_DETAIL
    |
    */
    'mmg_mapping' => [
        // TODO: définir le mapping MMG quand la table DETAIL sera créée
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication Keys
    |--------------------------------------------------------------------------
    |
    | Définit les colonnes utilisées pour la déduplication (par priorité).
    | Le premier champ non-vide sera utilisé comme clé unique.
    |
    */
    'occ_dedup_keys' => ['CHARGING_ID', 'CALL_REFERENCE', 'RECORD_ID'],
    'mmg_dedup_keys' => [], // TODO: à définir

    /*
    |--------------------------------------------------------------------------
    | Detail Tables
    |--------------------------------------------------------------------------
    |
    | Noms des tables DETAIL par type de CDR
    |
    */
    'tables' => [
        'occ' => [
            'tmp' => 'RA_T_TMP_OCC',
            'detail' => 'RA_T_OCC_CDR_DETAIL',
        ],
        'mmg' => [
            'tmp' => 'RA_T_TMP_MMG',
            'detail' => 'RA_T_MMG_CDR_DETAIL', // TODO: créer cette table
        ],
    ],
];
