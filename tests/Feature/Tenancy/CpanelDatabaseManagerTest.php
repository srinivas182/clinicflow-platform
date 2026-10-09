<?php

use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Tenancy\CpanelDatabaseManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['clinicflow.cpanel' => ['host' => 'reseller148.aserv.co.za', 'port' => 2083, 'user' => 'drbusinessflow', 'token' => 'TESTTOKEN', 'db_user' => 'drbusinessflow_app'],
        'tenancy.database.prefix' => 'drbusinessflow_cf_']);
    $this->tenant = new Provider(['id' => '01k9practice0000000000000a']);
});

it('creates a practice database and grants our database user through cPanel', function (): void {
    Http::fake(['reseller148.aserv.co.za:2083/execute/*' => Http::response(['status' => 1, 'errors' => null, 'data' => null])]);
    expect(app(CpanelDatabaseManager::class)->createDatabase($this->tenant))->toBeTrue();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute/Mysql/create_database') && $r['name'] === 'drbusinessflow_cf_01k9practice0000000000000a'
        && $r->header('Authorization') === ['cpanel drbusinessflow:TESTTOKEN']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute/Mysql/set_privileges_on_database') && $r['user'] === 'drbusinessflow_app'
        && $r['database'] === 'drbusinessflow_cf_01k9practice0000000000000a' && $r['privileges'] === 'ALL PRIVILEGES');
});

it('deletes a practice database through cPanel', function (): void {
    Http::fake(['*' => Http::response(['status' => 1])]);
    app(CpanelDatabaseManager::class)->deleteDatabase($this->tenant);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/execute/Mysql/delete_database') && $r['name'] === 'drbusinessflow_cf_01k9practice0000000000000a');
});

it('stops with cPanel\'s reason when a call fails, and when settings are missing', function (): void {
    Http::fake(['*' => Http::response(['status' => 0, 'errors' => ['The database name is too long.']])]);
    expect(fn () => app(CpanelDatabaseManager::class)->createDatabase($this->tenant))
        ->toThrow(RuntimeException::class, 'cPanel Mysql/create_database failed: The database name is too long.');

    config(['clinicflow.cpanel.token' => '']);
    expect(fn () => app(CpanelDatabaseManager::class)->createDatabase($this->tenant))->toThrow(RuntimeException::class, 'cPanel database settings are missing');
});
