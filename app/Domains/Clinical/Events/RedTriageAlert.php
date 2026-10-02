<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sent immediately to every doctor on shift. First doctor to accept takes the patient.
 */
class RedTriageAlert implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public string $providerId, public string $visitId, public string $ticket) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("provider.{$this->providerId}.doctors")];
    }
}
