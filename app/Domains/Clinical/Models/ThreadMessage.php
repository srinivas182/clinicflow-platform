<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Models;

use App\Domains\Clinical\Support\PracticeCrypto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A clinician message. The body is encrypted with the practice's key and can
 * never be edited or deleted; corrections are new messages (corrects_id).
 *
 * @property int $id
 * @property string $message_thread_id
 * @property int|null $sender_staff_id
 * @property string $sender_label
 * @property string $body
 * @property string|null $attachment_path
 * @property string|null $shared_item
 * @property int|null $corrects_id
 * @property bool $visible_to_patient
 * @property Carbon|null $filed_at
 * @property list<int>|null $read_by
 * @property Carbon $created_at
 */
class ThreadMessage extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['visible_to_patient' => 'boolean', 'filed_at' => 'datetime', 'read_by' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (ThreadMessage $m): void {
            if (array_diff(array_keys($m->getDirty()), ['visible_to_patient', 'filed_at', 'read_by']) !== []) {
                throw new LogicException('Messages cannot be edited. Send a correction instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Messages cannot be deleted.'));
    }

    public function plainBody(): string
    {
        return PracticeCrypto::decrypt($this->body);
    }
}
