<?php

declare(strict_types=1);

namespace App\Domains\Platform\Storage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Super admin: storage targets. A target must pass a connection test (write, read, delete)
 * before it can be activated; activating one deactivates the others.
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
        if ($data['root'] !== null && $data['root'] !== '' && (preg_match('#^[A-Za-z0-9_\-./]{1,120}$#', (string) $data['root']) !== 1 || str_contains((string) $data['root'], '..') || str_starts_with((string) $data['root'], '/'))) {
            $errors['root'] = 'Use letters, numbers, dashes and slashes only.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $row = ['name' => trim($data['name']), 'driver' => $data['driver'], 'provider' => $data['driver'] === 's3' ? $data['provider'] : null,
            'bucket' => $data['bucket'], 'region' => $data['region'], 'endpoint' => $data['endpoint'] ?: null, 'path_style' => $data['path_style'],
            'encrypt' => $data['encrypt'], 'root' => $data['root'] ?: null, 'verified_at' => null, 'updated_at' => now()];
        if (filled($data['key']) && filled($data['secret'])) {
            $row['credentials'] = Crypt::encryptString((string) json_encode(['key' => $data['key'], 'secret' => $data['secret']]));
        }
        if ($id === null) {
            return (int) $this->db()->table('storage_targets')->insertGetId($row + ['active' => false, 'created_at' => now()]);
        }
        // Changing an active target's settings takes effect only after a new test and activation.
        $this->db()->table('storage_targets')->where('id', $id)->update($row);

        return $id;
    }

    /** Writes, reads back and deletes a probe file. */
    public function test(int $id): bool
    {
        $target = (array) ($this->db()->table('storage_targets')->where('id', $id)->first() ?? []);
        if ($target === []) {
            throw ValidationException::withMessages(['target' => 'Storage target not found.']);
        }
        $probe = '_clinicflow-probe/'.Str::random(16).'.txt';
        $body = 'probe '.now()->toIso8601String();
        try {
            $disk = Storage::build(FileStore::diskConfig($target));
            $disk->put($probe, $body);
            $ok = $disk->get($probe) === $body;
            $disk->delete($probe);
            $error = $ok ? null : 'The file read back did not match.';
        } catch (\Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 250);
        }
        $this->db()->table('storage_targets')->where('id', $id)->update(['verified_at' => $ok ? now() : null, 'last_error' => $error, 'updated_at' => now()]);

        return $ok;
    }

    public function activate(int $id): void
    {
        $target = $this->db()->table('storage_targets')->where('id', $id)->first();
        if ($target === null || $target->verified_at === null) {
            throw ValidationException::withMessages(['target' => 'Test the connection successfully before activating.']);
        }
        $this->db()->transaction(function () use ($id): void {
            $this->db()->table('storage_targets')->update(['active' => false]);
            $this->db()->table('storage_targets')->where('id', $id)->update(['active' => true, 'updated_at' => now()]);
        });
        FileStore::forget();
        activity('platform')->withProperties(['storage_target' => $id])->log('File storage switched');
    }
}
