<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Storage\FileStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Copies existing local files into a storage target (each practice into its own folder),
 * verifying every file by size and SHA-256. Re-runnable; never deletes anything.
 */
class CopyFilesToStorageCommand extends Command
{
    protected $signature = 'storage:copy-to-active {target : The storage target id to copy into} {--dry-run : Only count what would be copied}';

    protected $description = 'Copy local files into a storage target and verify them (run before activating it)';

    public function handle(): int
    {
        $row = DB::connection((string) config('tenancy.database.central_connection'))->table('storage_targets')->where('id', (int) $this->argument('target'))->first();
        if ($row === null || $row->driver === 'local') {
            $this->error('Choose an S3 or S3-compatible storage target.');

            return self::FAILURE;
        }
        $base = FileStore::diskConfig((array) $row);
        $sources = [['label' => 'platform', 'from' => storage_path('app/private'), 'root' => (string) $base['root']]];
        foreach (Provider::query()->get() as $p) {
            $sources[] = ['label' => (string) $p->getAttribute('name'), 'from' => storage_path(config('tenancy.filesystem.suffix_base').$p->getKey().'/app'),
                'root' => rtrim((string) $base['root'], '/').'/'.config('tenancy.filesystem.suffix_base').$p->getKey()];
        }
        [$copied, $skipped, $failed] = [0, 0, 0];
        foreach ($sources as $s) {
            if (! is_dir($s['from'])) {
                continue;
            }
            $from = Storage::build(['driver' => 'local', 'root' => $s['from']]);
            $to = Storage::build(array_merge($base, ['root' => $s['root']]));
            foreach ($from->allFiles() as $path) {
                if (str_starts_with($path, '_clinicflow-probe/')) {
                    continue;
                }
                $hash = hash('sha256', (string) $from->get($path));
                if ($this->same($to, $path, $from->size($path), $hash)) {
                    $skipped++;

                    continue;
                }
                if ($this->option('dry-run')) {
                    $copied++;

                    continue;
                }
                try {
                    $to->put($path, (string) $from->get($path));
                    if (! $this->same($to, $path, $from->size($path), $hash)) {
                        throw new \RuntimeException('verification failed');
                    }
                    $copied++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("{$s['label']}: {$path} — ".mb_substr($e->getMessage(), 0, 120));
                }
            }
        }
        $this->info(($this->option('dry-run') ? 'Would copy' : 'Copied and verified')." {$copied}; already there {$skipped}; failed {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @phpstan-impure Reads the storage, so the answer changes after a copy. */
    private function same(Filesystem $disk, string $path, int $size, string $hash): bool
    {
        return $disk->exists($path) && $disk->size($path) === $size && hash('sha256', (string) $disk->get($path)) === $hash;
    }
}
