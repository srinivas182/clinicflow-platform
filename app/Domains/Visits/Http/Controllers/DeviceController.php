<?php

declare(strict_types=1);

namespace App\Domains\Visits\Http\Controllers;

use App\Domains\Platform\Models\Provider;
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

        $today = Visit::query()->whereDate('visit_date', today())->get(['ticket', 'stage', 'stage_changed_at']);

        return Inertia::render('Devices/Display', [
            'provider' => $this->providerName(),
            'calling' => $today
                ->filter(fn (Visit $v) => in_array($v->stage, [VisitStage::Triage, VisitStage::Doctor], true) && $v->stage_changed_at->gt(now()->subMinutes(10)))
                ->sortByDesc('stage_changed_at')->take(3)
                ->map(fn (Visit $v) => ['ticket' => $v->ticket, 'to' => $v->stage === VisitStage::Triage ? 'Triage' : 'Doctor'])->values(),
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
