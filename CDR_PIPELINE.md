# Pipeline CDR TMP → DETAIL

## Vue d'ensemble

Ce document décrit le pipeline complet de traitement des fichiers CDR (Call Detail Records) depuis le téléchargement FTP jusqu'à l'insertion dans les tables DETAIL typées.

## Architecture

```
FTP Server → Download → Validate CSV → Load TMP (RAW) → Transform → DETAIL (Typed) → Archive
                ↓                          ↓                ↓             ↓
              ERR (invalid)            TMP Tables      MERGE + Dedup    OUT/
```

## Étapes du Pipeline

### 1. Download & Validation
- Téléchargement depuis FTP (ou upload local pour tests)
- Validation stricte du CSV:
  - Header count constant
  - Quotes équilibrées (newline = record delimiter)
  - Colonnes contre whitelist (config/cdr.php)
- Si validation échoue → fichier en ERR/ + audit ERROR

### 2. Load TMP (RAW)
- Insertion dans tables staging VARCHAR2(255):
  - `RA_T_TMP_OCC`
  - `RA_T_TMP_MMG`
- Colonnes techniques ajoutées: SOURCE_FILE, SOURCE_DIR, LOAD_TS
- Optimisé avec INSERT ALL + bind variables (voir `CdrRun::loadCsvToOracle`)

### 3. Transform TMP → DETAIL
- Service: `App\Services\CdrTransformService`
- Transformations appliquées:
  - **Nettoyage**: TRIM, suppression \r\n\t (clean_msisdn)
  - **Casse**: UPPER sur CALL_TYPE, EVENT_TYPE
  - **Types**: VARCHAR → NUMBER, DATE (conversions safe)
  - **Timestamp**: epoch seconds/milliseconds → DATE Oracle
  - **Déduplication**: MERGE sur CHARGING_ID (fallback CALL_REFERENCE, RECORD_ID)
- Filtrage:
  - Champs obligatoires vides → ligne rejetée (non insérée)
  - Conversions impossibles → NULL ou rejet
- Résultat: stats [inserted, rejected, tmpRows]

### 4. Vérification & Cleanup
- Vérification cohérence (rows TMP vs DETAIL estimé)
- Update LOAD_AUDIT avec stats détaillées
- Nettoyage TMP selon stratégie (config: `cdr.tmp_cleanup_strategy`)
- Déplacement fichier OUT/ ou ERR/

## Configuration

### config/cdr.php

```php
'timestamp_unit' => 'seconds|milliseconds', // ENV: CDR_TIMESTAMP_UNIT
'batch_size' => 500,                        // ENV: CDR_BATCH_SIZE
'tmp_cleanup_strategy' => 'on_success',     // ENV: CDR_TMP_CLEANUP
'tmp_whitelist_mode' => 'dynamic',          // ENV: CDR_TMP_WHITELIST_MODE
```

### Whitelist TMP (Validation CSV Entrants)

**Mode: DYNAMIC (recommandé)**
- Fetch automatique des colonnes depuis Oracle (`USER_TAB_COLUMNS`)
- Cache 24h pour performance
- Accepte **toutes** les colonnes de `RA_T_TMP_OCC` (45+ colonnes)
- Exemples: `AGGREGATION_GROUP`, `A_IMSI`, `A_MSISDN_ORIG`, `TRUNK_IN`, etc.

**Commande de refresh:**
```bash
php artisan cdr:cache-columns          # Refresh cache
php artisan cdr:cache-columns --show   # Voir colonnes cachées
```

**⚠️ Architecture correcte:**
- **TMP Stage**: Whitelist = colonnes Oracle (dynamic) → accepte tout le CSV brut
- **DETAIL Stage**: Whitelist = config mapping → transforme les colonnes essentielles

Voir [TMP_WHITELIST.md](TMP_WHITELIST.md) pour plus de détails.

### Whitelist DETAIL (Transformation TMP → DETAIL)

Colonnes transformées définies dans `config/cdr.php::occ_mapping`.

**Colonnes obligatoires (required=true):**
- DATASOURCE, A_MSISDN, ORIG_START_TIME, APN
- CALL_TYPE, EVENT_TYPE, CHARGING_ID, SERVICE_ID
- SUBSCRIBER_TYPE, ROAMING_TYPE, PARTNER, FILTER_CODE
- FLEX_FLD1, FLEX_FLD2, FLEX_FLD3

**Colonnes optionnelles:**
- B_MSISDN, EVENT_COUNT, DATA_VOLUME, EVENT_DURATION
- CHARGE_AMOUNT, KEYWORD, DA_AMOUNT_CALC, MA_AMNT_CALC

**Colonnes de fallback (dédup):**
- CALL_REFERENCE, RECORD_ID

### Timestamp Conversion

