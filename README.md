# SMSPlus API

Projet Laravel 12 **100 % CLI** connecte a Oracle XE 21 avec Docker Sail.
Le coeur du projet est un pipeline CDR (CSV → Oracle) qui :

1. Telecharge des fichiers CSV via FTP (ou depuis un dossier local)
2. Valide leur structure (nombre de colonnes, quotes, whitelist dynamique)
3. Charge les donnees dans des tables Oracle de staging (TMP)
4. Transforme les donnees TMP → DETAIL via un MERGE Oracle (dedup, nettoyage, conversions)
5. Audite chaque fichier dans `LOAD_AUDIT`
6. Deplace les fichiers vers OUT ou ERR, suppression FTP optionnelle

**Fonctionnalites principales**
- Import CSV depuis FTP ou dossier local vers Oracle (tables de staging puis DETAIL)
- Validation stricte du CSV (header, nombre de colonnes, quotes equilibrees)
- Validation des colonnes contre la whitelist Oracle (mode dynamique/strict/permissif)
- Transformation TMP → DETAIL avec MERGE, deduplication (`CHARGING_ID`), nettoyage (`TRIM`, `UPPER`, `clean_msisdn`) et conversion de types (epoch → DATE, string → NUMBER)
- Audit complet des chargements dans `LOAD_AUDIT` (statuts DOWNLOADED/SUCCESS/ERROR)
- Cache des colonnes TMP Oracle (24h) avec commande de gestion
- Planification automatique toutes les 10 minutes (sans chevauchement)

**Pile technique**
- Laravel 12, PHP ≥ 8.2
- Oracle XE 21 (driver `yajra/laravel-oci8` v12)
- Docker Sail + image custom PHP 8.4 avec OCI8 + FTP
- Vite 7 + Tailwind 4

## Architecture Docker

Les services Docker sont definis dans [docker-compose.yml](docker-compose.yml).

| Service | Image | Port |
|---|---|---|
| `laravel.test` | `sail-8.4/app` (build local) | `80` (app), `5173` (Vite) |
| `oracle` | `gvenzl/oracle-xe:21-slim` | `1522 → 1521` |

L image PHP est construite avec un Dockerfile custom qui installe OCI8, Composer, l extension FTP et l Oracle Instant Client 21.x : [docker/8.4/Dockerfile](docker/8.4/Dockerfile)

Le volume `sail-oracle` assure la persistance des donnees Oracle entre les redemarrages.

## Configuration base de donnees (Oracle)

Le driver Oracle est configure dans [config/database.php](config/database.php) via `yajra/laravel-oci8`.

Variables attendues dans `.env` (exemple) :

```
DB_CONNECTION=oracle
DB_HOST=oracle
DB_PORT=1521
DB_DATABASE=XEPDB1
DB_SERVICE_NAME=XEPDB1
DB_USERNAME=RAPRD
DB_PASSWORD=simplepass
ORACLE_HOST_PORT=1522
```

## Configuration FTP

Deux disques FTP sont configures dans [config/filesystems.php](config/filesystems.php) :
- `ftp` — disque FTP generique
- `ftp_cdr` — disque CDR avec `root: /home`

Variables `.env` :

```
FTP_HOST=
FTP_USERNAME=
FTP_PASSWORD=
FTP_PORT=21
FTP_SSL=false
FTP_PASSIVE=true
FTP_TIMEOUT=30
```

## Commandes utiles

**Demarrer les services**

```bash
./vendor/bin/sail up -d
```

**Arreter les services**

```bash
./vendor/bin/sail down
```

**Rebuild complet (image PHP OCI8)**

```bash
./vendor/bin/sail build --no-cache
```

**Composer dans le container**

```bash
./vendor/bin/sail exec laravel.test composer -V
```

**Artisan (exemple)**

```bash
./vendor/bin/sail exec laravel.test php artisan config:clear
```

**Lancer les tests**

```bash
php artisan test
# ou
./vendor/bin/sail exec laravel.test php artisan test
```

## Commandes Artisan CDR

| Commande | Description |
|---|---|
| `cdr:run` | Pipeline complet : FTP → TMP → DETAIL → OUT/ERR. Planifie toutes les 10 min. |
| `cdr:run-local` | Meme pipeline mais depuis des dossiers locaux (`--mmg-path=`, `--occ-path=`). |
| `cdr:ftp-list` | Teste la connexion FTP et liste les fichiers (`--dir=`). |
| `cdr:cache-columns` | Gere le cache des colonnes TMP Oracle (`--only=`, `--clear`, `--show`). |

