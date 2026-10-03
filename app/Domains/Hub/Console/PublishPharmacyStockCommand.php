<?php

declare(strict_types=1);

namespace App\Domains\Hub\Console;

use App\Domains\Hub\Actions\PharmacyComparison;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;

class PublishPharmacyStockCommand extends Command
{
    protected $signature = 'pharmacy:publish-stock';

    protected $description = 'Publish opted-in pharmacies\' stock and prices to the Network Hub for comparison';

    public function handle(PharmacyComparison $comparison): int
    {
        Provider::query()->where('type', ProviderType::Pharmacy->value)->each(fn (Provider $p) => $comparison->publish($p));

        return self::SUCCESS;
    }
}
