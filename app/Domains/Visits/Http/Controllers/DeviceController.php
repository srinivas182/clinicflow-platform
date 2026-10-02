<?php

declare(strict_types=1);

namespace App\Domains\Visits\Http\Controllers;

use App\Domains\Platform\Models\Provider;
use App\Domains\Scheduling\Models\Room;
use App\Domains\Visits\Actions\DeviceTokens;
use App\Domains\Visits\Actions\KioskCheckIn;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self check-in kiosk and waiting-room display. Reached with a secret device
 * link; never signed in as staff; no patient names on the display.
 */
class DeviceController extends Controller
{
    public function kiosk(string $token, DeviceTokens $tokens): Response
    {
        abort_unless($tokens->verify('kiosk', $token), 404);

        return Inertia::render('Devices/Kiosk', ['token' => $token, 'provider' => $this->providerName()]);
    }

    public function kioskCheckIn(Request $request, string $token, DeviceTokens $tokens, KioskCheckIn $action): RedirectResponse
    {
        abort_unless($tokens->verify('kiosk', $token), 404);

        $data = $request->validate(['cell' => ['required', 'string', 'max:15']]);
        $visit = $action->handle($data['cell']);

        return back()->with('success', $visit->ticket);
    }

    public function display(string $token, DeviceTokens $tokens): Response
    {
        abort_unless($tokens->verify('display', $token), 404);

        $today = Visit::query()->whereDate('visit_date', today())->whereNull('called_at')->get(['ticket', 'stage', 'stage_changed_at', 'called_at']);

        return Inertia::render('Devices/Display', [
            'provider' => $this->providerName(),
            'calling' => Visit::query()->whereDate('visit_date', today())->where('stage', VisitStage::Doctor->value)
                ->whereNotNull('called_at')->where('called_at', '>', now()->subMinutes(10))
                ->orderByDesc('called_at')->limit(3)->get()
                ->map(fn (Visit $v) => ['ticket' => $v->ticket, 'to' => $v->room_id !== null ? (Room::query()->whereKey($v->room_id)->value('name') ?? 'Doctor') : 'Doctor'])->values(),
            'waiting' => $today->filter(fn (Visit $v) => $v->stage->isWaiting())
                ->map(fn (Visit $v) => ['ticket' => $v->ticket, 'stage' => $v->stage->label()])->values(),
        ]);
    }

    private function providerName(): string
    {
        $provider = tenant();

        return $provider instanceof Provider ? $provider->name : '';
    }
}
