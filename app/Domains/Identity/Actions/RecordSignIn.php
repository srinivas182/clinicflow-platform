<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Messaging\Actions\SendMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a sign-in and emails the user when it comes from a browser or device not seen before.
 */
class RecordSignIn
{
    public function handle(User $user, string $ip, string $userAgent): void
    {
        $device = hash('sha256', $userAgent);
        $known = DB::table('login_events')->where('user_id', $user->id)->where('device', $device)->exists();
        $first = ! DB::table('login_events')->where('user_id', $user->id)->exists();
        DB::table('login_events')->insert(['user_id' => $user->id, 'ip' => mb_substr($ip, 0, 45), 'user_agent' => mb_substr($userAgent, 0, 255),
            'device' => $device, 'new_device' => ! $known && ! $first, 'created_at' => now()]);
        if (! $known && ! $first) {
            app(SendMessage::class)->handle('email', (string) $user->email,
                'New sign-in to your account at '.now()->format('Y-m-d H:i')." from {$ip} (".mb_substr($userAgent, 0, 80).").\n\n"
                ."If this wasn't you, change your password now and use \"Sign out other devices\" on your security page.",
                'New sign-in to your account');
        }
    }
}
