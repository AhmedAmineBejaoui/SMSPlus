# SMSPlus API

Projet Laravel 12 connecte a Oracle XE 21 avec Docker Sail. Le coeur du projet est un pipeline CDR (CSV) qui telecharge des fichiers via FTP, valide leur contenu, charge les donnees dans des tables Oracle temporaires, puis deplace les fichiers en OUT ou ERR.

**Fonctionnalites principales**
- Import CSV depuis FTP vers Oracle (tables de staging)
- Validation stricte du CSV (nombre de colonnes, quotes)
- Audit des chargements dans `LOAD_AUDIT`
- Deplacement des fichiers vers OUT/ERR et suppression FTP optionnelle

**Pile technique**
- Laravel 12, PHP 8.4
- Oracle XE 21 (driver `yajra/laravel-oci8`)
- Docker Sail + image custom PHP avec OCI8
- Vite + Tailwind

## Architecture Docker

Deux services Docker sont definis dans [compose.yaml](compose.yaml) (fichier source pour Sail) et dupliques dans [docker-compose.yml](docker-compose.yml).

- `laravel.test`: application Laravel (image `sail-8.4/app`)
- `oracle`: base Oracle XE 21 (`gvenzl/oracle-xe:21-slim`)

L image PHP est construite avec un Dockerfile custom qui installe OCI8, Composer et l extension FTP: [docker/8.4/Dockerfile](docker/8.4/Dockerfile)

## Configuration base de donnees (Oracle)

Le driver Oracle est active dans [config/database.php](config/database.php) via `yajra/laravel-oci8`.

Variables attendues dans `.env` (exemple) :

```
DB_CONNECTION=''
DB_HOST=''
DB_PORT=''
DB_DATABASE=''
DB_SERVICE_NAME=''
DB_USERNAME=''
DB_PASSWORD=''
ORACLE_HOST_PORT=''
```

## Commandes utiles

**Demarrer les services**

```
./vendor/bin/sail up -d
```

**Arreter les services**

```
./vendor/bin/sail down
```

**Rebuild complet (image PHP OCI8)**

```
./vendor/bin/sail build --no-cache
```

**Composer dans le container**

```
./vendor/bin/sail exec laravel.test composer -V
```

**Artisan (exemple)**

```
./vendor/bin/sail exec laravel.test php artisan config:clear
```

## Pipeline CDR (CSV)

Commande principale: `cdr:run` dans [app/Console/Commands/CdrRun.php](app/Console/Commands/CdrRun.php)

**Flux simplifie**
1. Liste des fichiers sur FTP par source (MMG, OCC)
2. Telechargement en local (TMP puis IN)
3. Validation CSV stricte (header + nombre de colonnes)
4. Insertion par batch dans Oracle
5. Verification du nombre de lignes
6. Deplacement vers OUT ou ERR

**Tables Oracle utilisees**
- `LOAD_AUDIT` (audit des chargements)
- `RA_T_TMP_MMG`
- `RA_T_TMP_OCC`

Les tables temporaires sont **pre-creees** dans la base Oracle. Le code ne tente plus de les creer automatiquement.

**Dossiers locaux (storage)**
- `storage/app/cdr/TMP`
- `storage/app/cdr/IN/{MMG|OCC}`
- `storage/app/cdr/OUT/{MMG|OCC}`
- `storage/app/cdr/ERR/{MMG|OCC}`

## Dependances principales

Voir [composer.json](composer.json) et [package.json](package.json)

- `yajra/laravel-oci8` (Oracle)
- `league/flysystem-ftp` (FTP)
- Laravel 12

## Notes de dev

- La logique d import utilise le Query Builder (`DB::table`).
- Les colonnes Oracle sont derivees du header CSV et normalisees (maj, 30 chars max).
- Si le nombre de lignes inserees ne correspond pas au CSV, les lignes sont supprimees et le fichier passe en ERR.

## Troubleshooting rapide

**Composer introuvable dans l image**
- Rebuild avec [docker/8.4/Dockerfile](docker/8.4/Dockerfile) (Composer est installe dans l image)

**Oracle non joignable**
- Verifier `ORACLE_HOST_PORT` et `DB_HOST=oracle`
- Verifier la config dans [compose.yaml](compose.yaml)

**Erreur permission sur composer.json**
- Utiliser `./vendor/bin/sail exec --user root laravel.test composer ...`


