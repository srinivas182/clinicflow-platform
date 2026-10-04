<?php

declare(strict_types=1);

namespace App\Domains\Wellness\Actions;

use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Patients\Actions\RegisterPatient;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Support\RegistrationData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Employer wellness days. Employees register themselves (with their own consent);
 * their results belong to them and are shown to them only. The employer never
 * sees individual results (reports in 17B-2 are anonymised totals).
 * Risk thresholds are DEMO values for the clinical reviewer to confirm.
 */
class CorporateWellness
{
    public const SERVICES = ['bp', 'glucose', 'cholesterol', 'bmi', 'flu'];

    /**
     * @param  array{name: string, contact_name: ?string, contact_email: ?string, contact_phone: ?string, billing_address: ?string, vat_number: ?string, rate_cents: int}  $data
     */
    public function saveAccount(?int $id, array $data): int
    {
        $row = $data + ['updated_at' => now()];
        if ($id === null) {
            return (int) DB::table('corporate_accounts')->insertGetId($row + ['active' => true, 'created_at' => now()]);
        }
        DB::table('corporate_accounts')->where('id', $id)->update($row);

        return $id;
    }

    /**
     * @param  list<string>  $services
     */
    public function createEvent(int $accountId, string $title, string $location, string $start, string $end, int $slotMinutes, int $perSlot, array $services): int
    {
        $s = CarbonImmutable::parse($start);
        $e = CarbonImmutable::parse($end);
        $services = array_values(array_intersect(self::SERVICES, $services));
        if ($s->isPast() || $e->lte($s) || $services === [] || $slotMinutes < 5 || $perSlot < 1) {
            throw ValidationException::withMessages(['starts_at' => 'Choose a future time window, slot length and at least one screening service.']);
        }
        if (! DB::table('corporate_accounts')->where('id', $accountId)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['corporate_account_id' => 'Choose an active corporate account.']);
        }

