<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CdrTransformService;
use Illuminate\Support\Facades\Cache;

/**
 * Commande pour mettre à jour le cache des colonnes TMP depuis Oracle.
 *
 * Utilité:
 * - Forcer le refresh du cache après modification de la structure des tables TMP
 * - Vérifier que la connexion Oracle fonctionne
 * - Voir quelles colonnes sont actuellement en cache
 *
 * Usage:
 *   php artisan cdr:cache-columns          # Refresh tous les types (occ, mmg)
 *   php artisan cdr:cache-columns --only=occ   # Refresh OCC uniquement
 *   php artisan cdr:cache-columns --clear      # Nettoyer le cache sans refresh
 */
class CdrCacheColumns extends Command
{
    protected $signature = 'cdr:cache-columns
                            {--only= : Type de CDR à traiter (occ ou mmg)}
                            {--clear : Nettoyer le cache sans le reconstruire}
                            {--show : Afficher les colonnes cachées actuelles}';

    protected $description = 'Met à jour le cache des colonnes TMP depuis Oracle (whitelist dynamique)';

    public function handle(): int
    {
        $transformService = new CdrTransformService();

        $types = $this->option('only') ? [$this->option('only')] : ['occ', 'mmg'];

        // Option: afficher le cache actuel
        if ($this->option('show')) {
            $this->showCachedColumns($types);
            return 0;
        }

        // Option: nettoyer le cache
        if ($this->option('clear')) {
            $this->clearCache($types);
            $this->info('✅ Cache nettoyé avec succès');
            return 0;
        }

        // Refresh du cache
        $this->info('🔄 Mise à jour du cache des colonnes TMP...');
        $this->newLine();

        foreach ($types as $type) {
            $cacheKey = "cdr.tmp_columns.{$type}";
            $tmpTable = config("cdr.tables.{$type}.tmp");

            if (!$tmpTable) {
                $this->warn("⚠️  Aucune table TMP configurée pour le type '{$type}'");
                $this->line("   Vérifiez config/cdr.php → tables.{$type}.tmp");
                continue;
            }

            $this->line("📋 Type: <fg=cyan>{$type}</> → Table: <fg=yellow>{$tmpTable}</>");

            try {
                // Nettoyer le cache existant
                Cache::forget($cacheKey);

                // Fetcher depuis Oracle
                $columns = $transformService->getTableColumns($tmpTable);

                if (empty($columns)) {
                    $this->error("   ❌ Aucune colonne trouvée pour {$tmpTable}");
                    $this->line("   Vérifiez que la table existe dans Oracle (USER_TAB_COLUMNS)");
                    continue;
                }

                // Mettre en cache (24h)
                Cache::put($cacheKey, $columns, 86400);

                $this->info("   ✅ {$tmpTable}: " . count($columns) . " colonnes mises en cache");

                // Afficher les colonnes (limit 10)
                $preview = array_slice($columns, 0, 10);
                $this->line("   Colonnes: " . implode(', ', $preview) . (count($columns) > 10 ? '...' : ''));

            } catch (\Throwable $e) {
                $this->error("   ❌ Erreur: " . $e->getMessage());
                $this->line("   Vérifiez la connexion Oracle et l'existence de la table");
            }

            $this->newLine();
        }

        $this->info('🎉 Mise à jour terminée');
        $this->line('💡 Le cache est maintenant valide pour 24 heures');
        $this->line('💡 Pour forcer un refresh: <fg=cyan>php artisan cdr:cache-columns</>');

        return 0;
    }

    /**
     * Affiche les colonnes actuellement en cache.
     */
    private function showCachedColumns(array $types): void
    {
        $this->info('📦 Cache actuel des colonnes TMP:');
        $this->newLine();

        foreach ($types as $type) {
            $cacheKey = "cdr.tmp_columns.{$type}";
            $cached = Cache::get($cacheKey);

            $this->line("Type: <fg=cyan>{$type}</>");

            if ($cached === null) {
                $this->warn('   ⚠️  Aucun cache trouvé');
                $this->line('   Exécutez: <fg=cyan>php artisan cdr:cache-columns</> pour créer le cache');
            } elseif (is_array($cached)) {
                $this->info('   ✅ Colonnes en cache: ' . count($cached));
                $this->line('   ' . implode(', ', $cached));
            } else {
                $this->warn('   ⚠️  Cache corrompu (type: ' . gettype($cached) . ')');
            }

            $this->newLine();
        }
    }

    /**
     * Nettoie le cache sans le reconstruire.
     */
    private function clearCache(array $types): void
    {
        foreach ($types as $type) {
            $cacheKey = "cdr.tmp_columns.{$type}";
            Cache::forget($cacheKey);
            $this->line("🧹 Cache nettoyé: <fg=cyan>{$cacheKey}</>");
        }
    }
}
