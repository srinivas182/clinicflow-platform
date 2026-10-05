<?php

declare(strict_types=1);

namespace App\Domains\Api\Webhooks;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Messaging\Actions\SendMessage;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signed webhooks. Payloads carry IDs and status only — never clinical details.
 * Signature header: X-ClinicFlow-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "t.body" with the endpoint secret>.
 */
class Webhooks
{
    public const EVENTS = [
        'appointment.booked' => 'Appointment booked',
        'appointment.cancelled' => 'Appointment cancelled',
        'appointment.checked_in' => 'Patient checked in',
        'invoice.paid' => 'Invoice paid in full',
        'patient.registered' => 'Patient registered',
    ];

    /** Minutes to wait before each retry (about 20 hours in total). */
    public const BACKOFF = [1, 5, 30, 120, 360, 720];

    public const DISABLE_AFTER = 5;

    public function __construct(private readonly WebhookUrlGuard $guard) {}

    /**
     * @param  list<string>  $events
     * @return array{id: int, secret: string}
     */
    public function addEndpoint(string $url, array $events, int $by): array
    {
        $events = array_values(array_intersect(array_keys(self::EVENTS), $events));
        if ($events === []) {
            throw ValidationException::withMessages(['events' => 'Choose at least one event.']);
        }
        if (($problem = $this->guard->problem($url)) !== null) {
            throw ValidationException::withMessages(['url' => $problem]);
        }
        $secret = 'whsec_'.Str::random(40);
        $id = (int) DB::table('webhook_endpoints')->insertGetId(['url' => $url, 'events' => json_encode($events), 'secret' => Crypt::encryptString($secret),
            'active' => true, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $id, 'secret' => $secret];
    }

    public function setActive(int $id, bool $active): void
    {
        DB::table('webhook_endpoints')->where('id', $id)->update(['active' => $active, 'consecutive_failures' => 0, 'disabled_at' => $active ? null : now(), 'updated_at' => now()]);
    }

    /**
     * Queues a delivery to every active endpoint subscribed to the event.
     *
     * @param  array<string, mixed>  $data
     */
    public function dispatch(string $event, array $data): int
    {
        $count = 0;
        foreach (DB::table('webhook_endpoints')->where('active', true)->get() as $endpoint) {
            if ($event !== 'webhook.test' && ! in_array($event, (array) json_decode((string) $endpoint->events, true), true)) {
                continue;
            }
            $id = (int) DB::table('webhook_deliveries')->insertGetId(['webhook_endpoint_id' => $endpoint->id, 'event' => $event, 'payload' => '{}',
                'status' => 'pending', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('webhook_deliveries')->where('id', $id)->update(['payload' => json_encode([
                'id' => $id, 'event' => $event, 'created_at' => now()->toIso8601String(), 'practice' => (string) tenant('id'), 'data' => $data,
            ])]);
            $count++;
        }

        return $count;
    }

    /**
     * Sends due deliveries for this practice. Returns how many were attempted.
     */
    public function deliverDue(): int
    {
        $due = DB::table('webhook_deliveries')->where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('id')->limit(100)->get();
        foreach ($due as $delivery) {
            $this->attempt($delivery);
        }

        return $due->count();
    }

    private function attempt(\stdClass $delivery): void
    {
        $endpoint = DB::table('webhook_endpoints')->where('id', $delivery->webhook_endpoint_id)->first();
        if ($endpoint === null || ! (bool) $endpoint->active) {
            DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'last_error' => 'Endpoint switched off', 'updated_at' => now()]);

            return;
        }
        $body = (string) $delivery->payload;
        $time = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $time.'.'.$body, Crypt::decryptString((string) $endpoint->secret));
        $status = null;
        $error = $this->guard->problem((string) $endpoint->url);
        if ($error === null) {
            try {
                $response = Http::timeout(10)->withOptions(['allow_redirects' => false])->withBody($body, 'application/json')->withHeaders([
                    'X-ClinicFlow-Event' => (string) $delivery->event, 'X-ClinicFlow-Delivery' => (string) $delivery->id,
                    'X-ClinicFlow-Signature' => "t={$time},v1={$signature}", 'User-Agent' => 'ClinicFlow-Webhooks/1.0',
                ])->post((string) $endpoint->url);
                $status = $response->status();
                $error = $response->successful() ? null : "HTTP {$status}";
            } catch (\Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 250);
            }
        }
        $attempts = (int) $delivery->attempts + 1;

        if ($error === null) {
            DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['status' => 'delivered', 'attempts' => $attempts, 'response_status' => $status, 'last_error' => null,
                'delivered_at' => now(), 'next_attempt_at' => null, 'updated_at' => now()]);
            DB::table('webhook_endpoints')->where('id', $endpoint->id)->update(['consecutive_failures' => 0]);

            return;
        }
        $final = $attempts > count(self::BACKOFF);
        DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['status' => $final ? 'failed' : 'pending', 'attempts' => $attempts, 'response_status' => $status,
            'last_error' => $error, 'next_attempt_at' => $final ? null : now()->addMinutes(self::BACKOFF[$attempts - 1]), 'updated_at' => now()]);
        if ($final) {
            $failures = (int) $endpoint->consecutive_failures + 1;
            DB::table('webhook_endpoints')->where('id', $endpoint->id)->update(['consecutive_failures' => $failures]);
            if ($failures >= self::DISABLE_AFTER) {
                $this->setActive((int) $endpoint->id, false);
                foreach (User::query()->whereIn('id', Membership::query()->where('tenant_id', tenant('id'))->where('role', StaffRole::Owner->value)->pluck('user_id'))->pluck('email') as $email) {
                    app(SendMessage::class)->handle('email', (string) $email, "Clinic Flow switched off your webhook to {$endpoint->url} after ".self::DISABLE_AFTER.' failed deliveries in a row. Fix the receiver, then switch it back on in Settings → API.', 'Webhook switched off');
                }
            }
        }
    }
}
