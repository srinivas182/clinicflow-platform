<?php

declare(strict_types=1);

namespace App\Domains\Lab\Console;

use App\Domains\Lab\Actions\LabReleaseRules;
use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;

class LabReleaseTickCommand extends Command
{
    protected $signature = 'lab:release-tick';

    protected $description = 'Auto-release normal lab results and escalate results waiting too long for the doctor';

    public function handle(): int
    {
        Provider::query()->each(fn (Provider $p) => $p->run(fn () => app(LabReleaseRules::class)->run()));

        return self::SUCCESS;
    }
}
