<?php

declare(strict_types=1);

namespace App\Octane;

use App\Domains\Platform\Storage\FileStore;
use Illuminate\Support\Facades\Storage;

/**
 * Octane keeps the application in memory between requests, so the super admin's storage choice
 * is re-applied at the start of every request (cheap: it is cached), and a storage switch takes
 * effect without restarting the workers.
 */
class RefreshFileStorage
{
    public function handle(object $event): void
    {
        FileStore::configure();
        Storage::forgetDisk(FileStore::DISK);
    }
}
