# TMP Whitelist - Validation Dynamique depuis Oracle

## 🎯 Problème Résolu

### Ancien Comportement (AVANT)
```
❌ WHITELIST_ERROR: Unknown columns: AGGREGATION_GROUP, A_IMSI, A_MSISDN_ORIG, ...
```

**Pourquoi?**
- Le système appliquait la whitelist **DETAIL** (colonnes transformées) sur les fichiers CSV entrants
- Les CSV contiennent **toutes les colonnes TMP** (plus nombreuses que DETAIL)
- Résultat: fichiers valides rejetés en ERR

### Architecture Correcte (APRÈS)
```
CSV (FTP) → [TMP Whitelist] → RA_T_TMP_OCC → [Transform] → [DETAIL Whitelist] → RA_T_OCC_CDR_DETAIL
              ↑ Dynamic Oracle                                ↑ Config mapping
```

## 🧠 Solution Mise en Place

### 1. Whitelist Séparée par Stage

| Stage | Whitelist | Source | Objectif |
|-------|-----------|--------|----------|
| **TMP** | Colonnes Oracle | `USER_TAB_COLUMNS` (dynamic) | Accepter tous les champs du CSV brut |
| **DETAIL** | Config mapping | `config/cdr.php::occ_mapping` | Valider/transformer les champs essentiels |

### 2. Mode de Validation Configurable

Dans `.env`:
```bash
# Mode de validation TMP (recommandé: dynamic)
CDR_TMP_WHITELIST_MODE=dynamic
```

**Modes disponibles:**

| Mode | Description | Cas d'usage |
|------|-------------|-------------|
| `dynamic` | Fetch depuis Oracle avec cache 24h | **Production (recommandé)** |
| `permissive` | Accepte toutes les colonnes | Tests locaux sans Oracle |
| `strict` | Utilise config mapping | Legacy (non recommandé) |

### 3. Cache Intelligent

- **Durée:** 24 heures
- **Clé:** `cdr.tmp_columns.{type}`
- **Fallback:** Si Oracle inaccessible → mode permissif automatique

## 📋 Commandes

### Mettre à jour le cache des colonnes

```bash
# Refresh tous les types (occ, mmg)
php artisan cdr:cache-columns

# Refresh OCC uniquement
php artisan cdr:cache-columns --only=occ

# Voir le cache actuel
php artisan cdr:cache-columns --show

# Nettoyer le cache
php artisan cdr:cache-columns --clear
```

**Quand l'utiliser?**
- ✅ Après modification de la structure de `RA_T_TMP_OCC` (ajout/suppression de colonnes)
- ✅ Lors du déploiement initial (pour pré-charger le cache)
- ✅ Si vous voyez encore des WHITELIST_ERROR après avoir ajouté une colonne

### Exemple de sortie

```
🔄 Mise à jour du cache des colonnes TMP...

📋 Type: occ → Table: RA_T_TMP_OCC
   ✅ RA_T_TMP_OCC: 45 colonnes mises en cache
   Colonnes: AGGREGATION_GROUP, APN, A_IMSI, A_MSISDN, A_MSISDN_ORIG, ...

📋 Type: mmg → Table: RA_T_TMP_MMG
   ⚠️  Aucune colonne trouvée pour RA_T_TMP_MMG
   Vérifiez que la table existe dans Oracle (USER_TAB_COLUMNS)

🎉 Mise à jour terminée
💡 Le cache est maintenant valide pour 24 heures
```

## 🔍 Vérification

### 1. Tester la connexion Oracle et le fetch des colonnes

```bash
php artisan tinker
```

```php
use App\Services\CdrTransformService;

$service = new CdrTransformService();

// Tester le fetch direct (sans cache)
$columns = $service->getTableColumns('RA_T_TMP_OCC');
print_r($columns);

// Tester le cache
$cached = $service->getTmpColumnsWhitelist('occ');
print_r($cached);
```

**Sortie attendue:**
```php
Array
(
    [0] => AGGREGATION_GROUP
    [1] => APN
    [2] => A_IMSI
    [3] => A_MSISDN
    [4] => A_MSISDN_ORIG
    [5] => CALL_REFERENCE
    [6] => CALL_TYPE
    // ... (toutes les colonnes de RA_T_TMP_OCC)
)
```

### 2. Tester la validation

```php
use App\Services\CdrTransformService;

$service = new CdrTransformService();

// Colonnes typiques d'un CSV OCC
$headerCols = [
    'AGGREGATION_GROUP', 'A_IMSI', 'A_MSISDN_ORIG', 'APN', 'CALL_TYPE',
    'CHARGING_ID', 'DATASOURCE', 'EVENT_TYPE', 'ORIG_START_TIME'
];

// Mode TMP (dynamic)
$validation = $service->validateColumns($headerCols, 'occ', 'tmp');
print_r($validation);

// Résultat attendu:
// ['valid' => true, 'unknown_columns' => []]
```

