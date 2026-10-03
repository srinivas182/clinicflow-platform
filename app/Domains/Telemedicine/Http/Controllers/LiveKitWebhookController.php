<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Http\Controllers;

use App\Domains\Platform\Models\Provider;
use App\Domains\Telemedicine\Actions\TrackTeleSession;
use App\Domains\Telemedicine\Models\TeleSession;
use App\Domains\Telemedicine\Models\VideoConfig;
use App\Domains\Telemedicine\Support\LiveKit;
use App\Domains\Telemedicine\Support\Telemedicine;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives LiveKit room events. Accepted when signed by any saved
 * configuration, so calls finishing on a server that was just switched off
 * are still charged correctly.
 */
class LiveKitWebhookController extends Controller
{
    public function __invoke(Request $request, TrackTeleSession $tracker): Response
    {
        $body = $request->getContent();
        $verified = VideoConfig::query()->get()->contains(fn (VideoConfig $c) => $c->isComplete() && (new LiveKit($c))->verifyWebhook($body, $request->header('Authorization')));
        if (! $verified) {
            return response('Invalid signature', 401);
        }

        $event = (string) $request->input('event');
        $room = (string) $request->input('room.name');
        $parsed = Telemedicine::parseRoom($room);
        if ($parsed === null) {
            return response('Ignored', 200);
        }
        [$tenantId] = $parsed;
        $provider = Provider::query()->find($tenantId);
        if (! $provider instanceof Provider) {
            return response('Ignored', 200);
        }

        $at = is_numeric($request->input('createdAt')) ? CarbonImmutable::createFromTimestamp((int) $request->input('createdAt')) : CarbonImmutable::now();
        $identity = $request->input('participant.identity');

        $provider->run(function () use ($room, $event, $identity, $at, $tracker): void {
            $session = TeleSession::query()->with('appointment')->where('room_name', $room)->first();
            if ($session instanceof TeleSession) {
                $tracker->handle($session, $event, is_string($identity) ? $identity : null, $at);
            }
        });

        return response('OK', 200);
    }
}
