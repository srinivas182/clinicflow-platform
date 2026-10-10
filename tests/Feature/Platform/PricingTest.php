<?php

use App\Domains\Platform\Models\Package;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('shows only active packages on the public pricing page', function (): void {
    $this->seed(PackageSeeder::class);
    Package::query()->where('code', 'clinic-pro')->update(['is_active' => false]);

    $this->get('http://localhost/pricing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Public/Pricing')
            // Free packages are listed first; the paid ones follow.
            ->has('packages', 10)
            ->where('packages.0.code', 'clinic-free')
            ->where('packages.0.priceMonthly', 0)
            ->where('packages.4.code', 'clinic-starter')
            ->where('packages.4.priceMonthly', 1490));
});

it('keeps admin pages for platform admins only', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('http://localhost/admin/providers')->assertForbidden();
});
