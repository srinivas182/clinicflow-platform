<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Http\Controllers;

use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Domains\Scheduling\Calendar\CalendarApp;
use App\Domains\Scheduling\Calendar\CalendarConnection;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A doctor's calendar: connect Google or Microsoft, privacy and busy-time
 * options, the iCal feed, and the OAuth callback (central domain).
 */
class CalendarController extends Controller
{
    public function show(Request $request): Response
    {
        $conn = $this->connection($request);

        return Inertia::render('Settings/Calendar', [
            'connection' => ['driver' => $conn->driver, 'showInitials' => $conn->show_initials, 'importBusy' => $conn->import_busy, 'error' => $conn->last_error],
            'icalUrl' => url("/calendar/{$conn->ical_token}.ics"),
            'apps' => CalendarApp::query()->where('offered', true)->whereNotNull('client_id')->pluck('driver'),
        ]);
    }

    public function connect(Request $request, string $driver): HttpResponse
    {
        $app = CalendarApp::query()->where('driver', $driver)->where('offered', true)->firstOrFail();
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $state = Crypt::encryptString((string) json_encode(['t' => $provider->id, 's' => $this->connection($request)->staff_id, 'd' => $driver, 'x' => now()->addMinutes(15)->timestamp]));

        return redirect()->away($app->authorizeUrl(self::callbackUrl($driver), $state));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['show_initials' => ['required', 'boolean'], 'import_busy' => ['required', 'boolean'], 'disconnect' => ['boolean'], 'new_feed' => ['boolean']]);
        $conn = $this->connection($request);
        $conn->forceFill(['show_initials' => $data['show_initials'], 'import_busy' => $data['import_busy']]);
        if ($data['disconnect'] ?? false) {
            $conn->forceFill(['driver' => null, 'access_token' => null, 'refresh_token' => null, 'token_expires_at' => null]);
        }
        if ($data['new_feed'] ?? false) {
            $conn->forceFill(['ical_token' => Str::random(40)]);
        }
        $conn->save();

        return back()->with('success', 'Calendar settings saved.');
    }

    /**
     * Read-only iCal feed of the doctor's upcoming appointments (no names unless initials are on).
     */
    public function ical(string $token): HttpResponse
    {
        $conn = CalendarConnection::query()->where('ical_token', $token)->firstOrFail();
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Dr Business Flow//EN', 'CALSCALE:GREGORIAN'];
        Appointment::query()->where('staff_id', $conn->staff_id)->whereIn('status', [AppointmentStatus::Booked->value, AppointmentStatus::CheckedIn->value])
            ->where('starts_at', '>=', now()->subDay())->where('starts_at', '<=', now()->addDays(60))->orderBy('starts_at')->get()
            ->each(function (Appointment $a) use (&$lines, $conn): void {
                $title = 'Appointment';
                if ($conn->show_initials && ($p = Patient::query()->find($a->patient_id)) instanceof Patient) {
                    $title .= ' · '.mb_substr($p->first_names, 0, 1).'.'.mb_substr($p->surname, 0, 1).'.';
                }
                array_push($lines, 'BEGIN:VEVENT', "UID:{$a->id}@clinicflow", 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
                    'DTSTART:'.$a->starts_at->copy()->utc()->format('Ymd\THis\Z'), 'DTEND:'.$a->ends_at->copy()->utc()->format('Ymd\THis\Z'), "SUMMARY:{$title}", 'END:VEVENT');
            });
        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines)."\r\n", 200, ['Content-Type' => 'text/calendar; charset=utf-8']);
    }

    public function callback(Request $request, string $driver): RedirectResponse
    {
        try {
            $state = json_decode(Crypt::decryptString($request->string('state')->toString()), true);
        } catch (\Throwable) {
            abort(403, 'The connection request is invalid.');
        }
        abort_unless(is_array($state) && ($state['d'] ?? null) === $driver && (int) ($state['x'] ?? 0) > now()->timestamp, 403, 'The connection request has expired.');
        $app = CalendarApp::query()->where('driver', $driver)->firstOrFail();
        $r = Http::asForm()->acceptJson()->post($app->tokenUrl(), ['grant_type' => 'authorization_code', 'code' => $request->string('code')->toString(),
            'redirect_uri' => self::callbackUrl($driver), 'client_id' => (string) $app->client_id, 'client_secret' => (string) $app->client_secret]);
        abort_unless(is_string($r->json('access_token')), 422, 'The calendar did not issue access.');

        $provider = Provider::query()->findOrFail((string) $state['t']);
        $provider->run(fn () => CalendarConnection::query()->where('staff_id', (int) $state['s'])->update([
            'driver' => $driver, 'access_token' => Crypt::encryptString((string) $r->json('access_token')),
            'refresh_token' => is_string($r->json('refresh_token')) ? Crypt::encryptString((string) $r->json('refresh_token')) : null,
            'token_expires_at' => now()->addSeconds((int) ($r->json('expires_in') ?? 3600) - 60), 'last_error' => null,
        ]));

        return redirect()->away(($request->isSecure() ? 'https://' : 'http://').$provider->domains()->value('domain').'/me/calendar');
    }

    public static function callbackUrl(string $driver): string
    {
        return rtrim((string) config('app.url'), '/')."/calendar/callback/{$driver}";
    }

    private function connection(Request $request): CalendarConnection
    {
        $staff = Staff::query()->findOrFail((int) $request->user()?->getAuthIdentifier());

        return CalendarConnection::query()->firstOrCreate(['staff_id' => $staff->id], ['ical_token' => Str::random(40)]);
    }
}
