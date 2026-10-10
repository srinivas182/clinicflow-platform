<?php

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->seed(PackageSeeder::class);
    $this->seed(ClinicalReferenceSeeder::class);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('creates demo practices on the free packages with an account for every role, and removes them again', function (): void {
    $real = User::factory()->create(['email' => 'someone@drbusinessflow.com']);
    $this->artisan('clinicflow:demo', ['--email' => 'srini@example.com'])->expectsOutputToContain('Password for every demo account')->assertSuccessful();

    $providers = Provider::query()->where('data->demo', true)->get();
    expect($providers)->toHaveCount(3);
    $roles = count(StaffRole::forProviderType(ProviderType::Clinic)) + count(StaffRole::forProviderType(ProviderType::Pharmacy)) + count(StaffRole::forProviderType(ProviderType::Lab));
    expect(User::query()->where('email', 'like', 'srini+demo-%@example.com')->count())->toBe($roles)
        ->and(User::query()->where('email', 'srini+demo-clinic-doctor@example.com')->exists())->toBeTrue()
        ->and(DB::table('subscriptions')->join('packages', 'packages.id', '=', 'subscriptions.package_id')->whereIn('subscriptions.tenant_id', $providers->pluck('id'))->pluck('packages.code')->sort()->values()->all())
        ->toBe(['clinic-free', 'lab-free', 'pharmacy-free']);
    $clinic = $providers->firstWhere('type', ProviderType::Clinic);
    $clinic->run(fn () => expect(DB::table('patients')->count())->toBe(6));

    $this->artisan('clinicflow:demo', ['--email' => 'srini@example.com'])->expectsOutputToContain('already exist')->assertFailed();

    $this->artisan('clinicflow:demo', ['--remove' => true])->expectsOutputToContain('Removed 3 demo practice(s)')->assertSuccessful();
    expect(Provider::query()->where('data->demo', true)->count())->toBe(0)
        ->and(User::query()->where('email', 'like', '%+demo-%')->count())->toBe(0)
        ->and(User::query()->whereKey($real->id)->exists())->toBeTrue();
});

it('lists free packages first, with no trial', function (): void {
    expect(DB::table('packages')->orderBy('sort_order')->limit(4)->pluck('code')->all())->toBe(['clinic-free', 'doctor-free', 'pharmacy-free', 'lab-free'])
        ->and(DB::table('packages')->where('price_monthly_cents', 0)->max('trial_days'))->toBe(0);
});
