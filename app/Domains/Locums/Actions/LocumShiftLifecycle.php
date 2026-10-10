<?php

declare(strict_types=1);

namespace App\Domains\Locums\Actions;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Platform\Models\Provider;
use App\Domains\Scheduling\Models\RosterSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * After booking: alerts, reminders, hours, the locum's shift invoice (paid
 * directly by the practice), cancellations and a private "would book again".
 */
class LocumShiftLifecycle
{
    public const LATE_HOURS = 24;

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    /**
     * Emails (and optionally texts) verified locums about a new shift: the invited locum, or locums
     * who work in the shift's area. Each locum is told about a shift once.
     */
    public function alert(int $shiftId): int
    {
        $shift = $this->db()->table('locum_shifts')->where('id', $shiftId)->first();
        if ($shift === null || $shift->status !== 'open') {
            return 0;
        }
        $practice = (string) Provider::query()->whereKey($shift->tenant_id)->value('name');
        $profiles = $this->db()->table('locum_profiles')->join('users', 'users.id', '=', 'locum_profiles.user_id')->where('locum_profiles.status', 'verified')
            ->get(['locum_profiles.id', 'locum_profiles.areas', 'locum_profiles.alerts_email', 'locum_profiles.alerts_sms', 'users.email', 'users.phone'])
            ->filter(function ($p) use ($shift): bool {
                if ($shift->invited_profile_id !== null) {
                    return (int) $p->id === (int) $shift->invited_profile_id;
                }
                $areas = array_map('mb_strtolower', (array) json_decode((string) $p->areas, true));

                return $shift->area !== null && in_array(mb_strtolower((string) $shift->area), $areas, true);
            });
        $sent = 0;
        foreach ($profiles as $p) {
            if ($this->db()->table('locum_alerts')->where('locum_profile_id', $p->id)->where('locum_shift_id', $shiftId)->exists()) {
                continue;
            }
            $when = CarbonImmutable::parse((string) $shift->starts_at)->format('D j M H:i');
            $text = ($shift->invited_profile_id !== null ? "{$practice} offered you a locum shift" : "New locum shift at {$practice}")." on {$when}. Sign in to Dr Business Flow to apply: ".rtrim((string) config('app.url'), '/').'/locum';
            if ((bool) $p->alerts_email && filled($p->email)) {
                app(SendMessage::class)->handle('email', (string) $p->email, $text, 'Locum shift: '.$practice);
            }
            if ((bool) $p->alerts_sms && filled($p->phone ?? null)) {
                app(SendMessage::class)->handle('sms', (string) $p->phone, $text);
            }
            $this->db()->table('locum_alerts')->insert(['locum_profile_id' => $p->id, 'locum_shift_id' => $shiftId, 'sent_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    /**
     * Day-before reminder to the locum and the practice owner for each booked shift.
     */
    public function remind(): int
    {
        $count = 0;
        foreach ($this->db()->table('locum_shifts')->where('status', 'filled')->whereNull('reminded_at')->whereBetween('starts_at', [now(), now()->addDay()])->get() as $shift) {
            $locum = $this->bookedUser((int) $shift->id);
            $when = CarbonImmutable::parse((string) $shift->starts_at)->format('D j M H:i');
            $practice = (string) Provider::query()->whereKey($shift->tenant_id)->value('name');
            if ($locum instanceof User) {
                app(SendMessage::class)->handle('email', $locum->email, "Reminder: your locum shift at {$practice} starts {$when}.", 'Shift reminder: '.$practice);
            }
            foreach ($this->owners((string) $shift->tenant_id) as $email) {
                app(SendMessage::class)->handle('email', $email, 'Reminder: '.($locum instanceof User ? $locum->name : 'your locum')." works the locum shift starting {$when}.", 'Locum shift tomorrow');
            }
            $this->db()->table('locum_shifts')->where('id', $shift->id)->update(['reminded_at' => now()]);
            $count++;
        }

        return $count;
    }

    public function submitHours(int $shiftId, int $profileId, string $start, string $end, int $breakMinutes): void
    {
        $shift = $this->bookedShift($shiftId);
        if ((int) $this->db()->table('locum_applications')->where('locum_shift_id', $shiftId)->where('status', 'accepted')->value('locum_profile_id') !== $profileId) {
            throw ValidationException::withMessages(['shift' => 'This is not your shift.']);
        }
        if (CarbonImmutable::parse((string) $shift->starts_at)->isFuture()) {
            throw ValidationException::withMessages(['shift' => 'Hours can be submitted once the shift has started.']);
        }
        if ($shift->hours_status === 'confirmed' || $shift->hours_status === 'adjusted') {
            throw ValidationException::withMessages(['shift' => 'The practice has already confirmed these hours.']);
        }
        [$s, $e] = $this->validHours($shift, $start, $end, $breakMinutes);
        $this->db()->table('locum_shifts')->where('id', $shiftId)->update(['worked_start' => $s, 'worked_end' => $e, 'break_minutes' => $breakMinutes, 'hours_status' => 'submitted', 'updated_at' => now()]);
    }

    /**
     * The practice confirms the submitted hours, or adjusts them with a reason; the invoice number is issued.
     */
    public function confirmHours(int $shiftId, Provider $provider, ?string $start = null, ?string $end = null, ?int $breakMinutes = null, ?string $note = null): void
    {
        $shift = $this->bookedShift($shiftId, $provider);
        if ($shift->hours_status !== 'submitted') {
            throw ValidationException::withMessages(['shift' => 'Wait for the locum to submit their hours.']);
        }
        $adjusted = $start !== null && $end !== null;
        if ($adjusted && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Say why the hours were changed.']);
        }
        [$s, $e] = $adjusted ? $this->validHours($shift, $start, $end, (int) $breakMinutes) : [CarbonImmutable::parse((string) $shift->worked_start), CarbonImmutable::parse((string) $shift->worked_end)];
        $break = $adjusted ? (int) $breakMinutes : (int) $shift->break_minutes;
        $minutes = max(0, (int) $s->diffInMinutes($e) - $break);
        $net = $shift->rate_basis === 'shift' ? (int) $shift->rate_cents : (int) round($shift->rate_cents * $minutes / 60);
        $vatNumber = $this->db()->table('locum_profiles')->where('id', $this->bookedProfileId($shiftId))->value('vat_number');
        $total = $net + (filled($vatNumber) ? (int) round($net * 0.15) : 0);

        $this->db()->transaction(function () use ($shiftId, $s, $e, $break, $adjusted, $note, $total): void {
            $prefix = 'LOC-'.now()->format('Y').'-';
            $next = $this->db()->table('locum_shifts')->where('invoice_number', 'like', $prefix.'%')->lockForUpdate()->count() + 1;
            $this->db()->table('locum_shifts')->where('id', $shiftId)->update([
                'worked_start' => $s, 'worked_end' => $e, 'break_minutes' => $break, 'hours_status' => $adjusted ? 'adjusted' : 'confirmed',
                'hours_note' => $adjusted ? trim((string) $note) : null, 'invoice_number' => $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                'invoice_total_cents' => $total, 'updated_at' => now(),
            ]);
        });
    }

    public function markPaid(int $shiftId, Provider $provider): void
    {
        $shift = $this->bookedShift($shiftId, $provider);
        if ($shift->invoice_number === null) {
            throw ValidationException::withMessages(['shift' => 'Confirm the hours first.']);
        }
        $this->db()->table('locum_shifts')->where('id', $shiftId)->update(['invoice_paid_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The locum's invoice to the practice for one shift (paid directly, not through Dr Business Flow).
     */
    public function invoicePdf(int $shiftId): string
    {
        $shift = $this->db()->table('locum_shifts')->where('id', $shiftId)->first();
        abort_if($shift === null || $shift->invoice_number === null, 404);
        $profile = $this->db()->table('locum_profiles')->where('id', $this->bookedProfileId($shiftId))->first();
        $locum = User::query()->whereKey((int) data_get($profile, 'user_id'))->first();
        $practice = Provider::query()->whereKey((string) $shift->tenant_id)->first();
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $s = CarbonImmutable::parse((string) $shift->worked_start);
        $en = CarbonImmutable::parse((string) $shift->worked_end);
        $hours = round(max(0, $s->diffInMinutes($en) - (int) $shift->break_minutes) / 60, 2);
        $vat = filled(data_get($profile, 'vat_number'));
        $total = (int) $shift->invoice_total_cents;
        $net = $vat ? (int) round($total / 1.15) : $total;
        $money = fn (int $c) => 'R '.number_format($c / 100, 2, '.', ' ');
        $html = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 11px">'
            .'<h2>'.($vat ? 'Tax invoice' : 'Invoice').' '.$e($shift->invoice_number).'</h2>'
            .'<p><b>From:</b> '.$e($locum?->name).' · HPCSA '.$e(data_get($profile, 'hpcsa_number')).($vat ? ' · VAT '.$e(data_get($profile, 'vat_number')) : '').'</p>'
            .'<p><b>To:</b> '.$e($practice?->name).'</p>'
            .'<p><b>Shift:</b> '.$e($shift->title).' · '.$s->format('j M Y H:i').'–'.$en->format('H:i').' · break '.(int) $shift->break_minutes.' min · '.$hours.' h'
            .($shift->hours_status === 'adjusted' ? ' (adjusted by the practice: '.$e($shift->hours_note).')' : '').'</p>'
            .'<p><b>Rate:</b> '.$money((int) $shift->rate_cents).' per '.$e($shift->rate_basis).'</p>'
            .'<p>Amount: '.$money($net).($vat ? '<br>VAT 15%: '.$money($total - $net) : '').'<br><b>Total due: '.$money($total).'</b></p>'
            .'<p style="color:#666">Paid directly by the practice to the locum — not through Dr Business Flow.'.($shift->invoice_paid_at !== null ? ' Marked paid by the practice on '.substr((string) $shift->invoice_paid_at, 0, 10).'.' : '').'</p></body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->render();

        return (string) $pdf->output();
    }

    /**
     * Either side cancels a booked shift before it starts: access and the roster session are removed;
     * cancelling within 24 hours of the start is recorded as late.
     */
    public function cancelBooked(int $shiftId, string $by, string $reason): void
    {
        $shift = $this->bookedShift($shiftId);
        if (CarbonImmutable::parse((string) $shift->starts_at)->isPast()) {
            throw ValidationException::withMessages(['shift' => 'A shift that has started cannot be cancelled.']);
        }
        if (trim($reason) === '' || ! in_array($by, ['practice', 'locum'], true)) {
            throw ValidationException::withMessages(['reason' => 'Give a reason for cancelling.']);
        }
        $user = $this->bookedUser($shiftId);
        $late = CarbonImmutable::parse((string) $shift->starts_at)->lt(now()->addHours(self::LATE_HOURS));

        $this->db()->transaction(function () use ($shiftId, $by, $reason, $late): void {
            $this->db()->table('locum_shifts')->where('id', $shiftId)->update(['status' => 'cancelled', 'cancelled_by' => $by, 'cancel_reason' => trim($reason),
                'cancelled_at' => now(), 'late_cancellation' => $late, 'updated_at' => now()]);
            $this->db()->table('locum_applications')->where('locum_shift_id', $shiftId)->where('status', 'accepted')->update(['status' => 'cancelled', 'updated_at' => now()]);
        });

        $provider = Provider::query()->findOrFail((string) $shift->tenant_id);
        if ($user instanceof User) {
            $provider->run(fn () => RosterSession::query()->where('staff_id', $user->id)->where('starts_at', $shift->starts_at)->where('ends_at', $shift->ends_at)->delete());
            $stillBooked = $this->db()->table('locum_shifts')->join('locum_applications', 'locum_applications.locum_shift_id', '=', 'locum_shifts.id')
                ->where('locum_shifts.tenant_id', $provider->id)->where('locum_applications.status', 'accepted')->where('locum_shifts.ends_at', '>', now())
                ->where('locum_applications.locum_profile_id', $this->db()->table('locum_profiles')->where('user_id', $user->id)->value('id'))->exists();
            if (! $stillBooked) {
                Membership::query()->where('user_id', $user->id)->where('tenant_id', $provider->id)->where('role', StaffRole::LocumDoctor->value)->update(['expires_at' => now()]);
            }
            $other = $by === 'practice' ? [$user->email] : $this->owners($provider->id);
            foreach ($other as $email) {
                app(SendMessage::class)->handle('email', $email, 'The locum shift on '.CarbonImmutable::parse((string) $shift->starts_at)->format('D j M H:i')." at {$provider->name} was cancelled by the {$by}: ".trim($reason), 'Locum shift cancelled');
            }
        }
        activity('locums')->withProperties(['shift' => $shiftId, 'by' => $by, 'late' => $late])->log('Locum shift cancelled');
    }

    /**
     * Private note: only the practice that made it and the super admin see it.
     */
    public function rebook(int $shiftId, Provider $provider, bool $again, ?string $note): void
    {
        $shift = $this->bookedShift($shiftId, $provider);
        if (CarbonImmutable::parse((string) $shift->ends_at)->isFuture()) {
            throw ValidationException::withMessages(['shift' => 'You can note this once the shift has ended.']);
        }
        $this->db()->table('locum_shifts')->where('id', $shiftId)->update(['rebook' => $again, 'rebook_note' => $note === null ? null : mb_substr(trim($note), 0, 255), 'updated_at' => now()]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function validHours(\stdClass $shift, string $start, string $end, int $breakMinutes): array
    {
        $s = CarbonImmutable::parse($start);
        $e = CarbonImmutable::parse($end);
        $from = CarbonImmutable::parse((string) data_get($shift, 'starts_at'))->subHours(12);
        $to = CarbonImmutable::parse((string) data_get($shift, 'ends_at'))->addHours(12);
        if ($e->lte($s) || $s->lt($from) || $e->gt($to) || $breakMinutes < 0 || $breakMinutes >= $s->diffInMinutes($e)) {
            throw ValidationException::withMessages(['hours' => 'Enter the times actually worked (around the shift) and a break shorter than the shift.']);
        }

        return [$s, $e];
    }

    private function bookedShift(int $shiftId, ?Provider $provider = null): \stdClass
    {
        $shift = $this->db()->table('locum_shifts')->where('id', $shiftId)->first();
        if ($shift === null || $shift->status !== 'filled' || ($provider !== null && $shift->tenant_id !== $provider->id)) {
            throw ValidationException::withMessages(['shift' => 'This booked shift was not found.']);
        }

        return $shift;
    }

    private function bookedProfileId(int $shiftId): int
    {
        return (int) $this->db()->table('locum_applications')->where('locum_shift_id', $shiftId)->whereIn('status', ['accepted', 'cancelled'])->value('locum_profile_id');
    }

    private function bookedUser(int $shiftId): ?User
    {
        return User::query()->whereKey((int) $this->db()->table('locum_profiles')->where('id', $this->bookedProfileId($shiftId))->value('user_id'))->first();
    }

    /**
     * @return list<string>
     */
    private function owners(string $tenantId): array
    {
        return array_values(User::query()->whereIn('id', Membership::query()->where('tenant_id', $tenantId)->where('role', StaffRole::Owner->value)->pluck('user_id'))->pluck('email')->all());
    }
}
