<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class FtpListCdr extends Command
{
    protected $signature = 'cdr:ftp-list {--dir= : MMG ou OCC (optionnel)}';
    protected $description = 'Test FTP connection and list /home/MMG and /home/OCC files';

    public function handle(): int
    {
        $disk = Storage::disk('ftp_cdr');

        $dirs = $this->option('dir')
            ? [strtoupper($this->option('dir'))]
            : ['MMG', 'OCC'];

        foreach ($dirs as $d) {
            $remotePath = $d; // car root=/home déjà
            $this->info("Listing FTP: /home/{$d}");

            try {
                // Liste des fichiers (chemins relatifs)
                $files = $disk->files($remotePath);

                if (empty($files)) {
                    $this->warn("Aucun fichier trouvé dans {$remotePath}");
                    continue;
                }

                foreach ($files as $file) {
                    // Métadonnées
                    $size = null;
                    $mtime = null;

                    try { $size = $disk->size($file); } catch (\Throwable $e) {}
                    try { $mtime = $disk->lastModified($file); } catch (\Throwable $e) {}

                    $this->line(sprintf(
                        "- %s | size=%s | mtime=%s",
                        $file,
                        $size ?? 'N/A',
                        $mtime ? date('Y-m-d H:i:s', $mtime) : 'N/A'
                    ));
                }
            } catch (\Throwable $e) {
                $this->error("FTP error on {$remotePath}: " . $e->getMessage());
                return Command::FAILURE;
            }

            $this->newLine();
        }

        $this->info("✅ FTP OK + listing done.");
        return Command::SUCCESS;
    }
}
