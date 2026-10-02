<?php

use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

/*
 * Database-per-provider tenancy needs a real MySQL server (CI provides one).
 * CREATE DATABASE commits implicitly, so these tests use DatabaseMigrations
 * rather than transactions.
 */
uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
});

afterEach(function (): void {
    tenancy()->end();

    Provider::query()->get()->each->delete();
});

function databaseExists(string $name): bool
{
    return DB::connection(config('tenancy.database.central_connection'))
        ->table('information_schema.schemata')
        ->where('schema_name', $name)
        ->exists();
}

it('creates and migrates a dedicated database for a new provider', function (): void {
    $provider = Provider::create([
        'name' => 'Sunrise Medical Centre',
        'type' => ProviderType::Clinic,
        'status' => ProviderStatus::Trial,
    ]);

    $database = $provider->database()->getName();

    expect($database)->toStartWith(config('tenancy.database.prefix'))
        ->and(databaseExists($database))->toBeTrue();

    tenancy()->initialize($provider);

    expect(Schema::hasTable('settings'))->toBeTrue()
        ->and(DB::connection()->getDatabaseName())->toBe($database);

    tenancy()->end();
});

it('keeps each provider in its own database', function (): void {
    $clinic = Provider::create(['name' => 'Sunrise Medical Centre', 'type' => ProviderType::Clinic, 'status' => ProviderStatus::Trial]);
    $pharmacy = Provider::create(['name' => 'Vilakazi Pharmacy', 'type' => ProviderType::Pharmacy, 'status' => ProviderStatus::Trial]);

    tenancy()->initialize($clinic);
    DB::table('settings')->insert(['group' => 'billing', 'key' => 'payment_timing', 'value' => json_encode('check_in')]);
    tenancy()->end();

    tenancy()->initialize($pharmacy);
    expect(DB::table('settings')->count())->toBe(0);
    tenancy()->end();

    expect($clinic->database()->getName())->not->toBe($pharmacy->database()->getName());
});

it('drops the provider database when the provider is deleted', function (): void {
    $provider = Provider::create(['name' => 'Short-lived Lab', 'type' => ProviderType::Lab, 'status' => ProviderStatus::Trial]);
    $database = $provider->database()->getName();

    $provider->delete();

    expect(databaseExists($database))->toBeFalse();
});

it('serves a provider page on the provider domain only', function (): void {
    $provider = Provider::create(['name' => 'Sunrise Medical Centre', 'type' => ProviderType::Clinic, 'status' => ProviderStatus::Trial]);
    $provider->domains()->create(['domain' => 'sunrise.clinicflow.test']);

    $this->withoutVite()
        ->get('http://sunrise.clinicflow.test/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Provider/Home')
            ->where('provider.name', 'Sunrise Medical Centre')
            ->where('provider.type', 'clinic'));
});
