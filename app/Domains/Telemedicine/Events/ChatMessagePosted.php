<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A chat consult message was posted. Only the thread id is sent; the page fetches the
 * messages itself (no message text travels through the real-time server).
 */
class ChatMessagePosted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public string $providerId, public string $threadId) {}

    public function broadcastAs(): string
    {
        return 'chat.posted';
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("provider.{$this->providerId}.chat.{$this->threadId}")];
    }
}