        return (int) DB::table('wellness_events')->insertGetId(['corporate_account_id' => $accountId, 'title' => trim($title), 'location' => trim($location),
            'starts_at' => $s, 'ends_at' => $e, 'slot_minutes' => $slotMinutes, 'per_slot' => $perSlot, 'services' => json_encode($services),
            'token' => Str::random(32), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return list<array{at: string, free: int}>
     */
    public function slots(int $eventId): array
    {
        $event = DB::table('wellness_events')->where('id', $eventId)->first();
        if ($event === null) {
            return [];
        }
        $taken = DB::table('wellness_registrations')->where('wellness_event_id', $eventId)->where('status', '!=', 'cancelled')
            ->selectRaw('slot_at, COUNT(*) as n')->groupBy('slot_at')->pluck('n', 'slot_at');
        $out = [];
        for ($t = CarbonImmutable::parse((string) $event->starts_at); $t->lt(CarbonImmutable::parse((string) $event->ends_at)); $t = $t->addMinutes((int) $event->slot_minutes)) {
            $out[] = ['at' => $t->toDateTimeString(), 'free' => max(0, (int) $event->per_slot - (int) ($taken[$t->toDateTimeString()] ?? 0))];
        }

        return $out;
    }

    /**
     * An employee registers with their own consent; matched to an existing patient by cell number, otherwise registered.
     *
     * @param  array{first_names: string, surname: string, id_number: ?string, date_of_birth: ?string, cell: string, email: ?string, slot_at: string, consent: bool}  $data
     */
    public function register(string $token, array $data): int
    {
        $event = DB::table('wellness_events')->where('token', $token)->where('status', 'open')->first();
        if ($event === null || CarbonImmutable::parse((string) $event->ends_at)->isPast()) {
            throw ValidationException::withMessages(['event' => 'Registration for this wellness day is closed.']);
        }
        if (! $data['consent']) {
            throw ValidationException::withMessages(['consent' => 'Please agree to the consent to take part. Taking part is voluntary.']);
        }
        $slot = collect($this->slots((int) $event->id))->firstWhere('at', CarbonImmutable::parse($data['slot_at'])->toDateTimeString());
        if ($slot === null || $slot['free'] < 1) {
            throw ValidationException::withMessages(['slot_at' => 'That time is full. Choose another.']);
        }

        return DB::transaction(function () use ($event, $data, $slot): int {
            $patient = Patient::query()->where('cell', $data['cell'])->orderBy('created_at')->first()
                ?? app(RegisterPatient::class)->handle(new RegistrationData(
                    firstNames: trim($data['first_names']), surname: trim($data['surname']),
                    idType: filled($data['id_number']) ? IdType::SaId : IdType::None, idNumber: filled($data['id_number']) ? (string) $data['id_number'] : null,
                    passportCountry: null, dateOfBirth: filled($data['id_number']) || $data['date_of_birth'] === null ? null : CarbonImmutable::parse($data['date_of_birth']),
                    cell: $data['cell'], noCell: false, email: $data['email'], preferredLanguage: 'en', preferredChannel: Channel::Sms, address: null,
                    guardianName: null, guardianRelationship: null, guardianCell: null, popiaConsent: true, treatmentConsent: true,
                    consentGivenBy: ConsentGivenBy::Patient, maturityConfirmed: false, medicalAidScheme: null,
                ));
            if (DB::table('wellness_registrations')->where('wellness_event_id', $event->id)->where('patient_id', $patient->id)->exists()) {
                throw ValidationException::withMessages(['cell' => 'You are already registered for this wellness day.']);
            }
            $id = (int) DB::table('wellness_registrations')->insertGetId(['wellness_event_id' => $event->id, 'patient_id' => $patient->id, 'slot_at' => $slot['at'],
                'consented_at' => now(), 'status' => 'registered', 'created_at' => now(), 'updated_at' => now()]);
            app(SendMessage::class)->handle('sms', (string) $patient->cell, "You're booked for {$event->title} on ".CarbonImmutable::parse($slot['at'])->format('D j M H:i')." at {$event->location}. Your results are private to you.", null, 'wellness_registration', (string) $id);

            return $id;
        });
    }

    /**
     * @param  array{bp_systolic?: ?int, bp_diastolic?: ?int, glucose?: ?float, cholesterol?: ?float, height_cm?: ?float, weight_kg?: ?float, flu_vaccinated?: bool}  $v
     * @return list<string>
     */
    public function screen(int $registrationId, array $v, int $by): array
    {
        $reg = DB::table('wellness_registrations')->where('id', $registrationId)->first();
        if ($reg === null || $reg->status === 'cancelled') {
            throw ValidationException::withMessages(['registration' => 'Registration not found.']);
        }
        $bmi = ! empty($v['height_cm']) && ! empty($v['weight_kg']) ? round((float) $v['weight_kg'] / (((float) $v['height_cm'] / 100) ** 2), 1) : null;
        $flags = self::flags($v['bp_systolic'] ?? null, $v['bp_diastolic'] ?? null, $v['glucose'] ?? null, $v['cholesterol'] ?? null, $bmi);

        DB::transaction(function () use ($registrationId, $v, $bmi, $flags, $by): void {
            DB::table('wellness_screenings')->updateOrInsert(['wellness_registration_id' => $registrationId], [
                'bp_systolic' => $v['bp_systolic'] ?? null, 'bp_diastolic' => $v['bp_diastolic'] ?? null, 'glucose' => $v['glucose'] ?? null, 'cholesterol' => $v['cholesterol'] ?? null,
                'height_cm' => $v['height_cm'] ?? null, 'weight_kg' => $v['weight_kg'] ?? null, 'bmi' => $bmi, 'flu_vaccinated' => (bool) ($v['flu_vaccinated'] ?? false),
                'flags' => json_encode($flags), 'recorded_by' => $by, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('wellness_registrations')->where('id', $registrationId)->update(['status' => 'screened', 'updated_at' => now()]);
        });

        $patient = Patient::query()->find($reg->patient_id);
        if ($patient instanceof Patient && filled($patient->cell)) {
            $text = $flags === []
                ? 'Your wellness screening results are ready in your patient portal. All checks were in the healthy range.'
                : 'Your wellness screening results are ready in your patient portal. Some results need a follow-up — please book a visit.';
            app(SendMessage::class)->handle('sms', (string) $patient->cell, $text, null, 'wellness_registration', (string) $registrationId);
        }

        return $flags;
    }

    /**
     * DEMO thresholds (adult, random sampling) — to be confirmed by the clinical reviewer.
     *
     * @return list<string>
     */
    public static function flags(?int $sys, ?int $dia, ?float $glucose, ?float $chol, ?float $bmi): array
    {
        $f = [];
        if ($sys !== null && $dia !== null) {
            if ($sys >= 140 || $dia >= 90) {
                $f[] = 'bp_high';
            } elseif ($sys >= 130 || $dia >= 85) {
                $f[] = 'bp_elevated';
            }
        }
        if ($glucose !== null) {
            if ($glucose >= 11.1) {
                $f[] = 'glucose_high';
            } elseif ($glucose >= 7.8) {
                $f[] = 'glucose_raised';
            }
        }
        if ($chol !== null) {
            if ($chol >= 6.2) {
                $f[] = 'cholesterol_high';
            } elseif ($chol >= 5.0) {
                $f[] = 'cholesterol_raised';
            }
        }
        if ($bmi !== null) {
            $f[] = match (true) {
                $bmi >= 30 => 'bmi_obese', $bmi >= 25 => 'bmi_overweight', $bmi < 18.5 => 'bmi_underweight', default => null,
            };
        }

        return array_values(array_filter($f));
    }
}
