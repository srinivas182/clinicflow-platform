<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Http\Controllers;

use App\Domains\Scheduling\Calendar\CalendarApp;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: Dr Business Flow's Google and Microsoft calendar app credentials.
 */
class CalendarAdminController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Calendars', [
            'apps' => collect(['google', 'microsoft'])->map(fn (string $d) => [
                'driver' => $d, 'offered' => (bool) CalendarApp::query()->where('driver', $d)->value('offered'),
                'clientId' => CalendarApp::query()->where('driver', $d)->value('client_id'), 'hasSecret' => filled(CalendarApp::query()->where('driver', $d)->first()?->client_secret),
                'callback' => CalendarController::callbackUrl($d),
            ])->values(),
        ]);
    }

    public function save(Request $request, string $driver): RedirectResponse
    {
        $data = $request->validate(['offered' => ['required', 'boolean'], 'client_id' => ['nullable', 'string', 'max:255'], 'client_secret' => ['nullable', 'string', 'max:255']]);
        abort_unless(in_array($driver, ['google', 'microsoft'], true), 404);
        $app = CalendarApp::query()->firstOrNew(['driver' => $driver]);
        $app->fill(['offered' => (bool) $data['offered'], 'client_id' => $data['client_id'] ?? $app->client_id]);
        if (filled($data['client_secret'] ?? null)) {
            $app->client_secret = (string) $data['client_secret'];
        }
        $app->save();

        return back()->with('success', ucfirst($driver).' calendar saved.');
    }
}
