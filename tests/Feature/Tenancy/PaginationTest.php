<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->owner = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('pages the audit log 50 at a time and looks up who acted once per page', function (): void {
    $people = User::factory()->count(12)->create();
    $this->clinic->run(function () use ($people): void {
        foreach (range(1, 60) as $i) {
            activity('compliance')->causedBy($people[$i % 12])->log("Entry {$i}");
        }
    });

    // Adding staff is audited too, so count the real total rather than assuming 60.
    $total = $this->clinic->run(fn () => DB::table('activity_log')->count());
    expect($total)->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(100);
    $this->actingAs($this->owner)->get('http://sunrise.clinicflow.test/compliance/audit')->assertOk()->assertInertia(fn ($p) => $p->component('Compliance/Audit')
        ->has('entries.data', 50)->where('entries.total', $total)->where('entries.last_page', 2));
    $this->actingAs($this->owner)->get('http://sunrise.clinicflow.test/compliance/audit?page=2')->assertOk()->assertInertia(fn ($p) => $p->has('entries.data', $total - 50));

    DB::enableQueryLog();
    $this->actingAs($this->owner)->get('http://sunrise.clinicflow.test/compliance/audit');
    $userLookups = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `users`') && str_contains($q['query'], ' in ('))->count();
    expect($userLookups)->toBeLessThanOrEqual(2);
});
