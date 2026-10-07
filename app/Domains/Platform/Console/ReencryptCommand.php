<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Key rotation step 2: re-encrypts every stored encrypted value (platform, network hub and each
 * practice database) with the current APP_KEY. Values are found by their encrypted format, so new
 * encrypted fields are covered automatically; the decrypted text is kept byte-for-byte.
 * See docs/security/key-rotation.md.
 */
class ReencryptCommand extends Command
{
    protected $signature = 'security:reencrypt {--dry-run : Only count what would be re-encrypted}';

    protected $description = 'Re-encrypt stored secrets and records with the current APP_KEY (after rotating it)';

    private const SKIP_TABLES = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations'];

    /** @var array{checked: int, rotated: int, unreadable: int} */
    private array $totals = ['checked' => 0, 'rotated' => 0, 'unreadable' => 0];

    public function handle(): int
    {
        // Reset each run: the command object can be reused in one process (scheduler, tests, Octane).
        $this->totals = ['checked' => 0, 'rotated' => 0, 'unreadable' => 0];
        $current = new Encrypter($this->key((string) config('app.key')), (string) config('app.cipher'));
        $this->sweep(DB::connection((string) config('tenancy.database.central_connection')), 'platform', $current);
        if (config('database.connections.hub') !== null) {
            try {
                $this->sweep(DB::connection('hub'), 'network hub', $current);
            } catch (\Throwable $e) {
                $this->warn('Network hub skipped: '.$e->getMessage());
            }
        }
        Provider::query()->each(fn (Provider $p) => $p->run(fn () => $this->sweep(DB::connection(), (string) $p->getAttribute('name'), $current)));

        $verb = $this->option('dry-run') ? 'would be re-encrypted' : 're-encrypted';
        $this->info("Checked {$this->totals['checked']} encrypted value(s); {$this->totals['rotated']} {$verb}; {$this->totals['unreadable']} unreadable with any configured key.");

        return $this->totals['unreadable'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function sweep(ConnectionInterface $db, string $label, Encrypter $current): void
    {
        if (! $db instanceof Connection) {
            return;
        }
        $schema = $db->getSchemaBuilder();
        $database = $db->getDatabaseName();
        foreach ($schema->getTables() as $table) {
            // Only this connection's database (the server may hold many practice databases).
            if (isset($table['schema']) && $table['schema'] !== $database) {
                continue;
            }
            $name = (string) $table['name'];
            if (in_array($name, self::SKIP_TABLES, true) || ! $schema->hasColumn($name, 'id')) {
                continue;
            }
            $textColumns = [];
            foreach ($schema->getColumns($name) as $column) {
                if (in_array(strtolower((string) $column['type_name']), ['varchar', 'char', 'text', 'mediumtext', 'longtext'], true)) {
                    $textColumns[(string) $column['name']] = true;
                }
            }
            foreach (array_keys($textColumns) as $col) {
                // Laravel's encrypted values are base64 JSON starting {"iv":
                $db->table($name)->select(['id', $col])->where($col, 'like', 'eyJpdiI6%')->orderBy('id')
                    ->lazyById(500, 'id')->each(function ($row) use ($db, $name, $col, $current, $label): void {
                        $this->totals['checked']++;
                        $value = (string) $row->{$col};
                        try {
                            $current->decryptString($value);

                            return; // already under the current key
                        } catch (\Throwable) {
                        }
                        try {
                            $plain = Crypt::decryptString($value); // tries APP_PREVIOUS_KEYS
                        } catch (\Throwable) {
                            $this->totals['unreadable']++;
                            $this->warn("{$label}: {$name}.{$col} #{$row->id} cannot be read with any configured key");

                            return;
                        }
                        $this->totals['rotated']++;
                        if ($this->output->isVerbose()) {
                            $this->line("  {$label}: {$name}.{$col} #{$row->id}");
                        }
                        if (! $this->option('dry-run')) {
                            $db->table($name)->where('id', $row->id)->update([$col => $current->encryptString($plain)]);
                        }
                    });
            }
        }
    }

    private function key(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }
}
