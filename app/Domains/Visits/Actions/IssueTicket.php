<?php

declare(strict_types=1);

namespace App\Domains\Visits\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Daily ticket numbers per provider: A001, A002, ... restarting each day.
 * Issued by the server only, so numbers are never duplicated.
 */
class IssueTicket
{
    public function handle(CarbonInterface $day): string
    {
        return DB::transaction(function () use ($day): string {
            $date = $day->toDateString();
            DB::table('ticket_counters')->insertOrIgnore(['day' => $date, 'last_number' => 0]);
            $row = DB::table('ticket_counters')->where('day', $date)->lockForUpdate()->first();
            $next = (int) ($row->last_number ?? 0) + 1;
            DB::table('ticket_counters')->where('day', $date)->update(['last_number' => $next]);

            return 'A'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        });
    }
}
