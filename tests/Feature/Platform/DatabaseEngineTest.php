<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

it('creates every table as InnoDB, whatever the server default', function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('MySQL/MariaDB only.');
    }
    expect(config('database.connections.mysql.engine'))->toBe('InnoDB');
    DB::statement("SET SESSION default_storage_engine = 'MyISAM'");
    Schema::create('engine_probe', fn ($t) => $t->string('email')->primary());
    $engine = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'engine_probe')->value('ENGINE');
    Schema::drop('engine_probe');
    expect($engine)->toBe('InnoDB');
});