| timestamp_unit  | Formule Oracle                                |
|-----------------|-----------------------------------------------|
| `seconds`       | `DATE '1970-01-01' + (ts / 86400)`           |
| `milliseconds`  | `DATE '1970-01-01' + (ts / 1000 / 86400)`    |

Exemple:
- 1609459200 (seconds) → 2021-01-01 00:00:00
- 1609459200000 (milliseconds) → 2021-01-01 00:00:00

### Déduplication

Priorité (COALESCE):
1. CHARGING_ID (prioritaire)
2. CALL_REFERENCE (fallback si CHARGING_ID vide)
3. RECORD_ID (fallback si les 2 précédents vides)

Si tous vides → ligne rejetée.

## Commandes Artisan

### Production (FTP)
```bash
php artisan cdr:run
```
- Télécharge depuis FTP (`/home/MMG`, `/home/OCC`)
- Traite TMP → DETAIL automatiquement
- Supprime fichiers FTP si SUCCESS (configurable)

### Tests (Local)
```bash
php artisan cdr:run-local \
  --mmg-path="/mnt/c/Users/Ahmed/Desktop/CDR MMG" \
  --occ-path="/mnt/c/Users/Ahmed/Desktop/CDR OCC"
```
- Upload depuis répertoires locaux
- Même pipeline TMP → DETAIL
- Fichiers locaux conservés

## Scheduler Laravel

Dans `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('cdr:run')
        ->everyTenMinutes()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/cdr-schedule.log'));
}
```

## Logs

### Channel CDR
- Fichier: `storage/logs/cdr-YYYY-MM-DD.log`
- Rotation: 14 jours (configurable dans `config/logging.php`)
- Contenu:
  - Transformation stats par fichier
  - Erreurs de validation/conversion
  - Timing et performance

### Exemples de logs
```
[2026-02-18 10:15:32] cdr.INFO: transformOccTmpToDetail: Processing 125000 rows for file CDR_OCC_20260218.csv
[2026-02-18 10:16:45] cdr.INFO: Transform SUCCESS for CDR_OCC_20260218.csv {"inserted":124850,"rejected":150,"tmpRows":125000}
[2026-02-18 10:16:45] cdr.INFO: transformOccTmpToDetail: Cleaned TMP for file CDR_OCC_20260218.csv
```

## Audit Base de Données

Table `LOAD_AUDIT` mise à jour avec:

```sql
STATUS: 'SUCCESS' | 'ERROR'
ROWS_CSV: nombre de lignes dans le CSV
ROWS_DB: nombre de lignes dans TMP
MESSAGE: 'TMP:125000 DETAIL:124850 REJECTED:150' | 'TRANSFORM_ERROR: ...'
```

Requêtes utiles:

```sql
-- Derniers traitements
SELECT * FROM LOAD_AUDIT ORDER BY LOAD_TS DESC FETCH FIRST 20 ROWS ONLY;

-- Taux de rejet par fichier
SELECT 
    FILE_NAME,
    ROWS_CSV,
    REGEXP_SUBSTR(MESSAGE, 'DETAIL:([0-9]+)', 1, 1, NULL, 1) as DETAIL_ROWS,
    REGEXP_SUBSTR(MESSAGE, 'REJECTED:([0-9]+)', 1, 1, NULL, 1) as REJECTED_ROWS
FROM LOAD_AUDIT
WHERE STATUS = 'SUCCESS'
ORDER BY LOAD_TS DESC;

-- Erreurs récentes
SELECT FILE_NAME, MESSAGE FROM LOAD_AUDIT 
WHERE STATUS = 'ERROR' 
ORDER BY LOAD_TS DESC;
```

## Prérequis Base de Données

### 1. Créer la table DETAIL
```bash
sqlplus user/pass@db @database/migrations/create_occ_detail_table.sql
```

### 2. Vérifier l'index unique
```sql
SELECT index_name, uniqueness 
FROM user_indexes 
WHERE table_name = 'RA_T_OCC_CDR_DETAIL';
```

Doit contenir: `UK_OCC_CHARGING_ID` (UNIQUE)

### 3. Tester une transformation
```sql
-- Insérer une ligne test dans TMP
INSERT INTO RA_T_TMP_OCC (
    DATASOURCE, A_MSISDN, ORIG_START_TIME, APN, CALL_TYPE,
    EVENT_TYPE, CHARGING_ID, SERVICE_ID, SUBSCRIBER_TYPE,
    ROAMING_TYPE, PARTNER, FILTER_CODE,
    FLEX_FLD1, FLEX_FLD2, FLEX_FLD3,
    SOURCE_FILE, SOURCE_DIR, LOAD_TS
) VALUES (
    'TEST', '21612345678', '1609459200', 'internet', 'DATA',
    'PDP_CONTEXT', 'CHG123456', 'SRV001', 'PREPAID',
    'LOCAL', 'PARTNER_A', 'FLT01',
    'FLD1', 'FLD2', 'FLD3',
    'test.csv', 'OCC', SYSDATE
);
COMMIT;
```

