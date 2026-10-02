<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Console;

use App\Domains\Pharmacy\Actions\ReturnUncollected;
use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;

class ReturnUncollectedCommand extends Command
{
    protected $signature = 'pharmacy:return-uncollected';

    protected $description = 'Return medicine not collected in time to stock, for every provider';

    public function handle(ReturnUncollected $action): int
    {
        Provider::query()->each(function (Provider $provider) use ($action): void {
            $count = $provider->run(fn () => $action->handle());
            $this->line("{$provider->name}: {$count} visit(s) closed");
        });

        return self::SUCCESS;
    }
}
