<?php

declare(strict_types=1);

namespace App\Domains\Platform\Jobs;

use App\Domains\Platform\Storage\CopyFiles;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Background copy of existing files into a storage target (started from Admin → File storage),
 * and the catch-up copy after activation for files uploaded in between.
 */
class CopyFilesToStorage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $targetId, public ?string $source = null) {}

    public function handle(CopyFiles $copy): void
    {
        $copy->handle($this->targetId, $this->source);
    }
}
