<?php

declare(strict_types=1);

namespace App\Domains\Visits\Events;

use App\Domains\Visits\Models\Visit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed to the provider's queue channel so front desk, doctors and the TV
 * display update without refreshing.
 */
class VisitStageChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public string $providerId, public string $visitId, public string $ticket, public string $stage, public ?string $patientId = null) {}

    public static function fromVisit(Visit $visit): self
    {
        return new self((string) tenant()?->getTenantKey(), $visit->id, $visit->ticket, $visit->stage->value, (string) $visit->patient_id);
    }

    public function broadcastAs(): string
    {
        return 'queue.changed';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        // The queue (staff and the waiting-room display: ticket numbers only) and the patient's own channel (portal).
        $channels = [new PrivateChannel("provider.{$this->providerId}.queue")];
        if ($this->patientId !== null) {
            $channels[] = new PrivateChannel("provider.{$this->providerId}.patient.{$this->patientId}");
        }

        return $channels;
    }
}
