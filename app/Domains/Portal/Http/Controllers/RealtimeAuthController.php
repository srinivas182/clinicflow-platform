<?php

declare(strict_types=1);

namespace App\Domains\Portal\Http\Controllers;

use App\Domains\Portal\Actions\PortalSignIn;
use App\Domains\Visits\Actions\DeviceTokens;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Real-time channel sign-in for people who are not staff users:
 *  - patients (portal session): only their own patient, chat and call channels;
 *  - a paired waiting-room display (device token): only the practice's queue channel (ticket numbers, no names).
 * Uses the same signature the real-time server expects for private channels.
 */
class RealtimeAuthController extends Controller
{
    public function portal(Request $request, PortalSignIn $signIn): JsonResponse
    {
        [$socket, $channel] = $this->input($request);
        $patients = $signIn->profiles((string) $request->session()->get('portal_cell'))->pluck('id')->map(fn ($v) => (string) $v)->all();
        $prefix = 'private-provider.'.tenant('id').'.';
        $allowed = false;
        if ($patients !== [] && str_starts_with($channel, $prefix)) {
            $rest = substr($channel, strlen($prefix));
            if (preg_match('/^patient\.([0-9a-z]{26})$/', $rest, $m) === 1) {
                $allowed = in_array($m[1], $patients, true);
            } elseif (preg_match('/^chat\.(\d+)$/', $rest, $m) === 1) {
                $allowed = DB::table('chat_threads')->where('id', (int) $m[1])->whereIn('patient_id', $patients)->exists();
            } elseif (preg_match('/^call\.([0-9a-z]{26})$/', $rest, $m) === 1) {
                $allowed = DB::table('appointments')->where('id', $m[1])->whereIn('patient_id', $patients)->exists();
            }
        }
        abort_unless($allowed, 403);

        return response()->json(['auth' => $this->sign($socket, $channel)]);
    }

    public function display(Request $request, string $token, DeviceTokens $tokens): JsonResponse
    {
        abort_unless($tokens->verify('display', $token), 404);
        [$socket, $channel] = $this->input($request);
        abort_unless($channel === 'private-provider.'.tenant('id').'.queue', 403);

        return response()->json(['auth' => $this->sign($socket, $channel)]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function input(Request $request): array
    {
        $data = $request->validate(['socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'], 'channel_name' => ['required', 'string', 'max:200']]);

        return [(string) $data['socket_id'], (string) $data['channel_name']];
    }

    private function sign(string $socket, string $channel): string
    {
        $key = (string) config('broadcasting.connections.reverb.key');
        $secret = (string) config('broadcasting.connections.reverb.secret');

        return $key.':'.hash_hmac('sha256', $socket.':'.$channel, $secret);
    }
}
