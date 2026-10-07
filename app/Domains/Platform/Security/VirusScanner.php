<?php

declare(strict_types=1);

namespace App\Domains\Platform\Security;

/**
 * Scans an uploaded file. Returns null when clean, or the name of what was found.
 * Throws a RuntimeException when the scanner cannot be reached.
 */
interface VirusScanner
{
    public function scan(string $path): ?string;
}
