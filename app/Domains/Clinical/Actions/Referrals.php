<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Actions;

use App\Domains\Clinical\Models\Referral;
use App\Domains\Clinical\Support\AccessLog;
use App\Domains\Clinical\Support\ClinicalSummary;
use App\Domains\Hub\Actions\ShareConsent;
use App\Domains\Hub\Models\HubLink;
use App\Domains\Hub\Models\HubReferral;
use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use Illuminate\Validation\ValidationException;

/**
 * Structured referrals. To a network practice: the clinical summary contains only
 * the categories the patient consented to share with that practice; status and
 * feedback flow back. To anyone else: a printable referral letter.
 */
class Referrals
{
    public const SPECIALTIES = ['Cardiology', 'Dermatology', 'Endocrinology', 'ENT', 'Gastroenterology', 'General surgery', 'Gynaecology and obstetrics', 'Nephrology',
        'Neurology', 'Oncology', 'Ophthalmology', 'Orthopaedics', 'Paediatrics', 'Physiotherapy', 'Psychiatry', 'Psychology', 'Pulmonology', 'Radiology', 'Rheumatology', 'Urology', 'Other'];

    public function __construct(private readonly ShareConsent $consent) {}

    /**
     * @param  list<string>  $categories  categories the doctor wants to include (limited to the patient's consent for network referrals)
     */
    public function refer(Patient $patient, Staff $doctor, ?string $toTenantId, string $toName, string $specialty, string $urgency, string $reason, array $categories, ?string $consultationId = null): Referral
    {
        if (! in_array($urgency, ['routine', 'soon', 'urgent'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give the reason and urgency for the referral.']);
        }
        $here = $this->provider();
        $target = $toTenantId === null ? null : Provider::query()->findOrFail($toTenantId);

        if ($target instanceof Provider) {
            $identity = $patient->getAttribute('hub_identity_id');
            $linked = is_string($identity) && HubLink::query()->where('identity_id', $identity)->where('tenant_id', $here->id)->where('status', 'active')->exists();
            if (! $linked) {
                throw ValidationException::withMessages(['to_tenant_id' => 'Link the patient to the network before referring to a network practice.']);
            }
            $categories = array_values(array_intersect($categories, $this->consent->categories($identity, $target->id)));
        }

        $referral = Referral::create([
            'direction' => 'out', 'patient_id' => $patient->id, 'consultation_id' => $consultationId, 'staff_id' => $doctor->id,
            'other_tenant_id' => $target?->id, 'other_name' => $target instanceof Provider ? $target->name : trim($toName), 'specialty' => $specialty, 'urgency' => $urgency,
            'reason' => trim($reason), 'shared_categories' => $categories, 'summary' => ClinicalSummary::build($patient->id, $categories),
            'status' => 'sent',
        ]);
        AccessLog::record($patient->id, 'shared', "Referred to {$referral->other_name} ({$specialty})".($categories === [] ? '' : '; shared: '.implode(', ', $categories)));

        if ($target instanceof Provider) {
            $identity = (string) $patient->getAttribute('hub_identity_id');
            $hub = HubReferral::create(['identity_id' => $identity, 'from_tenant_id' => $here->id, 'from_referral_id' => $referral->id, 'to_tenant_id' => $target->id, 'status' => 'sent']);
            $doctorName = $doctor->name;
            $inId = $target->run(function () use ($referral, $identity, $here, $patient, $hub, $doctorName): string {
                $local = Patient::query()->where('hub_identity_id', $identity)->first() ?? Patient::create([
                    'first_names' => $patient->first_names, 'surname' => $patient->surname, 'id_type' => IdType::None, 'date_of_birth' => $patient->date_of_birth,
                    'sex' => $patient->sex, 'cell' => $patient->cell, 'no_cell' => $patient->cell === null, 'preferred_language' => 'en',
                    'preferred_channel' => Channel::Sms, 'hub_identity_id' => $identity, 'needs_consent' => true,
                ]);
                $in = Referral::create([
                    'direction' => 'in', 'patient_id' => $local->id, 'other_tenant_id' => $here->id, 'other_name' => "{$doctorName}, {$here->name}",
                    'specialty' => $referral->specialty, 'urgency' => $referral->urgency, 'reason' => $referral->reason,
                    'shared_categories' => $referral->shared_categories, 'summary' => $referral->summary, 'status' => 'sent', 'hub_referral_id' => $hub->id,
                ]);
                AccessLog::record($local->id, 'shared', "{$here->name} referred you here ({$referral->specialty})");

                return $in->id;
            });
            $hub->forceFill(['to_referral_id' => $inId])->save();
            $referral->forceFill(['hub_referral_id' => $hub->id])->save();
        }

        return $referral;
    }

    /**
     * Receiving practice moves the referral on; status and feedback flow back to the referrer.
     */
    public function update(Referral $referral, string $action, ?string $value = null): Referral
    {
        if ($referral->direction !== 'in') {
            throw ValidationException::withMessages(['referral' => 'Only the practice that received the referral can update it.']);
        }
        $changes = match ($action) {
            'accept' => ['status' => 'accepted'],
            'decline' => ['status' => 'declined', 'feedback' => $value],
            'book' => ['status' => 'booked', 'appointment_at' => $value],
            'seen' => ['status' => 'seen'],
            'feedback' => ['status' => 'feedback', 'feedback' => $value, 'feedback_at' => now()],
            default => throw ValidationException::withMessages(['action' => 'Unknown action.']),
        };
        if (in_array($action, ['decline', 'feedback'], true) && trim((string) $value) === '') {
            throw ValidationException::withMessages(['value' => 'Write the feedback for the referring doctor.']);
        }
        $referral->forceFill($changes)->save();

        $hub = HubReferral::query()->find($referral->hub_referral_id);
        if ($hub instanceof HubReferral) {
            $hub->forceFill(['status' => $changes['status']])->save();
            Provider::query()->findOrFail($hub->from_tenant_id)->run(function () use ($hub, $changes): void {
                $out = Referral::query()->findOrFail($hub->from_referral_id);
                $out->forceFill($changes)->save();
                if ($changes['status'] === 'feedback') {
                    AccessLog::record($out->patient_id, 'discussed', "{$out->other_name} sent feedback on your referral");
                }
            });
        }

        return $referral;
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
