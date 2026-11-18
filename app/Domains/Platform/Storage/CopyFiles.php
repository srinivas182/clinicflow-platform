<?php

declare(strict_types=1);

namespace App\Domains\Platform\Storage;

use App\Domains\Platform\Models\Provider;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Copies every file (the platform's and each practice's) from one storage into another, checking
 * each copy by size and SHA-256. Re-runnable (files already there are skipped); never deletes.
 * The result is recorded on the target: only a copy with no failures lets the target be activated.
 */
class CopyFiles
{
    /** Source key for this server's disk when no storage target was ever activated. */
    public const DEFAULT_SOURCE = 'default';

    private const PROGRESS_EVERY = 25;

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    /** The storage files are in now: the active target, or this server's disk. */
    public function currentSource(): string
    {
        $id = $this->db()->table('storage_targets')->where('active', true)->value('id');

        return $id === null ? self::DEFAULT_SOURCE : (string) $id;
    }

    /**
     * @param  string|null  $source  target id or "default"; null = where files are now
     * @param  (callable(string): void)|null  $onError
     * @return array{copied: int, skipped: int, failed: int}
     */
    public function handle(int $targetId, ?string $source = null, bool $dryRun = false, ?callable $onError = null): array
    {
        $target = $this->row($targetId) ?? throw ValidationException::withMessages(['target' => 'Storage target not found.']);
        $source ??= $this->currentSource();
        $from = $source === self::DEFAULT_SOURCE ? null : ($this->row((int) $source) ?? throw ValidationException::withMessages(['target' => 'The storage to copy from no longer exists.']));
        if ((string) $targetId === $source) {
            throw ValidationException::withMessages(['target' => 'This storage already holds the files.']);
        }

        $counts = ['copied' => 0, 'skipped' => 0, 'failed' => 0];
        $record = function (string $status) use ($targetId, $source, &$counts, $dryRun): void {
            if (! $dryRun) {
                $this->db()->table('storage_targets')->where('id', $targetId)->update(['copy_status' => $status, 'copy_progress' => json_encode($counts), 'copy_source' => $source,
                    'copied_at' => $status === 'done' ? now() : null, 'updated_at' => now()]);
            }
        };
        $record('running');

        try {
            $targetLocations = self::locations($target);
            foreach (self::locations($from) as $i => $loc) {
                $toConfig = $targetLocations[$i]['config'];
                if ($loc['config'] == $toConfig) {
                    continue; // same place (e.g. practice folders on this server's disk)
                }
                if ($loc['config']['driver'] === 'local' && ! is_dir((string) $loc['config']['root'])) {
                    continue; // nothing stored there yet
                }
                $this->copyLocation($loc['label'], Storage::build($loc['config']), Storage::build($toConfig), $dryRun, $counts, $record, $onError);
            }
        } catch (\Throwable $e) {
            $counts['failed']++;
            $onError !== null && $onError('Could not read or write the storage: '.mb_substr($e->getMessage(), 0, 200));
            Log::error('Storage copy stopped', ['target' => $targetId, 'error' => $e->getMessage()]);
        }
        $record($counts['failed'] === 0 ? 'done' : 'failed');
        if (! $dryRun) {
            activity('platform')->withProperties(['storage_target' => $targetId, 'from' => $source] + $counts)->log($counts['failed'] === 0 ? 'Files copied to storage and checked' : 'File copy to storage had failures');
        }

        return $counts;
    }

    /**
     * Where files live for a storage target (null = this server's default disk): the platform's
     * folder, then one folder per practice, in the same order for every target.
     *
     * @param  array<string, mixed>|null  $target
     * @return list<array{label: string, config: array<string, mixed>}>
     */
    public static function locations(?array $target): array
    {
        $local = $target === null || ($target['driver'] ?? 'local') === 'local';
        $base = $target === null ? ['driver' => 'local', 'root' => storage_path('app/private'), 'throw' => true] : FileStore::diskConfig($target);
        $locations = [['label' => 'platform', 'config' => $base]];
        foreach (Provider::query()->orderBy('id')->get() as $provider) {
            $folder = config('tenancy.filesystem.suffix_base').$provider->getKey();
            $locations[] = ['label' => (string) $provider->getAttribute('name'), 'config' => $local
                // On a server disk each practice keeps its own storage folder, whatever the platform folder is.
                ? ['driver' => 'local', 'root' => storage_path($folder.'/app'), 'throw' => true]
                : array_merge($base, ['root' => rtrim((string) $base['root'], '/').'/'.$folder])];
        }

        return $locations;
    }

    /**
     * @param  array{copied: int, skipped: int, failed: int}  $counts
     * @param  callable(string): void  $record
     * @param  (callable(string): void)|null  $onError
     */
    private function copyLocation(string $label, Filesystem $from, Filesystem $to, bool $dryRun, array &$counts, callable $record, ?callable $onError): void
    {
        foreach ($from->allFiles() as $path) {
            if (str_starts_with($path, '_clinicflow-probe/')) {
                continue;
            }
            try {
                $body = (string) $from->get($path);
                $hash = hash('sha256', $body);
                if ($this->same($to, $path, strlen($body), $hash)) {
                    $counts['skipped']++;
                } elseif ($dryRun) {
                    $counts['copied']++;
                } else {
                    $to->put($path, $body);
                    if (! $this->same($to, $path, strlen($body), $hash)) {
                        throw new \RuntimeException('the copy did not match the original');
                    }
                    $counts['copied']++;
                }
            } catch (\Throwable $e) {
                $counts['failed']++;
                $onError !== null && $onError("{$label}: {$path} — ".mb_substr($e->getMessage(), 0, 120));
                Log::warning('Storage copy: file failed', ['location' => $label, 'path' => $path, 'error' => $e->getMessage()]);
            }
            if (($counts['copied'] + $counts['skipped'] + $counts['failed']) % self::PROGRESS_EVERY === 0) {
                $record('running');
            }
        }
    }

    /** @phpstan-impure Reads the storage, so the answer changes after a copy. */
    private function same(Filesystem $disk, string $path, int $size, string $hash): bool
    {
        return $disk->exists($path) && $disk->size($path) === $size && hash('sha256', (string) $disk->get($path)) === $hash;
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        $row = $this->db()->table('storage_targets')->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }
}
