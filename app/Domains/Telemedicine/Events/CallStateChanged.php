<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Something changed for a video/audio consult (AI scribe consent or progress, paid extension).
 * Only ids are sent; both screens fetch the call state themselves.
 */
class CallStateChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public string $providerId, public string $appointmentId) {}

    public function broadcastAs(): string
    {
        return 'call.changed';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("provider.{$this->providerId}.call.{$this->appointmentId}")];
    }

    /** Notifies the call screens for a scribe session that belongs to a call or chat consult. */
    public static function forScribeSession(string $sessionId): void
    {
        $appointment = DB::table('scribe_sessions')->where('id', $sessionId)->value('appointment_id');
        if ($appointment !== null && tenant('id') !== null) {
            event(new self((string) tenant('id'), (string) $appointment));
        }
    }
}
