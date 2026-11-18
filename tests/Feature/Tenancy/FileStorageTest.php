<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Jobs\CopyFilesToStorage;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Storage\CopyFiles;
use App\Domains\Platform\Storage\FileStore;
use App\Domains\Platform\Storage\StorageTargets;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
    // Tested is not enough: existing files must be copied and checked first (beta #179).
    expect(fn () => $this->targets->activate($local))->toThrow(ValidationException::class, 'Copy the existing files');
    $old = Storage::build(['driver' => 'local', 'root' => storage_path('app/private')]);
    $old->put('beta-179/lab-report.txt', 'existing file');
    $this->targets->startCopy($local);
    expect(DB::table('storage_targets')->where('id', $local)->value('copy_status'))->toBe('done')
        ->and(file_get_contents($root.'/beta-179/lab-report.txt'))->toBe('existing file');
    $this->targets->activate($local);
    $old->deleteDirectory('beta-179');
    FileStore::configure();
    Storage::forgetDisk(FileStore::DISK);
    Storage::disk(FileStore::DISK)->put('hello.txt', 'hi');
    expect(file_get_contents($root.'/hello.txt'))->toBe('hi');

    $broken = $this->targets->save(null, s3Target(['provider' => 'other', 'region' => 'za', 'endpoint' => 'https://127.0.0.1:1']));
    expect($this->targets->test($broken))->toBeFalse()
        ->and(DB::table('storage_targets')->where('id', $broken)->value('last_error'))->not->toBeNull();
});

it('keeps each practice in its own folder on S3-compatible storage', function (): void {
    Queue::fake();
    $id = $this->targets->save(null, s3Target());
    DB::table('storage_targets')->where('id', $id)->update(['verified_at' => now(), 'copy_status' => 'done', 'copy_source' => 'default']);
    $this->targets->activate($id);
    FileStore::configure();
    expect(config('filesystems.disks.files.driver'))->toBe('s3')->and(config('tenancy.filesystem.root_override.files'))->toBeNull();

    $clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    tenancy()->initialize($clinic);
    expect(config('filesystems.disks.files.root'))->toBe('clinicflow/'.config('tenancy.filesystem.suffix_base').$clinic->id);
    tenancy()->end();
    expect(config('filesystems.disks.files.root'))->toBe('clinicflow');

    $this->artisan('storage:copy-to-active', ['target' => 999])->assertFailed();
    Queue::assertPushed(CopyFilesToStorage::class, fn (CopyFilesToStorage $job) => $job->targetId === $id && $job->source === 'default');
});

it('copies every practice\'s files, refuses to activate after a failed or outdated copy, and guards the storage in use (beta #179, #180)', function (): void {
    $clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $local = fn (string $root) => $this->targets->save(null, ['name' => 'Disk '.basename($root), 'driver' => 'local', 'provider' => null, 'bucket' => null, 'region' => null, 'endpoint' => null,
        'path_style' => false, 'encrypt' => false, 'root' => $root, 'key' => null, 'secret' => null]);
    $rootA = sys_get_temp_dir().'/cf-a-'.uniqid();
    $a = $local($rootA);
    $this->targets->test($a);
    $this->targets->startCopy($a);
    $this->targets->activate($a);

    // Files now live in A; a target copied earlier from another place must copy again.
    $b = $local(sys_get_temp_dir().'/cf-b-'.uniqid());
    $this->targets->test($b);
    DB::table('storage_targets')->where('id', $b)->update(['copy_status' => 'done', 'copy_source' => 'default']);
    expect(fn () => $this->targets->activate($b))->toThrow(ValidationException::class, 'Copy the existing files');
    DB::table('storage_targets')->where('id', $b)->update(['copy_status' => 'failed', 'copy_source' => (string) $a]);
    expect(fn () => $this->targets->activate($b))->toThrow(ValidationException::class, 'could not be copied');

    // Practice files are copied into the practice's folder on S3-style targets.
    Storage::build(['driver' => 'local', 'root' => storage_path(config('tenancy.filesystem.suffix_base').$clinic->id.'/app')])->put('documents/referral.pdf', 'pdf');
    $s3Locations = CopyFiles::locations(['driver' => 's3', 'credentials' => null, 'region' => 'af-south-1', 'bucket' => 'b', 'endpoint' => null, 'path_style' => false, 'root' => 'clinicflow', 'encrypt' => true]);
    expect(collect($s3Locations)->firstWhere('label', 'Sunrise Medical Centre')['config']['root'])->toBe('clinicflow/'.config('tenancy.filesystem.suffix_base').$clinic->id);
    Storage::build(['driver' => 'local', 'root' => storage_path()])->deleteDirectory(config('tenancy.filesystem.suffix_base').$clinic->id);

    // The storage in use: its location cannot change, and only keys that pass the test are saved (beta #180).
    $row = fn () => (array) DB::table('storage_targets')->find($a);
    $same = ['name' => 'Main disk', 'driver' => 'local', 'provider' => null, 'bucket' => null, 'region' => null, 'endpoint' => null, 'path_style' => false, 'encrypt' => false, 'root' => $rootA, 'key' => null, 'secret' => null];
    expect(fn () => $this->targets->save($a, ['root' => sys_get_temp_dir().'/cf-moved'] + $same))->toThrow(ValidationException::class, 'only its name and keys');
    $this->targets->save($a, $same);
    expect($row()['name'])->toBe('Main disk')->and($row()['verified_at'])->not->toBeNull()->and((bool) $row()['active'])->toBeTrue();

    $s3 = $this->targets->save(null, s3Target(['provider' => 'other', 'region' => 'za', 'endpoint' => 'https://127.0.0.1:1']));
    DB::table('storage_targets')->update(['active' => false]);
    DB::table('storage_targets')->where('id', $s3)->update(['active' => true, 'verified_at' => now()]);
    $keysBefore = DB::table('storage_targets')->where('id', $s3)->value('credentials');
    expect(fn () => $this->targets->save($s3, s3Target(['provider' => 'other', 'region' => 'za', 'endpoint' => 'https://127.0.0.1:1', 'key' => 'NEWKEY', 'secret' => 'new-secret'])))
        ->toThrow(ValidationException::class, 'current keys are still in use');
    expect(DB::table('storage_targets')->where('id', $s3)->value('credentials'))->toBe($keysBefore);
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
