<?php

declare(strict_types=1);

namespace App\Domains\Platform\Storage;

use App\Domains\Platform\Jobs\CopyFilesToStorage;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Super admin: storage targets. A target must pass a connection test (write, read, delete) and have
 * the existing files copied into it and checked before it can be activated; activating one
 * deactivates the others. The active target's location cannot be edited (files would seem lost);
 * new keys for it are tested before they are saved.
 */
class StorageTargets
{
    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    /**
     * @param  array{name: string, driver: string, provider: ?string, bucket: ?string, region: ?string, endpoint: ?string, path_style: bool, encrypt: bool, root: ?string, key: ?string, secret: ?string}  $data
     */
    public function save(?int $id, array $data): int
    {
        $errors = [];
        // Unique storage names (beta #173).
        if ($this->db()->table('storage_targets')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->when($id !== null, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $errors['name'] = 'Another storage has this name.';
        }
        if ($id !== null && ! $this->db()->table('storage_targets')->where('id', $id)->exists()) {
            abort(404);
        }
        if (! in_array($data['driver'], ['local', 's3'], true)) {
            $errors['driver'] = 'Choose local disk or S3-compatible storage.';
        }
        if ($data['driver'] === 's3') {
            if (preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', (string) $data['bucket']) !== 1) {
                $errors['bucket'] = 'Enter a valid bucket name.';
            }
            if ($data['endpoint'] !== null && $data['endpoint'] !== '' && ! str_starts_with((string) $data['endpoint'], 'https://')) {
                $errors['endpoint'] = 'The endpoint must use https://.';
            }
            if ($data['provider'] === 'aws' && preg_match('/^[a-z]{2}-[a-z]+-\d$/', (string) $data['region']) !== 1) {
                $errors['region'] = 'Enter the AWS region, e.g. af-south-1 (Cape Town).';
            }
            if ($id === null && (! filled($data['key']) || ! filled($data['secret']))) {
                $errors['key'] = 'Enter the access key and secret.';
            }
        }
        $root = (string) ($data['root'] ?? '');
        if ($root !== '') {
            if (preg_match('#^[A-Za-z0-9_\-./]{1,120}$#', $root) !== 1 || str_contains($root, '..')) {
                $errors['root'] = 'Use letters, numbers, dashes and slashes only.';
            } elseif ($data['driver'] === 's3' && str_starts_with($root, '/')) {
                $errors['root'] = 'The top folder must not start with a slash.';
            } elseif ($data['driver'] === 'local' && ! self::safeLocalRoot($root)) {
                // A folder inside the app (e.g. public/) could expose patient files on the web.
                $errors['root'] = 'Use a full path outside the application folder (or inside its private storage folder).';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $current = $id === null ? null : $this->db()->table('storage_targets')->where('id', $id)->first();
        if ($current !== null && (bool) $current->active) {
            return $this->saveActive((array) $current, $data);
        }
        $row = ['name' => trim($data['name']), 'driver' => $data['driver'], 'provider' => $data['driver'] === 's3' ? $data['provider'] : null,
            'bucket' => $data['bucket'], 'region' => $data['region'], 'endpoint' => $data['endpoint'] ?: null, 'path_style' => $data['path_style'],
            'encrypt' => $data['encrypt'], 'root' => $data['root'] ?: null, 'verified_at' => null,
            // Changed details: test again and copy the files again before activating.
            'copy_status' => null, 'copy_progress' => null, 'copy_source' => null, 'copied_at' => null, 'updated_at' => now()];
        if (filled($data['key']) && filled($data['secret'])) {
            $row['credentials'] = Crypt::encryptString((string) json_encode(['key' => $data['key'], 'secret' => $data['secret']]));
        }
        if ($id === null) {
            return (int) $this->db()->table('storage_targets')->insertGetId($row + ['active' => false, 'created_at' => now()]);
        }
        $this->db()->table('storage_targets')->where('id', $id)->update($row);

        return $id;
    }

    /**
     * The active target: only its name and keys may change, and new keys are used only after they
     * pass the connection test. Moving files elsewhere is done by adding a new target and copying.
     *
     * @param  array<string, mixed>  $current
     * @param  array{name: string, driver: string, provider: ?string, bucket: ?string, region: ?string, endpoint: ?string, path_style: bool, encrypt: bool, root: ?string, key: ?string, secret: ?string}  $data
     */
    private function saveActive(array $current, array $data): int
    {
        $same = fn (?string $a, mixed $b): bool => (string) ($a ?? '') === (string) ($b ?? '');
        $moved = $data['driver'] !== $current['driver'] || ! $same($data['bucket'], $current['bucket']) || ! $same($data['region'], $current['region'])
            || ! $same($data['endpoint'], $current['endpoint']) || ! $same($data['root'], $current['root']) || $data['path_style'] !== (bool) $current['path_style']
            || ($data['driver'] === 's3' && ! $same($data['provider'], $current['provider'])) || $data['encrypt'] !== (bool) $current['encrypt'];
        if ($moved) {
            throw ValidationException::withMessages(['target' => 'This storage is in use, so only its name and keys can change. To move files to another bucket or folder, add a new storage, copy the files and activate it.']);
        }
        $row = ['name' => trim($data['name']), 'updated_at' => now()];
        if (filled($data['key']) && filled($data['secret'])) {
            $credentials = Crypt::encryptString((string) json_encode(['key' => $data['key'], 'secret' => $data['secret']]));
            $error = $this->probe(['credentials' => $credentials] + $current);
            if ($error !== null) {
                throw ValidationException::withMessages(['key' => "The new keys did not pass the connection test, so the current keys are still in use. {$error}"]);
            }
            $row += ['credentials' => $credentials, 'verified_at' => now(), 'last_error' => null];
        }
        $this->db()->table('storage_targets')->where('id', $current['id'])->update($row);
        FileStore::forget();
        activity('platform')->withProperties(['storage_target' => $current['id'], 'keys_changed' => isset($row['credentials'])])->log('Active file storage edited');

        return (int) $current['id'];
    }

    /** Local folders must be absolute and outside the application, except its private storage folder. */
    public static function safeLocalRoot(string $root): bool
    {
        $root = rtrim($root, '/');
        $base = rtrim(base_path(), '/');
        $storage = rtrim(storage_path(), '/');
        $public = rtrim(public_path(), '/');
        $inside = fn (string $dir) => $root === $dir || str_starts_with($root.'/', $dir.'/');

        return str_starts_with($root, '/') && ! $inside($public) && ($inside($storage) || ! $inside($base));
    }

    /** Writes, reads back and deletes a probe file. */
    public function test(int $id): bool
    {
        $target = (array) ($this->db()->table('storage_targets')->where('id', $id)->first() ?? []);
        if ($target === []) {
            throw ValidationException::withMessages(['target' => 'Storage target not found.']);
        }
        $error = $this->probe($target);
        $ok = $error === null;
        $this->db()->table('storage_targets')->where('id', $id)->update(['verified_at' => $ok ? now() : null, 'last_error' => $error, 'updated_at' => now()]);

        return $ok;
    }

    /**
     * Writes, reads back and deletes a probe file; null when that worked, else the problem.
     *
     * @param  array<string, mixed>  $target
     */
    private function probe(array $target): ?string
    {
        $probe = '_clinicflow-probe/'.Str::random(16).'.txt';
        $body = 'probe '.now()->toIso8601String();
        try {
            $disk = Storage::build(FileStore::diskConfig($target));
            $disk->put($probe, $body);
            $ok = $disk->get($probe) === $body;
            $disk->delete($probe);

            return $ok ? null : 'The file read back did not match.';
        } catch (\Throwable $e) {
            return mb_substr($e->getMessage(), 0, 250);
        }
    }

    /** Starts copying the existing files into a target in the background. */
    public function startCopy(int $id): void
    {
        $target = $this->db()->table('storage_targets')->where('id', $id)->first();
        if ($target === null || $target->verified_at === null) {
            throw ValidationException::withMessages(['target' => 'Test the connection successfully before copying files.']);
        }
        if ((bool) $target->active) {
            throw ValidationException::withMessages(['target' => 'This storage already holds the files.']);
        }
        if (in_array($target->copy_status, ['queued', 'running'], true) && $target->updated_at !== null && now()->subHours(2)->lt($target->updated_at)) {
            throw ValidationException::withMessages(['target' => 'The files are already being copied.']);
        }
        $this->db()->table('storage_targets')->where('id', $id)->update(['copy_status' => 'queued', 'copy_progress' => null, 'copied_at' => null, 'updated_at' => now()]);
        CopyFilesToStorage::dispatch($id, app(CopyFiles::class)->currentSource());
    }

    /** Delete an unused entry (beta #182). The storage in use cannot be deleted; files at the target are left alone. */
    public function delete(int $id): void
    {
        $target = $this->db()->table('storage_targets')->where('id', $id)->first();
        abort_if($target === null, 404);
        if ((bool) $target->active) {
            throw ValidationException::withMessages(['target' => 'This storage is in use. Activate another one (or this server\'s disk) first.']);
        }
        if (in_array($target->copy_status, ['queued', 'running'], true)) {
            throw ValidationException::withMessages(['target' => 'Files are being copied into this storage. Wait for the copy to finish.']);
        }
        $this->db()->table('storage_targets')->where('id', $id)->delete();
        activity('platform')->withProperties(['storage_target' => $id, 'name' => $target->name])->log('Storage entry deleted');
    }

    /**
     * Back to this server's disk (beta #182): an entry for the server's own disk, which then goes
     * through the usual test, copy and activate steps so no file is left behind.
     */
    public function serverDisk(): int
    {
        $existing = $this->db()->table('storage_targets')->where('driver', 'local')->whereNull('root')->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return $this->save(null, ['name' => "This server's disk", 'driver' => 'local', 'provider' => null, 'bucket' => null, 'region' => null, 'endpoint' => null,
            'path_style' => false, 'encrypt' => false, 'root' => null, 'key' => null, 'secret' => null]);
    }

    public function activate(int $id): void
    {
        $target = $this->db()->table('storage_targets')->where('id', $id)->first();
        if ($target === null || $target->verified_at === null) {
            throw ValidationException::withMessages(['target' => 'Test the connection successfully before activating.']);
        }
        $source = app(CopyFiles::class)->currentSource();
        if ((bool) $target->active) {
            return;
        }
        // Files stay where they are when storage is switched: they must be copied and checked first.
        if ($target->copy_status !== 'done' || $target->copy_source !== $source) {
            throw ValidationException::withMessages(['target' => $target->copy_status === 'failed'
                ? 'Some files could not be copied. Copy the files again; activate once the copy has no failures.'
                : 'Copy the existing files into this storage first (Copy files), then activate.']);
        }
        $this->db()->transaction(function () use ($id): void {
            $this->db()->table('storage_targets')->update(['active' => false]);
            $this->db()->table('storage_targets')->where('id', $id)->update(['active' => true, 'updated_at' => now()]);
        });
        FileStore::forget();
        activity('platform')->withProperties(['storage_target' => $id, 'from' => $source])->log('File storage switched');
        // Catch files uploaded between the copy and now (the copy skips files already there).
        CopyFilesToStorage::dispatch($id, $source);
    }
}
