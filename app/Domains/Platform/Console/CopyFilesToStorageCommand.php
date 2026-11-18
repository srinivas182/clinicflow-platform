<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Platform\Storage\CopyFiles;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Copies the existing files into a storage target (each practice into its own folder), verifying
 * every file by size and SHA-256. Re-runnable; never deletes anything. The same copy can be started
 * from Admin → File storage; activating a target needs a copy with no failures.
 */
class CopyFilesToStorageCommand extends Command
{
    protected $signature = 'storage:copy-to-active {target : The storage target id to copy into} {--dry-run : Only count what would be copied}';

    protected $description = 'Copy the existing files into a storage target and verify them (needed before activating it)';

    public function handle(CopyFiles $copy): int
    {
        try {
            $counts = $copy->handle((int) $this->argument('target'), null, (bool) $this->option('dry-run'), fn (string $error) => $this->error($error));
        } catch (ValidationException $e) {
            $this->error((string) collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }
        $this->info(($this->option('dry-run') ? 'Would copy' : 'Copied and verified')." {$counts['copied']}; already there {$counts['skipped']}; failed {$counts['failed']}.");

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
