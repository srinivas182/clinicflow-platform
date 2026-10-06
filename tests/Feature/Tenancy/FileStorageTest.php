<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
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
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->targets = app(StorageTargets::class);
    FileStore::forget();
});

afterEach(function (): void {
    tenancy()->end();
    FileStore::forget();
    Provider::query()->get()->each->delete();
});

function s3Target(array $o = []): array
{
    return array_merge(['name' => 'AWS Cape Town', 'driver' => 's3', 'provider' => 'aws', 'bucket' => 'clinicflow-files', 'region' => 'af-south-1', 'endpoint' => null,
        'path_style' => false, 'encrypt' => true, 'root' => null, 'key' => 'AKIATEST', 'secret' => 'secret-test'], $o);
}

it('validates storage targets and builds S3 settings without exposing keys', function (): void {
    foreach ([['bucket' => 'X'], ['endpoint' => 'http://minio.local'], ['region' => 'cape-town'], ['key' => null]] as $bad) {
        expect(fn () => $this->targets->save(null, s3Target($bad)))->toThrow(ValidationException::class);
    }
    $id = $this->targets->save(null, s3Target(['provider' => 'minio', 'region' => 'za', 'endpoint' => 'https://files.example.test', 'path_style' => true]));
    $row = (array) DB::table('storage_targets')->find($id);
    expect($row['credentials'])->not->toContain('secret-test');
    $config = FileStore::diskConfig($row);
    expect($config)->toMatchArray(['driver' => 's3', 'key' => 'AKIATEST', 'secret' => 'secret-test', 'bucket' => 'clinicflow-files', 'endpoint' => 'https://files.example.test',
        'use_path_style_endpoint' => true, 'root' => 'clinicflow', 'visibility' => 'private'])
        ->and($config['options'])->toBe(['ServerSideEncryption' => 'AES256']);
});

it('activates only targets that pass a connection test, and a broken target fails it', function (): void {
    foreach ([base_path('public/files'), base_path('app'), '../etc', 'relative/path'] as $unsafe) {
        expect(fn () => $this->targets->save(null, ['name' => 'Bad', 'driver' => 'local', 'provider' => null, 'bucket' => null, 'region' => null, 'endpoint' => null,
            'path_style' => false, 'encrypt' => true, 'root' => $unsafe, 'key' => null, 'secret' => null]))->toThrow(ValidationException::class);
    }
    $root = sys_get_temp_dir().'/cf-files-'.uniqid();
    $local = $this->targets->save(null, ['name' => 'Second disk', 'driver' => 'local', 'provider' => null, 'bucket' => null, 'region' => null, 'endpoint' => null,
        'path_style' => false, 'encrypt' => true, 'root' => $root, 'key' => null, 'secret' => null]);
    expect(fn () => $this->targets->activate($local))->toThrow(ValidationException::class);
    expect($this->targets->test($local))->toBeTrue()
        ->and(glob($root.'/_clinicflow-probe/*'))->toBe([]);
    $this->targets->activate($local);
    FileStore::configure();
    Storage::forgetDisk(FileStore::DISK);
    Storage::disk(FileStore::DISK)->put('hello.txt', 'hi');
    expect(file_get_contents($root.'/hello.txt'))->toBe('hi');

    $broken = $this->targets->save(null, s3Target(['provider' => 'other', 'region' => 'za', 'endpoint' => 'https://127.0.0.1:1']));
    expect($this->targets->test($broken))->toBeFalse()
        ->and(DB::table('storage_targets')->where('id', $broken)->value('last_error'))->not->toBeNull();
});

it('keeps each practice in its own folder on S3-compatible storage', function (): void {
    $id = $this->targets->save(null, s3Target());
    DB::table('storage_targets')->where('id', $id)->update(['verified_at' => now()]);
    $this->targets->activate($id);
    FileStore::configure();
    expect(config('filesystems.disks.files.driver'))->toBe('s3')->and(config('tenancy.filesystem.root_override.files'))->toBeNull();

    $clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    tenancy()->initialize($clinic);
    expect(config('filesystems.disks.files.root'))->toBe('clinicflow/'.config('tenancy.filesystem.suffix_base').$clinic->id);
    tenancy()->end();
    expect(config('filesystems.disks.files.root'))->toBe('clinicflow');

    $this->artisan('storage:copy-to-active', ['target' => 999])->assertFailed();
});

it('lets only the super admin manage storage', function (): void {
    $owner = User::factory()->create();
    $clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    app(AddStaffMember::class)->handle($clinic, $owner, StaffRole::Owner);
    $this->actingAs($owner)->get('http://localhost/admin/storage')->assertForbidden();
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->actingAs($admin)->post('http://localhost/admin/storage', s3Target())->assertSessionHasNoErrors();
    $this->actingAs($admin)->get('http://localhost/admin/storage')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Storage')->where('targets.0.hasKeys', true)->missing('targets.0.secret'));
});
