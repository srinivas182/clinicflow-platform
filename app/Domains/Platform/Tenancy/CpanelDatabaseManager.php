<?php

declare(strict_types=1);

namespace App\Domains\Platform\Tenancy;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;

/**
 * Practice databases on cPanel hosting. Shared cPanel accounts may not run CREATE DATABASE, so the
 * database is created (and our database user given access) through cPanel's UAPI with an API token.
 * Everything else (connections, existence checks) is the standard MySQL/MariaDB manager.
 *
 * Settings: CPANEL_HOST, CPANEL_USER, CPANEL_API_TOKEN, CPANEL_DB_USER; practice database names must
 * start with the cPanel account prefix (TENANT_DB_PREFIX, e.g. "drbusinessflow_cf_").
 */
class CpanelDatabaseManager extends MySQLDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $name = (string) $tenant->database()->getName();
        $this->call('Mysql/create_database', ['name' => $name]);
        $this->call('Mysql/set_privileges_on_database', ['user' => (string) config('clinicflow.cpanel.db_user'), 'database' => $name, 'privileges' => 'ALL PRIVILEGES']);

        return true;
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $this->call('Mysql/delete_database', ['name' => (string) $tenant->database()->getName()]);

        return true;
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function call(string $function, array $params): array
    {
        $c = (array) config('clinicflow.cpanel');
        if ((string) ($c['host'] ?? '') === '' || (string) ($c['user'] ?? '') === '' || (string) ($c['token'] ?? '') === '') {
            throw new RuntimeException('cPanel database settings are missing (CPANEL_HOST, CPANEL_USER, CPANEL_API_TOKEN).');
        }
        $response = Http::withHeaders(['Authorization' => 'cpanel '.$c['user'].':'.$c['token']])
            ->timeout(30)->retry(2, 500, throw: false)
            ->get('https://'.$c['host'].':'.(int) ($c['port'] ?? 2083).'/execute/'.$function, $params);
        $json = (array) $response->json();
        if (! $response->successful() || (int) ($json['status'] ?? 0) !== 1) {
            $errors = array_filter((array) ($json['errors'] ?? []));

            throw new RuntimeException("cPanel {$function} failed: ".($errors === [] ? 'HTTP '.$response->status() : implode('; ', array_map('strval', $errors))));
        }

        return $json;
    }
}
