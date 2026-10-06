<?php

use App\Domains\Platform\Storage\FileStore;
use App\Domains\Platform\Storage\StorageTargets;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Requires MySQL.');
    }
    $this->targets = app(StorageTargets::class);
    $this->s3 = fn (array $o = []) => array_merge(['name' => 'AWS Cape Town', 'driver' => 's3', 'provider' => 'aws', 'bucket' => 'clinicflow-files', 'region' => 'af-south-1',
        'endpoint' => null, 'path_style' => false, 'encrypt' => true, 'root' => 'clinicflow', 'key' => 'AKIAEXAMPLE', 'secret' => 'secret-example'], $o);
});

afterEach(function (): void {
    FileStore::forget();
});

it('validates S3 settings', function (array $bad, string $field): void {
    try {
        $this->targets->save(null, ($this->s3)($bad));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'bucket' => [['bucket' => 'Bad_Bucket!'], 'bucket'],
    'insecure endpoint' => [['provider' => 'minio', 'endpoint' => 'http://minio.local:9000'], 'endpoint'],
    'aws region' => [['region' => 'cape town'], 'region'],
    'keys' => [['key' => null], 'key'],
    'folder' => [['root' => '../etc'], 'root'],
    'absolute folder' => [['root' => '/var/www'], 'root'],
]);

it('builds the S3 disk with encrypted keys, endpoint, path style and encryption at rest', function (): void {
    $id = $this->targets->save(null, ($this->s3)(['provider' => 'minio', 'endpoint' => 'https://files.example.co.za', 'path_style' => true, 'region' => 'us-east-1']));
    $row = (array) DB::table('storage_targets')->find($id);
    expect($row['credentials'])->not->toContain('secret-example');

    $config = FileStore::diskConfig($row);
    expect($config)->toMatchArray(['driver' => 's3', 'key' => 'AKIAEXAMPLE', 'secret' => 'secret-example', 'bucket' => 'clinicflow-files',
        'endpoint' => 'https://files.example.co.za', 'use_path_style_endpoint' => true, 'root' => 'clinicflow', 'visibility' => 'private'])
        ->and($config['options'])->toBe(['ServerSideEncryption' => 'AES256']);
});

it('only activates a target whose connection test passed, then points the files disk at it with per-practice folders', function (): void {
    $id = $this->targets->save(null, ($this->s3)(['provider' => 'minio', 'endpoint' => 'https://127.0.0.1:1', 'path_style' => true, 'region' => 'us-east-1']));
    expect(fn () => $this->targets->activate($id))->toThrow(ValidationException::class);

    expect($this->targets->test($id))->toBeFalse()
        ->and(DB::table('storage_targets')->where('id', $id)->value('last_error'))->not->toBeEmpty();

    $local = $this->targets->save(null, ['name' => 'Server disk', 'driver' => 'local', 'provider' => null, 'bucket' => null, 'region' => null, 'endpoint' => null,
        'path_style' => false, 'encrypt' => false, 'root' => null, 'key' => null, 'secret' => null]);
    expect($this->targets->test($local))->toBeTrue();
    $this->targets->activate($local);
    expect(Storage::disk('local')->allFiles('_clinicflow-probe'))->toBe([]);

    // Simulate a verified S3 target and check the files disk switches to it (no network needed).
    DB::table('storage_targets')->where('id', $id)->update(['verified_at' => now()]);
    $this->targets->activate($id);
    FileStore::configure();
    expect(config('filesystems.disks.files.driver'))->toBe('s3')
        ->and(config('tenancy.filesystem.root_override.files'))->toBeNull()
        ->and(DB::table('storage_targets')->where('active', true)->pluck('id')->all())->toBe([$id]);
});

it('is for the super admin only', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('http://localhost/admin/storage')->assertForbidden();
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->actingAs($admin)->get('http://localhost/admin/storage')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Storage')->where('current', 'local'));
});