Puis exécuter:
```bash
php artisan cdr:run-local --occ-path="/path/to/empty/dir"
```

## Tests Unitaires

```bash
# Exécuter les tests CDR
php artisan test tests/Unit/CdrTransformServiceTest.php

# Avec verbosité
php artisan test tests/Unit/CdrTransformServiceTest.php --testdox
```

Tests couverts:
- ✓ Validation whitelist (colonnes valides/invalides)
- ✓ Configuration timestamp_unit
- ✓ Mapping colonnes obligatoires
- ✓ Clés de déduplication
- ✓ Stratégie nettoyage TMP

## Performance

### Optimisations Implémentées

1. **Load TMP (loadCsvToOracle)**
   - INSERT ALL avec bind variables nommées
   - SQL préparé une seule fois, réutilisé
   - SYSDATE côté Oracle (pas de Carbon PHP)
   - Query log désactivé
   - Batch 500 lignes (configurable)

2. **Transform DETAIL (MERGE)**
   - Single statement (pas de boucle PHP)
   - Filtrage côté Oracle (WHERE clause)
   - Conversions inline (TO_NUMBER, DATE arithmetic)
   - Déduplication native (MERGE ON)

### Timing Attendu

| Fichier         | Lignes  | TMP Load | Transform | Total  |
|-----------------|---------|----------|-----------|--------|
| OCC Small       | 10K     | ~2s      | ~1s       | ~3s    |
| OCC Medium      | 100K    | ~15s     | ~8s       | ~23s   |
| OCC Large       | 1M      | ~2min    | ~1min     | ~3min  |

*Sur machine de développement (Docker WSL2, Oracle XE 21c)*

## Troubleshooting

### Problème: WHITELIST_ERROR
```
ERROR: filename.csv => ERR/OCC | WHITELIST_ERROR: Unknown columns: COL_X, COL_Y
```

**Solution:** Ajouter les colonnes manquantes dans `config/cdr.php::occ_mapping` ou corriger le fichier CSV.

### Problème: TRANSFORM_ERROR
```
ERROR: filename.csv => ERR/OCC | TRANSFORM_ERROR: ORA-00001: unique constraint violated
```

**Cause:** Doublon dans CHARGING_ID (fichier déjà traité partiellement).

**Solution:**
```sql
DELETE FROM RA_T_OCC_CDR_DETAIL WHERE CHARGING_ID IN (
    SELECT CHARGING_ID FROM RA_T_TMP_OCC WHERE SOURCE_FILE = 'filename.csv'
);
```

### Problème: Timestamp invalide
```
REJECTED rows > 50%
```

**Cause:** Mauvaise unité de timestamp (seconds vs milliseconds).

**Solution:** Vérifier/ajuster `CDR_TIMESTAMP_UNIT` dans `.env`:
```bash
CDR_TIMESTAMP_UNIT=milliseconds  # si timestamps > 10^12
```

### Problème: Performance lente
```
Transform > 5 minutes pour 100K lignes
```

**Actions:**
1. Vérifier l'index unique: `SELECT * FROM user_indexes WHERE table_name = 'RA_T_OCC_CDR_DETAIL'`
2. Augmenter batch_size: `CDR_BATCH_SIZE=1000`
3. Vérifier query log désactivé (devrait l'être automatiquement)
4. Analyser table: `EXEC DBMS_STATS.GATHER_TABLE_STATS(user, 'RA_T_OCC_CDR_DETAIL')`

## Évolutions Futures

### À implémenter pour MMG
1. Définir structure table `RA_T_MMG_CDR_DETAIL`
2. Ajouter mapping dans `config/cdr.php::mmg_mapping`
3. Implémenter `CdrTransformService::transformMmgTmpToDetail()`
4. Créer script SQL `create_mmg_detail_table.sql`

### Améliorations possibles
- Ajouter colonne `SOURCE_FILE` dans DETAIL pour traçabilité exacte
- Implémenter retry automatique sur erreurs transientes
- Ajouter métriques Prometheus/Grafana
- Paralléliser traitement multi-fichiers
- Implémenter archivage DETAIL vers datawarehouse

## Support

Pour toute question ou problème:
1. Consulter les logs: `storage/logs/cdr-*.log`
2. Vérifier LOAD_AUDIT: `SELECT * FROM LOAD_AUDIT WHERE STATUS='ERROR' ORDER BY LOAD_TS DESC`
3. Exécuter tests: `php artisan test tests/Unit/CdrTransformServiceTest.php`
