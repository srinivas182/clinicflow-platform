<?php

declare(strict_types=1);

namespace App\Domains\Hub\Actions;

use App\Domains\Hub\Models\HubConsent;
use App\Domains\Hub\Models\HubIdentity;
use App\Domains\Hub\Models\HubLink;
use App\Domains\Hub\Models\HubLinkRequest;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Support\SaIdNumber;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Network Hub rules:
 *  - one cell number = one identity across the network;
 *  - a new patient with an unknown cell or SA ID creates the identity and is linked;
 *  - a provider that finds an existing identity sees a masked match only, and
 *    links (creating its own local record) only after the patient approves
 *    with a code sent to their own phone;
 *  - the patient can revoke a provider's link at any time.
 */
class NetworkIdentity
{
    public function __construct(private readonly SendMessage $messages) {}

    /**
     * Called after local registration. Links when the person is new to the network;
     * returns the existing identity (unlinked) when they are already known elsewhere.
     */
    public function register(Patient $patient, Provider $provider): ?HubIdentity
    {
        if ($patient->cell === null) {
            return null;
        }

        $existing = $this->find($patient->cell, $patient->id_number);
        if ($existing instanceof HubIdentity) {
            return $existing;
        }

        return DB::connection('hub')->transaction(function () use ($patient, $provider): HubIdentity {
            $identity = HubIdentity::create([
                'cell' => $patient->cell,
                'sa_id_hash' => $patient->id_type === IdType::SaId && $patient->id_number !== null ? SaIdNumber::tryParse($patient->id_number)?->lookupHash() : null,
                'first_names' => $patient->first_names,
                'surname' => $patient->surname,
                'date_of_birth' => $patient->date_of_birth,
            ]);
            $this->link($identity, $provider->id, $patient, 'registration');

            return $identity;
        });
    }

    public function find(?string $cell, ?string $saId = null): ?HubIdentity
    {
        $hash = $saId !== null ? SaIdNumber::tryParse($saId)?->lookupHash() : null;

        return HubIdentity::query()
            ->when($cell !== null, fn ($q) => $q->where('cell', preg_replace('/\D/', '', (string) $cell)))
            ->when($hash !== null, fn ($q) => $cell === null ? $q->where('sa_id_hash', $hash) : $q->orWhere('sa_id_hash', $hash))
            ->first();
    }

    public function isLinked(HubIdentity $identity, string $providerId): bool
    {
        return HubLink::query()->where('identity_id', $identity->id)->where('tenant_id', $providerId)->where('status', 'active')->exists();
    }

    /**
     * Sends the patient a code to approve linking with this provider.
     */
    public function requestLink(HubIdentity $identity, Provider $provider, ?User $by = null): HubLinkRequest
    {
        if ($this->isLinked($identity, $provider->id)) {
            throw ValidationException::withMessages(['identity' => 'This patient is already linked to your practice.']);
        }

        $code = (string) random_int(100000, 999999);
        HubLinkRequest::query()->where('identity_id', $identity->id)->where('tenant_id', $provider->id)->whereNull('approved_at')->delete();
        $request = HubLinkRequest::create([
            'identity_id' => $identity->id, 'tenant_id' => $provider->id, 'code_hash' => Hash::make($code),
            'requested_by' => $by?->id, 'expires_at' => now()->addMinutes(10),
        ]);

        $this->messages->template('network.link_code', 'sms', $identity->cell, ['practice' => $provider->name, 'code' => $code], 'en', 'hub_link_request', (string) $request->id);

        return $request;
    }

    /**
     * Patient read the code to reception: link and create the local record.
     */
    public function confirmLink(HubLinkRequest $request, string $code, Provider $provider): Patient
    {
        if ($request->tenant_id !== $provider->id || $request->approved_at !== null || $request->expires_at->isPast() || $request->attempts >= HubLinkRequest::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Send a new one.']);
        }
        if (! Hash::check($code, $request->code_hash)) {
            $request->increment('attempts');
            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }

        $identity = HubIdentity::query()->findOrFail($request->identity_id);
        $request->forceFill(['approved_at' => now()])->save();

        $patient = Patient::query()->where('cell', $identity->cell)->first() ?? Patient::create([
            'first_names' => $identity->first_names,
            'surname' => $identity->surname,
            'id_type' => IdType::None,
            'date_of_birth' => $identity->date_of_birth,
            'cell' => $identity->cell,
            'no_cell' => false,
            'preferred_language' => 'en',
            'preferred_channel' => Channel::Sms,
            'hub_identity_id' => $identity->id,
            'needs_consent' => true,
        ]);
        $patient->forceFill(['hub_identity_id' => $identity->id])->save();

        $this->link($identity, $provider->id, $patient, 'otp');
        activity('hub')->performedOn($patient)->withProperties(['identity' => $identity->id])->log('Linked to network identity with patient approval');

        return $patient;
    }

    public function revoke(HubIdentity $identity, string $providerId): void
    {
        HubLink::query()->where('identity_id', $identity->id)->where('tenant_id', $providerId)->where('status', 'active')
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        HubConsent::query()->where('identity_id', $identity->id)->where('tenant_id', $providerId)->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now()]);
    }

    /**
     * @return array<int, array{provider: string, linked_at: string, provider_id: string}>
     */
    public function linkedProviders(HubIdentity $identity): array
    {
        $links = HubLink::query()->where('identity_id', $identity->id)->where('status', 'active')->get();
        $names = Provider::query()->whereIn('id', $links->pluck('tenant_id'))->pluck('name', 'id');

        return $links->map(fn (HubLink $l) => ['provider' => (string) ($names[$l->tenant_id] ?? 'A practice'), 'linked_at' => $l->linked_at->toDateString(), 'provider_id' => $l->tenant_id])->values()->all();
    }

    private function link(HubIdentity $identity, string $providerId, Patient $patient, string $via): void
    {
        HubLink::query()->updateOrCreate(['tenant_id' => $providerId, 'patient_id' => $patient->id], [
            'identity_id' => $identity->id, 'status' => 'active', 'linked_at' => now(), 'revoked_at' => null,
        ]);
        HubConsent::create(['identity_id' => $identity->id, 'tenant_id' => $providerId, 'scope' => HubConsent::LINK, 'captured_via' => $via, 'granted_at' => now()]);
        $patient->forceFill(['hub_identity_id' => $identity->id])->save();
    }
}