### Pipeline `cdr:run` (detail)

Commande principale dans [app/Console/Commands/CdrRun.php](app/Console/Commands/CdrRun.php)

**Flux complet**
1. Connexion au disque FTP `ftp_cdr`, iteration sur `/home/MMG` et `/home/OCC`
2. Verification anti-doublon via `LOAD_AUDIT` (skip si deja `SUCCESS`)
3. Telechargement atomique : `TMP/*.part` → `IN/<dir>/`
4. Validation CSV stricte (nombre de colonnes, quotes equilibrees)
5. Validation des colonnes contre la whitelist Oracle (mode dynamique via `cdr:cache-columns`)
6. INSERT ALL par batch (taille configurable, defaut 500) dans la table TMP Oracle avec bind variables + SYSDATE
7. Verification du nombre de lignes (CSV vs DB)
8. Transformation TMP → DETAIL via `CdrTransformService` (MERGE Oracle avec dedup, nettoyage, conversions)
9. Deplacement du fichier vers `OUT/` (succes) ou `ERR/` (echec)
10. Suppression optionnelle du fichier distant FTP

### Planification

Definie dans [app/Console/Kernel.php](app/Console/Kernel.php) :

```php
$schedule->command('cdr:run')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/cdr-schedule.log'));
```

## Service de transformation (`CdrTransformService`)

Voir [app/Services/CdrTransformService.php](app/Services/CdrTransformService.php)

| Methode | Role |
|---|---|
| `transformOccTmpToDetail($fileName)` | MERGE TMP → DETAIL avec dedup, nettoyage, conversions. Retourne `[inserted, rejected, tmpRows]` |
| `validateColumns($headers, $type, $mode)` | Valide les headers CSV contre la whitelist (mode `dynamic` ou `detail`) |
| `getTmpColumnsWhitelist($type)` | Whitelist cachee (24h) des colonnes Oracle TMP |
| `getTableColumns($table)` | Decouverte dynamique des colonnes Oracle via `USER_TAB_COLUMNS` |

**Regles de transformation (OCC)**
- `trim` → `TRIM()`
- `upper` → `UPPER(TRIM())`
- `clean_msisdn` → suppression `\r`, `\n`, `\t`, espaces
- `number` → `TO_NUMBER()` avec validation regex (ou NULL)
- `timestamp` → epoch (secondes ou millisecondes) → Oracle `DATE`
- Deduplication via MERGE ON `CHARGING_ID` (fallback : `CALL_REFERENCE`, `RECORD_ID`)
- Nettoyage TMP configurable : `on_success` (defaut), `on_error`, `never`

## Tables Oracle

| Table | Role |
|---|---|
| `LOAD_AUDIT` | Audit des chargements (statuts : DOWNLOADED, SUCCESS, ERROR) |
| `RA_T_TMP_OCC` | Staging OCC (donnees brutes CSV) |
| `RA_T_TMP_MMG` | Staging MMG (donnees brutes CSV) |
| `RA_T_OCC_CDR_DETAIL` | Donnees OCC transformees (22+ colonnes, index unique sur `CHARGING_ID`) |
| `RA_T_MMG_CDR_DETAIL` | Donnees MMG transformees (**TODO**) |

Le DDL Oracle pour la table DETAIL et ses index est dans [database/migrations/create_occ_detail_table.sql](database/migrations/create_occ_detail_table.sql).
Une vue `V_OCC_CDR_RECENT` (30 derniers jours) est egalement creee.

Les tables sont **pre-creees** dans la base Oracle. Le code ne tente pas de les creer automatiquement.

## Configuration CDR

Voir [config/cdr.php](config/cdr.php) — parametres principaux :

| Cle | Defaut | Description |
|---|---|---|
| `timestamp_unit` | `seconds` | Unite epoch (seconds ou milliseconds) |
| `batch_size` | `500` | Nombre de lignes par batch INSERT |
| `tmp_cleanup_strategy` | `on_success` | Quand supprimer les lignes TMP |
| `tmp_whitelist_mode` | `dynamic` | Mode validation colonnes (dynamic/permissive/strict) |

