<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Data retention (daily): removes operational logs past their retention period. Clinical records
 * (consultations, results, prescriptions, chat consults) are never removed here.
 */
class PruneDataCommand extends Command
{
    protected $signature = 'data:prune {--dry-run : Only count what would be removed}';

    protected $description = 'Remove operational logs past their retention period';

    public function handle(): int
    {
        $days = (array) config('clinicflow.retention');
        $central = DB::connection((string) config('tenancy.database.central_connection'));
        $total = $this->prune($central, 'platform', [
            ['login_challenges', 'created_at', $days['codes'] ?? 7, null],
            ['login_events', 'created_at', $days['sign_ins'] ?? 365, null],
            ['failed_jobs', 'failed_at', $days['failed_jobs'] ?? 30, null],
            ['activity_log', 'created_at', $days['audit'] ?? 2555, null],
        ]);
        $total += $central->table('trusted_devices')->where('expires_at', '<', now())->when($this->option('dry-run'), fn ($q) => $q->whereRaw('1 = 0'))->delete();
        Provider::query()->each(function (Provider $p) use ($days, &$total): void {
            $p->run(function () use ($days, $p, &$total): void {
                $total += $this->prune(DB::connection(), (string) $p->getAttribute('name'), [
                    ['portal_login_challenges', 'created_at', $days['codes'] ?? 7, null],
                    ['signing_challenges', 'created_at', $days['codes'] ?? 7, null],
                    ['api_requests', 'created_at', $days['api_requests'] ?? 90, null],
                    ['webhook_deliveries', 'created_at', $days['webhook_deliveries'] ?? 90, null],
                    ['record_views', 'created_at', $days['record_views'] ?? 365, null],
                    ['lab_inbound_messages', 'created_at', $days['lab_messages'] ?? 365, ['status', ['applied', 'duplicate', 'rejected']]],
                    ['message_log', 'sent_at', $days['messages'] ?? 730, null],
                    ['patient_access_log', 'created_at', $days['audit'] ?? 2555, null],
                    ['activity_log', 'created_at', $days['audit'] ?? 2555, null],
                ]);
            });
        });
        $this->info(($this->option('dry-run') ? 'Would remove' : 'Removed')." {$total} expired row(s).");

        return self::SUCCESS;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: array{0: string, 1: list<string>}|null}>  $rules
     */
    private function prune(ConnectionInterface $db, string $label, array $rules): int
    {
        if (! $db instanceof Connection) {
            return 0;
        }
        $schema = $db->getSchemaBuilder();
        $removed = 0;
        foreach ($rules as [$table, $column, $days, $only]) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $column)) {
                continue;
            }
            $query = $db->table($table)->where($column, '<', now()->subDays((int) $days));
            if ($only !== null) {
                $query->whereIn($only[0], $only[1]);
            }
            $count = $this->option('dry-run') ? $query->count() : $query->delete();
            if ($count > 0) {
                $this->line("  {$label}: {$table} — {$count}");
            }
            $removed += $count;
        }

        return $removed;
    }
}
