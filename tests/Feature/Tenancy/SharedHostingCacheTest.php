<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    // Shared cPanel hosting: database cache (no tags, no Redis).
    config(['cache.default' => 'database']);
    $this->a = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->b = makeProvider('Northside Clinic', ProviderType::Clinic, 'northside.clinicflow.test');
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('keeps the database cache, sessions and queue in the platform database', function (): void {
    expect(config('cache.stores.database.connection'))->toBe('mysql')
        ->and(config('session.connection'))->toBe('mysql')
        ->and(config('queue.connections.database.connection'))->toBe('mysql');
    // Inside a practice the cache still works (its own database has no cache table).
    $this->a->run(function (): void {
        Cache::put('probe', 'ok', 60);
        expect(Cache::get('probe'))->toBe('ok');
    });
});

it('keeps each practice\'s cache separate without cache tags', function (): void {
    $this->a->run(fn () => Cache::put('shared-key', 'A', 60));
    $this->b->run(function (): void {
        expect(Cache::get('shared-key'))->toBeNull();
        Cache::put('shared-key', 'B', 60);
    });
    $this->a->run(fn () => expect(Cache::get('shared-key'))->toBe('A'));
    expect(Cache::get('shared-key'))->toBeNull();
});

it('adds staff inside a practice with the database cache (the failing demo step)', function (): void {
    $user = User::factory()->create();
    app(AddStaffMember::class)->handle($this->a, $user, StaffRole::Doctor);
    expect(Membership::query()->where('user_id', $user->id)->exists())->toBeTrue();
});
