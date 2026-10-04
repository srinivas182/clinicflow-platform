<?php

declare(strict_types=1);

namespace App\Domains\Api\Console;

use App\Domains\Api\Webhooks\Webhooks;
use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;

class DeliverWebhooksCommand extends Command
{
    protected $signature = 'webhooks:deliver';

    protected $description = 'Send due webhook deliveries for every practice';

    public function handle(): int
    {
        Provider::query()->each(fn (Provider $p) => $p->run(fn () => app(Webhooks::class)->deliverDue()));

        return self::SUCCESS;
    }
}
