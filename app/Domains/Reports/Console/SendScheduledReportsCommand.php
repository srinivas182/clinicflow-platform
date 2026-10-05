<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Platform\Models\Provider;
use App\Domains\Reports\ReportDatasets;
use App\Domains\Reports\ReportRunner;
use App\Domains\Reports\ReportsController;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Emails scheduled reports (totals only) to staff who still have the data set's permission.
 * Weekly reports go out on Mondays for the previous 7 days; monthly on the 1st for the previous month.
 */
class SendScheduledReportsCommand extends Command
{
    protected $signature = 'reports:send';

    protected $description = 'Email scheduled reports';

    public function handle(ReportRunner $runner): int
    {
        $today = now();
        Provider::query()->each(fn (Provider $p) => $p->run(function () use ($runner, $today): void {
            foreach (DB::table('report_definitions')->where('schedule', '!=', 'none')->get() as $r) {
                $due = ($r->schedule === 'weekly' && $today->isMonday()) || ($r->schedule === 'monthly' && $today->day === 1);
                if (! $due || ($r->last_sent_at !== null && $today->isSameDay($r->last_sent_at))) {
                    continue;
                }
                $def = (array) json_decode((string) $r->definition, true);
                [$from, $to] = $r->schedule === 'weekly' ? [$today->copy()->subDays(7), $today->copy()->subDay()] : [$today->copy()->subMonth()->startOfMonth(), $today->copy()->subMonth()->endOfMonth()];
                $result = $runner->run(array_merge($def, ['from' => $from->toDateString(), 'to' => $to->toDateString()]));
                $permission = ReportDatasets::all()[(string) $def['dataset']]['permission'];
                $body = strip_tags(str_replace(['</tr>', '</th>', '</td>'], ["\n", ' | ', ' | '], ReportsController::html($result, (string) $r->name, 25)))."\n\nOpen the full report in Clinic Flow: ".url('/reports');
                foreach ((array) json_decode((string) ($r->recipients ?? '[]'), true) as $userId) {
                    $email = User::query()->whereKey((int) $userId)->value('email');
                    if (is_string($email) && ReportsController::staffMay((int) $userId, $permission)) {
                        app(SendMessage::class)->handle('email', $email, $body, $r->name.' ('.$from->toDateString().' to '.$to->toDateString().')');
                    }
                }
                DB::table('report_definitions')->where('id', $r->id)->update(['last_sent_at' => now()]);
            }
        }));

        return self::SUCCESS;
    }
}
