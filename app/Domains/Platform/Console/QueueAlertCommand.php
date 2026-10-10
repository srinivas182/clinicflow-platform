<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Messaging\Actions\SendMessage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly: emails platform admins when background jobs failed in the last hour.
 */
class QueueAlertCommand extends Command
{
    protected $signature = 'queue:alert';

    protected $description = 'Alert platform admins about failed background jobs in the last hour';

    public function handle(): int
    {
        $failed = DB::connection((string) config('queue.failed.database', config('database.default')))->table('failed_jobs')->where('failed_at', '>=', now()->subHour())->get(['queue', 'payload']);
        if ($failed->isEmpty()) {
            return self::SUCCESS;
        }
        $byJob = $failed->groupBy(fn ($f) => (string) (json_decode((string) $f->payload, true)['displayName'] ?? 'job'))->map->count();
        $body = $failed->count()." background job(s) failed in the last hour:\n".$byJob->map(fn ($n, $job) => "- {$job}: {$n}")->implode("\n")."\n\nReview them in Admin → Queues (Horizon) and retry once the cause is fixed.";
        foreach (User::query()->where('is_platform_admin', true)->pluck('email') as $email) {
            app(SendMessage::class)->handle('email', (string) $email, $body, 'Dr Business Flow: background jobs failed');
        }
        $this->info("Alerted about {$failed->count()} failed job(s).");

        return self::SUCCESS;
    }
}
