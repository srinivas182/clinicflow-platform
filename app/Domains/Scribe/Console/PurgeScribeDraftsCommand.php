<?php

declare(strict_types=1);

namespace App\Domains\Scribe\Console;

use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeScribeDraftsCommand extends Command
{
    protected $signature = 'scribe:purge';

    protected $description = 'Delete AI scribe transcripts and drafts older than 30 days (accepted notes stay in the consultation)';

    public function handle(): int
    {
        Provider::query()->each(fn (Provider $p) => $p->run(fn () => DB::table('scribe_sessions')->where('created_at', '<', now()->subDays(30))
            ->where(fn ($q) => $q->whereNotNull('transcript')->orWhereNotNull('draft'))->update(['transcript' => null, 'draft' => null, 'updated_at' => now()])));

        return self::SUCCESS;
    }
}
