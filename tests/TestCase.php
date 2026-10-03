<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;

abstract class TestCase extends BaseTestCase
{
    /** @var array<string, bool> databases already created by this process */
    private static array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests render Inertia pages without a built Vite manifest.
        $this->withoutVite();
    }

    /**
     * When tests run in parallel (pest --parallel), each process gets its own
     * platform database, Network Hub database and provider-database prefix, so
     * processes never share data. This runs before migrations (setUpTraits).
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        if (! is_string($token) || $token === '' || config('database.default') !== 'mysql') {
            return;
        }

        $central = config('database.connections.mysql.database').'_p'.$token;
        $hub = config('database.connections.hub.database').'_p'.$token;
        $this->createDatabases([$central, $hub]);

        config([
            'database.connections.mysql.database' => $central,
            'database.connections.hub.database' => $hub,
            'tenancy.database.prefix' => config('tenancy.database.prefix').'p'.$token.'_',
        ]);
        $this->app['db']->purge('mysql');
        $this->app['db']->purge('hub');
    }

    /**
     * @param  list<string>  $names
     */
    private function createDatabases(array $names): void
    {
        $missing = array_filter($names, fn (string $n) => ! isset(self::$created[$n]));
        if ($missing === []) {
            return;
        }
        $c = config('database.connections.mysql');
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']}", (string) $c['username'], (string) $c['password']);
        foreach ($missing as $name) {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.str_replace('`', '', $name).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            self::$created[$name] = true;
        }
    }
}