### 3. Vérifier les logs

Après upload d'un fichier OCC:

```bash
tail -f storage/logs/cdr-*.log
```

**AVANT (erreur):**
```
[2026-02-18 10:00:00] cdr.ERROR: WHITELIST_ERROR: Unknown columns: AGGREGATION_GROUP, A_IMSI, A_MSISDN_ORIG
```

**APRÈS (succès):**
```
[2026-02-18 10:05:00] cdr.INFO: getTmpColumnsWhitelist: Cached 45 columns for RA_T_TMP_OCC
[2026-02-18 10:05:02] cdr.INFO: transformOccTmpToDetail: Processing 125000 rows for file CDR_OCC_20260218.csv
[2026-02-18 10:06:15] cdr.INFO: Transform SUCCESS for CDR_OCC_20260218.csv {"inserted":124850,"rejected":150,"tmpRows":125000}
```

## 🔧 Dépannage

### Problème: "WHITELIST_ERROR" persiste après la correction

**Solution:**
```bash
# 1. Nettoyer le cache
php artisan cdr:cache-columns --clear

# 2. Refresh depuis Oracle
php artisan cdr:cache-columns

# 3. Vérifier
php artisan cdr:cache-columns --show
```

### Problème: "No Oracle columns available" (mode permissif activé)

**Causes possibles:**
- ❌ Table `RA_T_TMP_OCC` n'existe pas dans Oracle
- ❌ Connexion Oracle échouée (.env incorrect)
- ❌ User Oracle n'a pas accès à USER_TAB_COLUMNS

**Vérification:**
```sql
-- Depuis SQLPlus ou SQL Developer
SELECT COUNT(*) FROM USER_TAB_COLUMNS WHERE TABLE_NAME = 'RA_T_TMP_OCC';
-- Doit retourner > 0
```

**Solution temporaire:**
```bash
# Activer mode permissif dans .env
CDR_TMP_WHITELIST_MODE=permissive
```

### Problème: Fichier rejeté malgré mode dynamic

**Vérifier:**
1. Cache valide:
   ```bash
   php artisan cdr:cache-columns --show
   ```

2. Config correcte:
   ```bash
   php artisan tinker
   config('cdr.tmp_whitelist_mode') // doit retourner 'dynamic'
   ```

3. Colonne manquante dans Oracle:
   ```sql
   SELECT COLUMN_NAME FROM USER_TAB_COLUMNS 
   WHERE TABLE_NAME = 'RA_T_TMP_OCC' 
   ORDER BY COLUMN_ID;
   ```

## 📊 Performance

### Impact du Cache

| Scénario | Avant (sans cache) | Après (avec cache) |
|----------|-------------------|-------------------|
| Validation par fichier | ~200ms (query Oracle) | ~0.1ms (array lookup) |
| Fichiers/jour (10k) | 2000 secondes | 1 seconde |

### Refresh du Cache

```bash
# Manuelle (si besoin)
php artisan cdr:cache-columns

# Automatique via cron (optionnel)
0 3 * * * cd /path/to/app && php artisan cdr:cache-columns >> /dev/null 2>&1
```

## 🎓 Points Clés

1. ✅ **TMP Stage**: Acccepte **toutes** les colonnes de la table Oracle (dynamic)
2. ✅ **DETAIL Stage**: Valide et transforme selon `config/cdr.php::occ_mapping`
3. ✅ **Cache 24h**: Performance optimale, refresh automatique
4. ✅ **Fallback**: Si Oracle inaccessible → mode permissif (évite blocage)
5. ✅ **Future-proof**: Ajout de colonnes dans Oracle → détecté automatiquement au prochain refresh

## 📚 Résumé

**Problème original:**
- Whitelist DETAIL appliquée sur CSV entrants
- Colonnes TMP (AGGREGATION_GROUP, A_IMSI, etc.) rejetées

**Solution:**
- Whitelist TMP séparée, fetchée depuis Oracle
- Cache 24h pour performance
- Mode permissif en fallback
- Command artisan pour refresh manuel

**Résultat:**
- ✅ Fichiers OCC acceptés avec toutes leurs colonnes
- ✅ Validation cohérente avec la structure Oracle
- ✅ Maintenance simplifiée (ajout de colonnes automatique)
- ✅ Anti-duplicate logic fonctionne correctement