**Mapping OCC** — 22 colonnes mappees dont :
- 15 champs requis (DATASOURCE, A_MSISDN, START_DATE, APN, CALL_TYPE, etc.)
- 6 champs numeriques optionnels (EVENT_COUNT, DATA_VOLUME, CHARGE_AMOUNT, etc.)
- Cles de dedup : `CHARGING_ID`, `CALL_REFERENCE`, `RECORD_ID`

**Mapping MMG** — a implementer (TODO)

## Dossiers locaux (storage)

```
storage/app/cdr/
  TMP/               ← fichiers .part temporaires pendant le telechargement
  IN/MMG/            ← fichiers telecharges en attente de traitement
  IN/OCC/
  OUT/MMG/           ← fichiers traites avec succes
  OUT/OCC/
  ERR/MMG/           ← fichiers en erreur
  ERR/OCC/
```

## Tests

9 tests unitaires dans [tests/Unit/CdrTransformServiceTest.php](tests/Unit/CdrTransformServiceTest.php) :

| Test | Verification |
|---|---|
| `test_occ_valid_columns_pass_validation` | 19 headers OCC valides passent la validation |
| `test_occ_unknown_columns_fail_validation` | Colonnes inconnues rejetees |
| `test_technical_columns_are_allowed` | `SOURCE_FILE`, `SOURCE_DIR`, `LOAD_TS` sont whitelistes |
| `test_timestamp_unit_default_is_seconds` | Config defaut = `seconds` |
| `test_occ_mapping_has_required_fields` | 15 champs requis presents |
| `test_occ_dedup_keys_are_configured` | `CHARGING_ID` dans les cles de dedup |
| `test_table_names_are_configured` | Noms des tables TMP et DETAIL corrects |
| `test_batch_size_is_configurable` | Batch size > 0 |
| `test_tmp_cleanup_strategy_is_configured` | Strategie dans `on_success/on_error/never` |
| `test_mmg_transformation_throws_not_implemented` | MMG transform leve une exception |

## Dependances principales

Voir [composer.json](composer.json) et [package.json](package.json)

**PHP (production)**
| Package | Version |
|---|---|
| `laravel/framework` | `^12.0` |
| `yajra/laravel-oci8` | `^12` |
| `league/flysystem-ftp` | `^3` |
| `laravel/tinker` | `^2.10.1` |

**PHP (dev)**
| Package | Version |
|---|---|
| `phpunit/phpunit` | `^11.5.3` |
| `laravel/sail` | `^1.52` |
| `laravel/pint` | `^1.24` |
| `mockery/mockery` | `^1.6` |

**JS (dev)**
| Package | Version |
|---|---|
| `vite` | `^7.0.7` |
| `tailwindcss` | `^4.0.0` |
| `laravel-vite-plugin` | `^2.0.0` |
| `axios` | `^1.11.0` |

**Scripts Composer**
- `composer setup` — install, key:generate, migrate, npm install+build
- `composer dev` — lance serveur + queue + Vite en parallele (`concurrently`)
- `composer test` — config:clear + artisan test

## Notes de dev

- Le projet est **100 % CLI** : pas de controllers applicatifs, pas de routes API. Seul le welcome blade est expose en GET `/`.
- La logique d import utilise le Query Builder (`DB::table`) et des requetes SQL brutes (INSERT ALL, MERGE).
- Les colonnes Oracle sont derivees du header CSV et normalisees (majuscules, 30 chars max).
- Si le nombre de lignes inserees ne correspond pas au CSV, les lignes sont supprimees et le fichier passe en ERR.
- Le mapping MMG n est pas encore implemente (`transformMmgTmpToDetail` leve une exception).

## Troubleshooting rapide

**Composer introuvable dans l image**
- Rebuild avec [docker/8.4/Dockerfile](docker/8.4/Dockerfile) (Composer est installe dans l image)

**Oracle non joignable**
- Verifier `ORACLE_HOST_PORT` et `DB_HOST=oracle`
- Verifier la config dans [docker-compose.yml](docker-compose.yml)

**Erreur permission sur composer.json**
- Utiliser `./vendor/bin/sail exec --user root laravel.test composer ...`

**Logs du pipeline CDR**
- `storage/logs/cdr-schedule.log` (sortie planifiee)
- `storage/logs/laravel.log` (logs applicatifs)


