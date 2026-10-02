<?php

declare(strict_types=1);

namespace App\Domains\Visits\Actions;

use App\Domains\Platform\Models\Setting;
use Illuminate\Support\Str;

/**
 * Secret links for the self check-in kiosk and the waiting-room display.
 * These devices never sign in as staff and see no patient names.
 */
class DeviceTokens
{
    /**
     * @return array{kiosk: string, display: string}
     */
    public function ensure(): array
    {
        $tokens = [];

        foreach (['kiosk', 'display'] as $device) {
            $token = Setting::get('devices', "{$device}_token");
            if (! is_string($token) || $token === '') {
                $token = Str::random(40);
                Setting::put('devices', "{$device}_token", $token);
            }
            $tokens[$device] = $token;
        }

        return $tokens;
    }

    public function rotate(string $device): string
    {
        $token = Str::random(40);
        Setting::put('devices', "{$device}_token", $token);

        return $token;
    }

    public function verify(string $device, string $token): bool
    {
        $stored = Setting::get('devices', "{$device}_token");

        return is_string($stored) && $stored !== '' && hash_equals($stored, $token);
    }
}
