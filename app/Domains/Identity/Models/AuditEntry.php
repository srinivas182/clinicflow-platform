<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * Append-only audit log entry. Lives in the database of the current context:
 * the provider database inside a workspace, the Platform database outside.
 */
class AuditEntry extends Activity
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit entries are append-only.'));
    }
}
