<?php

declare(strict_types=1);

namespace App\Domains\Website\Console;

use App\Domains\Platform\Models\Provider;
use App\Domains\Website\Actions\Feedback;
use Illuminate\Console\Command;

class FeedbackRequestCommand extends Command
{
    protected $signature = 'feedback:request';

    protected $description = 'Ask patients for feedback after finished visits';

    public function handle(): int
    {
        Provider::query()->each(fn (Provider $p) => $p->run(fn () => app(Feedback::class)->sendRequests()));

        return self::SUCCESS;
    }
}
