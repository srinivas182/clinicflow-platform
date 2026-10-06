<?php

use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Platform\Storage\FileStore;
use App\Domains\Platform\Storage\StorageTargets;
use App\Domains\Wallet\Support\WalletSettings;
use App\Octane\RefreshFileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
});

afterEach(function (): void {
    tenancy()->end();
    FileStore::forget();
    Provider::query()->get()->each->delete();
});

it('caches practice settings per practice and shows changes immediately', function (): void {
    $a = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $b = makeProvider('Northside Clinic', ProviderType::Clinic, 'northside.clinicflow.test');
    $a->run(fn () => Setting::put('messaging', 'from_name', 'Sunrise'));
    $b->run(fn () => Setting::put('messaging', 'from_name', 'Northside'));

    $a->run(function (): void {
        expect(Setting::get('messaging', 'from_name'))->toBe('Sunrise');
        DB::enableQueryLog();
        expect(Setting::get('messaging', 'from_name'))->toBe('Sunrise')->and(Setting::get('messaging', 'missing', 'fallback'))->toBe('fallback');
        $first = count(DB::getQueryLog());
        expect(Setting::get('messaging', 'missing', 'fallback'))->toBe('fallback')->and(count(DB::getQueryLog()))->toBe($first);
        Setting::put('messaging', 'from_name', 'Sunrise Clinic');
        expect(Setting::get('messaging', 'from_name'))->toBe('Sunrise Clinic');
    });
    $b->run(fn () => expect(Setting::get('messaging', 'from_name'))->toBe('Northside'));
});

it('caches platform prices and clears them when changed', function (): void {
    expect(WalletSettings::get('ai.price_per_minute_cents'))->toBe(150);
    WalletSettings::put('ai.price_per_minute_cents', 175);
    expect(WalletSettings::get('ai.price_per_minute_cents'))->toBe(175);
    DB::connection((string) config('tenancy.database.central_connection'))->enableQueryLog();
    WalletSettings::get('ai.price_per_minute_cents');
    expect(DB::connection((string) config('tenancy.database.central_connection'))->getQueryLog())->toBe([]);
});

it('applies a storage switch on the next request under Octane, without a restart', function (): void {
    $root = sys_get_temp_dir().'/cf-octane-'.uniqid();
    $targets = app(StorageTargets::class);
    $id = $targets->save(null, ['name' => 'Second disk', 'driver' => 'local', 'provider' => null, 'bucket' => null, 'region' => null, 'endpoint' => null,
        'path_style' => false, 'encrypt' => true, 'root' => $root, 'key' => null, 'secret' => null]);
    expect($targets->test($id))->toBeTrue();
    $targets->activate($id);
    expect(config('filesystems.disks.files.root'))->not->toBe($root);

    (new RefreshFileStorage)->handle(new stdClass);
    expect(config('filesystems.disks.files.root'))->toBe($root);
});

it('logs N+1 queries in development instead of failing', function (): void {
    expect(Model::preventsLazyLoading())->toBeTrue();
});
