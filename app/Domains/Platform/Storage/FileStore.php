<?php

declare(strict_types=1);

namespace App\Domains\Platform\Storage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "files" disk: wherever the super admin's active storage target is.
 * Local disk keeps the existing folders; S3 and S3-compatible targets keep each
 * practice's files under its own folder prefix (done by the tenancy filesystem bootstrapper).
 */
final class FileStore
{
    public const DISK = 'files';

    public const CACHE_KEY = 'storage-target:active';

    /** S3-compatible providers and how their endpoint is formed (shown as guidance; the super admin enters the endpoint). */
    public const PROVIDERS = [
        'aws' => 'Amazon S3',
        'gcs' => 'Google Cloud Storage (S3-compatible access)',
        'minio' => 'MinIO (own servers)',
        'r2' => 'Cloudflare R2',
        'wasabi' => 'Wasabi',
        'digitalocean' => 'DigitalOcean Spaces',
        'backblaze' => 'Backblaze B2 (S3 API)',
        'other' => 'Other S3-compatible',
    ];

    /** Points the "files" disk at the active storage target. Called at boot; safe before migrations exist. */
    public static function configure(): void
    {
        try {
            $target = Cache::rememberForever(self::CACHE_KEY, function (): ?array {
                $central = DB::connection((string) config('tenancy.database.central_connection'));
                if (! Schema::connection($central->getName())->hasTable('storage_targets')) {
                    return null;
                }
                $row = $central->table('storage_targets')->where('active', true)->first();

                return $row === null ? null : (array) $row;
            });
        } catch (\Throwable) {
            return; // database unavailable (e.g. during install): keep the local default
        }
        if ($target === null) {
            return;
        }
        $config = self::diskConfig($target);
        config(['filesystems.disks.'.self::DISK => $config]);
        if ($config['driver'] !== 'local') {
            config(['tenancy.filesystem.root_override.'.self::DISK => null]);
        }
    }

    /**
     * Laravel disk configuration for a storage target row.
     *
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    public static function diskConfig(array $target): array
    {
        if (($target['driver'] ?? 'local') === 'local') {
            return ['driver' => 'local', 'root' => (string) ($target['root'] ?: storage_path('app/private')), 'serve' => false, 'throw' => true];
        }
        $creds = $target['credentials'] === null ? [] : (array) json_decode(Crypt::decryptString((string) $target['credentials']), true);

        return array_filter([
            'driver' => 's3', 'key' => $creds['key'] ?? null, 'secret' => $creds['secret'] ?? null,
            'region' => $target['region'] ?: 'auto', 'bucket' => $target['bucket'], 'endpoint' => $target['endpoint'] ?: null,
            'use_path_style_endpoint' => (bool) $target['path_style'], 'root' => (string) ($target['root'] ?: 'clinicflow'),
            'visibility' => 'private', 'throw' => true,
            'options' => (bool) $target['encrypt'] ? ['ServerSideEncryption' => 'AES256'] : null,
        ], fn ($v) => $v !== null);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
