<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Claims\Actions\ImportRemittances;
use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;

class ImportRemittancesCommand extends Command
{
    protected $signature = 'claims:remittances';

    protected $description = 'Import medical aid remittances for every provider';

    public function handle(): int
    {
        Provider::query()->each(function (Provider $provider): void {
            $result = $provider->run(fn () => app(ImportRemittances::class)->handle());
            $this->line("{$provider->name}: {$result['applied']} applied, {$result['shortfalls']} co-payments");
        });

        return self::SUCCESS;
    }
}
