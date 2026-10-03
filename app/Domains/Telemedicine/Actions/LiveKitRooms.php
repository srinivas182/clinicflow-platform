<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Actions;

use App\Domains\Telemedicine\Support\LiveKit;

/**
 * Best-effort room clean-up after the booked time and grace have passed.
 */
final class LiveKitRooms
{
    public static function delete(string $room): void
    {
        LiveKit::active()?->deleteRoom($room);
    }
}
