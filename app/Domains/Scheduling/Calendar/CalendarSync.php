<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Calendar;

use App\Domains\Patients\Models\Patient;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Pushes a doctor's appointments to Google Calendar or Microsoft 365 and reads
 * back busy times. Events show "Appointment" (or patient initials if the doctor
 * chose that) — never names or reasons, as external calendars sit outside
 * POPIA controls. A calendar problem never stops a booking.
 */
class CalendarSync
{
    public function sync(Appointment $appointment): void
    {
        $conn = CalendarConnection::query()->where('staff_id', $appointment->staff_id)->whereNotNull('driver')->first();
        if (! $conn instanceof CalendarConnection) {
            return;
        }
        try {
            $existing = DB::table('calendar_events')->where('appointment_id', $appointment->id)->value('external_id');
            $active = in_array($appointment->status, [AppointmentStatus::Booked, AppointmentStatus::CheckedIn], true);
            if (! $active) {
                if ($existing !== null) {
                    $this->request($conn, 'delete', $this->eventUrl($conn, (string) $existing));
                    DB::table('calendar_events')->where('appointment_id', $appointment->id)->delete();
                }

                return;
            }
            $body = $this->event($conn, $appointment);
            if ($existing !== null) {
                $this->request($conn, 'patch', $this->eventUrl($conn, (string) $existing), $body);

                return;
            }
            $id = (string) $this->request($conn, 'post', $this->eventUrl($conn, null), $body)['id'];
            DB::table('calendar_events')->insert(['appointment_id' => $appointment->id, 'external_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            $conn->forceFill(['last_error' => null])->save();
        } catch (Throwable $e) {
            $conn->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 250)])->save();
        }
    }

    /**
     * Imports the next 14 days of busy times so they block bookable slots.
     */
    public function importBusy(CalendarConnection $conn): int
    {
        $from = CarbonImmutable::now();
        $to = $from->addDays(14);
        $periods = $conn->driver === 'google'
            ? (array) ($this->request($conn, 'post', 'https://www.googleapis.com/calendar/v3/freeBusy', ['timeMin' => $from->toIso8601String(), 'timeMax' => $to->toIso8601String(), 'items' => [['id' => 'primary']]])['calendars']['primary']['busy'] ?? [])
            : array_map(fn ($e) => ['start' => $e['start']['dateTime'] ?? null, 'end' => $e['end']['dateTime'] ?? null],
                (array) ($this->request($conn, 'get', 'https://graph.microsoft.com/v1.0/me/calendarView?'.http_build_query(['startDateTime' => $from->toIso8601String(), 'endDateTime' => $to->toIso8601String(), '$select' => 'start,end,showAs']))['value'] ?? []));

        // Our own synced appointments are already blocked; skip them.
        $ours = DB::table('calendar_events')->pluck('external_id')->all();
        DB::table('calendar_busy')->where('staff_id', $conn->staff_id)->delete();
        $count = 0;
        foreach ($periods as $p) {
            if (! is_array($p) || ! isset($p['start'], $p['end']) || in_array($p['id'] ?? '', $ours, true)) {
                continue;
            }
            DB::table('calendar_busy')->insert(['staff_id' => $conn->staff_id, 'starts_at' => CarbonImmutable::parse((string) $p['start'])->setTimezone(config('app.timezone')), 'ends_at' => CarbonImmutable::parse((string) $p['end'])->setTimezone(config('app.timezone'))]);
            $count++;
        }

        return $count;
    }

    public static function isBusy(int $staffId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return DB::table('calendar_busy')->where('staff_id', $staffId)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function event(CalendarConnection $conn, Appointment $a): array
    {
        $title = 'Appointment';
        if ($conn->show_initials) {
            $p = Patient::query()->find($a->patient_id);
            $title .= $p instanceof Patient ? ' · '.mb_substr($p->first_names, 0, 1).'.'.mb_substr($p->surname, 0, 1).'.' : '';
        }
        $start = $a->starts_at->toIso8601String();
        $end = $a->ends_at->toIso8601String();

        return $conn->driver === 'google'
            ? ['summary' => $title, 'description' => 'Dr Business Flow', 'start' => ['dateTime' => $start], 'end' => ['dateTime' => $end]]
            : ['subject' => $title, 'body' => ['contentType' => 'text', 'content' => 'Dr Business Flow'], 'start' => ['dateTime' => $a->starts_at->format('Y-m-d\TH:i:s'), 'timeZone' => (string) config('app.timezone')], 'end' => ['dateTime' => $a->ends_at->format('Y-m-d\TH:i:s'), 'timeZone' => (string) config('app.timezone')]];
    }

    private function eventUrl(CalendarConnection $conn, ?string $id): string
    {
        return $conn->driver === 'google'
            ? 'https://www.googleapis.com/calendar/v3/calendars/primary/events'.($id === null ? '' : '/'.rawurlencode($id))
            : 'https://graph.microsoft.com/v1.0/me/events'.($id === null ? '' : '/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(CalendarConnection $conn, string $method, string $url, array $body = []): array
    {
        $response = Http::withToken($this->token($conn))->acceptJson()->{$method}($url, $body);
        if (! $response->successful()) {
            throw new RuntimeException("Calendar update failed (HTTP {$response->status()}).");
        }

        return (array) ($response->json() ?? []);
    }

    private function token(CalendarConnection $conn): string
    {
        if ($conn->token_expires_at !== null && $conn->token_expires_at->isFuture()) {
            return (string) $conn->access_token;
        }
        $app = CalendarApp::query()->where('driver', (string) $conn->driver)->firstOrFail();
        $r = Http::asForm()->acceptJson()->post($app->tokenUrl(), ['grant_type' => 'refresh_token', 'refresh_token' => (string) $conn->refresh_token, 'client_id' => (string) $app->client_id, 'client_secret' => (string) $app->client_secret]);
        if (! is_string($r->json('access_token'))) {
            throw new RuntimeException('Calendar sign-in expired. Reconnect your calendar.');
        }
        $conn->forceFill(['access_token' => $r->json('access_token'), 'refresh_token' => $r->json('refresh_token') ?? $conn->refresh_token, 'token_expires_at' => now()->addSeconds((int) ($r->json('expires_in') ?? 3600) - 60)])->save();

        return (string) $conn->access_token;
    }
}
