# CDR Pipeline - Quick Start Guide

## Installation et Configuration

### 1. Créer la table DETAIL dans Oracle

```bash
# Connectez-vous à Oracle
sqlplus RAPRD/password@//oracle:1521/XE

# Exécutez le script de création
@database/migrations/create_occ_detail_table.sql

# Vérifiez la création
SELECT table_name FROM user_tables WHERE table_name LIKE '%OCC%';
```

### 2. Configurer les variables d'environnement

Ajoutez ces lignes dans votre fichier `.env`:

```dotenv
# Unité du timestamp (seconds ou milliseconds)
CDR_TIMESTAMP_UNIT=seconds

# Taille des lots pour insertion (recommandé: 500-1000)
CDR_BATCH_SIZE=500

# Stratégie de nettoyage TMP (on_success recommandé)
CDR_TMP_CLEANUP=on_success

# Configuration CSV
CSV_DELIMITER=","
CSV_ENCLOSURE="\""

# Configuration FTP (pour production)
FTP_DIR_MMG=/home/MMG
FTP_DIR_OCC=/home/OCC
FTP_DELETE_AFTER_SUCCESS=true
```

### 3. Tester avec un fichier local

```bash
# Placez vos fichiers CSV de test dans:
# - C:\Users\Ahmed Amin Bejoui\Desktop\CDR MMG\
# - C:\Users\Ahmed Amin Bejoui\Desktop\CDR OCC\

# Lancez le traitement local
./upload-local-cdr.sh

# OU avec chemins personnalisés
php artisan cdr:run-local \
  --mmg-path="/chemin/vers/mmg" \
  --occ-path="/chemin/vers/occ"
```

### 4. Vérifier les résultats

```sql
-- Vérifier l'audit
SELECT * FROM LOAD_AUDIT ORDER BY LOAD_TS DESC;

-- Vérifier les données dans DETAIL
SELECT COUNT(*) FROM RA_T_OCC_CDR_DETAIL;

-- Voir un échantillon
SELECT * FROM RA_T_OCC_CDR_DETAIL FETCH FIRST 10 ROWS ONLY;

-- Vérifier le taux de rejet
SELECT 
    FILE_NAME,
    REGEXP_SUBSTR(MESSAGE, 'DETAIL:([0-9]+)', 1, 1, NULL, 1) as INSERTED,
    REGEXP_SUBSTR(MESSAGE, 'REJECTED:([0-9]+)', 1, 1, NULL, 1) as REJECTED
FROM LOAD_AUDIT
WHERE STATUS = 'SUCCESS'
ORDER BY LOAD_TS DESC;
```

### 5. Consulter les logs

```bash
# Log CDR détaillé
cat storage/logs/cdr-$(date +%Y-%m-%d).log

# Dernières lignes du log
tail -f storage/logs/cdr-$(date +%Y-%m-%d).log
```

## Exemple de Fichier CSV OCC Valide

Créez un fichier `test_occ.csv` avec ce contenu:

```csv
DATASOURCE,A_MSISDN,B_MSISDN,ORIG_START_TIME,APN,CALL_TYPE,EVENT_TYPE,CHARGING_ID,SERVICE_ID,SUBSCRIBER_TYPE,ROAMING_TYPE,PARTNER,FILTER_CODE,FLEX_FLD1,FLEX_FLD2,FLEX_FLD3,EVENT_COUNT,DATA_VOLUME,CHARGE_AMOUNT
SOURCE1,21612345678,21698765432,1609459200,internet,DATA,PDP_CONTEXT,CHG001,SRV001,PREPAID,LOCAL,PARTNER_A,FLT01,VAL1,VAL2,VAL3,1,1024.5,10.50
SOURCE1,21612345679,21698765433,1609459260,internet,DATA,PDP_CONTEXT,CHG002,SRV001,POSTPAID,LOCAL,PARTNER_A,FLT01,VAL1,VAL2,VAL3,1,2048.75,20.75
```

**Points importants:**
- Toutes les colonnes obligatoires présentes
- ORIG_START_TIME en epoch seconds (1609459200 = 2021-01-01 00:00:00)
- CHARGING_ID unique par ligne
- Pas de retour à la ligne dans les champs

## Scheduler pour Production

Ajoutez dans `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Traiter les fichiers toutes les 10 minutes
    $schedule->command('cdr:run')
        ->everyTenMinutes()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/cdr-schedule.log'));
}
```

Puis activez le cron Laravel:

```bash
# Ouvrir crontab
crontab -e

# Ajouter cette ligne
* * * * * cd /path/to/smsplus-api && php artisan schedule:run >> /dev/null 2>&1
```

## Exécuter les Tests

```bash
# Tous les tests CDR
php artisan test tests/Unit/CdrTransformServiceTest.php

# Avec détails
php artisan test tests/Unit/CdrTransformServiceTest.php --testdox

# Test spécifique
php artisan test --filter test_occ_valid_columns_pass_validation
```

## Troubleshooting Rapide

### Erreur: "WHITELIST_ERROR: Unknown columns"

**Cause:** Une colonne du CSV n'est pas dans la whitelist.

**Solution:** Ajoutez la colonne manquante dans `config/cdr.php` section `occ_mapping`.

### Erreur: "ORA-00001: unique constraint violated"

**Cause:** CHARGING_ID déjà présent (doublon).

**Solution:** Le MERGE devrait gérer ça automatiquement. Vérifiez que l'index unique existe:
```sql
SELECT index_name FROM user_indexes WHERE table_name = 'RA_T_OCC_CDR_DETAIL';
```

### Beaucoup de lignes rejetées (REJECTED > 20%)

**Causes possibles:**
1. Mauvaise unité de timestamp → Vérifiez `CDR_TIMESTAMP_UNIT`
2. Champs obligatoires vides → Nettoyez les données source
3. Conversions NUMBER échouent → Vérifiez le format des colonnes numériques

**Diagnostic:**
```sql
-- Voir quelles lignes du TMP seraient rejetées
SELECT * FROM RA_T_TMP_OCC 
WHERE SOURCE_FILE = 'votre_fichier.csv'
  AND (
    TRIM(DATASOURCE) IS NULL OR TRIM(DATASOURCE) = '' OR
    TRIM(A_MSISDN) IS NULL OR TRIM(A_MSISDN) = '' OR
    NOT REGEXP_LIKE(ORIG_START_TIME, '^[0-9]+$')
  );
```

### Performance lente

**Actions immédiates:**
1. Vérifier les index:
   ```sql
   SELECT index_name, status FROM user_indexes WHERE table_name = 'RA_T_OCC_CDR_DETAIL';
   ```

2. Analyser les stats:
   ```sql
   EXEC DBMS_STATS.GATHER_TABLE_STATS(user, 'RA_T_OCC_CDR_DETAIL');
   ```

3. Augmenter batch_size dans `.env`:
   ```
   CDR_BATCH_SIZE=1000
   ```

## Support

Documentation complète: `CDR_PIPELINE.md`

Structure projet:
```
app/
  Console/Commands/
    CdrRun.php          # Commande FTP production
    CdrRunLocal.php     # Commande test local
  Services/
    CdrTransformService.php  # Logique TMP→DETAIL
config/
  cdr.php              # Whitelist + configuration
  logging.php          # Channel 'cdr' ajouté
tests/
  Unit/
    CdrTransformServiceTest.php  # Tests unitaires
database/
  migrations/
    create_occ_detail_table.sql  # Script DDL Oracle
```
