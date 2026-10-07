<?php

use App\Domains\Platform\Actions\ProviderGroups;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\ProviderGroup;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('has indexes for report, analytics and retention queries', function (): void {
    $this->clinic->run(function (): void {
        foreach ([['invoices', ['created_at']], ['invoices', ['status', 'created_at']], ['payments', ['status', 'created_at']], ['appointments', ['starts_at', 'status']],
            ['visits', ['doctor_id', 'visit_date']], ['message_log', ['sent_at']], ['message_log', ['related_type', 'related_id']], ['record_views', ['created_at']]] as [$table, $columns]) {
            expect(Schema::hasIndex($table, $columns))->toBeTrue("{$table}(".implode(',', $columns).')');
        }
    });
    expect(Schema::hasIndex('login_events', ['created_at']))->toBeTrue()->and(Schema::hasIndex('login_challenges', ['created_at']))->toBeTrue();
});

it('caches the group dashboard for five minutes', function (): void {
    $group = ProviderGroup::query()->create(['name' => 'Sunrise Group', 'billing' => 'separate']);
    app(ProviderGroups::class)->addMember($group, $this->clinic);
    $first = app(ProviderGroups::class)->dashboard($group->fresh(), now()->startOfMonth()->toDateString(), now()->toDateString());
    $this->clinic->run(fn () => registerTestPatient('Thandi', '880412'));
    $cached = app(ProviderGroups::class)->dashboard($group->fresh(), now()->startOfMonth()->toDateString(), now()->toDateString());
    expect($cached)->toBe($first);
    $this->travel(6)->minutes();
    $fresh = app(ProviderGroups::class)->dashboard($group->fresh(), now()->startOfMonth()->toDateString(), now()->toDateString());
    expect($fresh[0]['new_patients'])->toBe($first[0]['new_patients'] + 1);
});
