<?php

declare(strict_types=1);

namespace App\Domains\Hub\Actions;

use App\Domains\Hub\Models\HubConsent;
use App\Domains\Hub\Models\HubIdentity;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Platform\Models\Provider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Share-history consent: which categories of the patient's history a linked
 * practice may see, optionally until a date. Given with a code to the
 * patient's phone (at the desk) or in the portal; revocable at any time.
 */
final class ShareConsent
{
    public const CATEGORIES = ['allergies', 'medicines', 'problems', 'results', 'notes'];

    public function requestCode(HubIdentity $identity, Provider $provider): void
    {
        $code = (string) random_int(100000, 999999);
        Cache::put($this->key($identity, $provider), Hash::make($code), now()->addMinutes(10));
        app(SendMessage::class)->handle('sms', $identity->cell, "{$provider->name} asks to see parts of your health history on Clinic Flow. Code {$code}. Only share it at {$provider->name}.", null, 'share_consent', $identity->id);
    }

    /**
     * @param  list<string>  $categories
     */
    public function confirmCode(HubIdentity $identity, Provider $provider, string $code, array $categories, ?string $until = null): HubConsent
    {
        $hash = Cache::get($this->key($identity, $provider));
        if (! is_string($hash) || ! Hash::check($code, $hash)) {
            throw ValidationException::withMessages(['code' => 'That code is not correct or has expired.']);
        }
        Cache::forget($this->key($identity, $provider));

        return $this->grant($identity, $provider->id, $categories, $until, 'otp');
    }

    /**
     * @param  list<string>  $categories
     */
    public function grant(HubIdentity $identity, string $tenantId, array $categories, ?string $until, string $via): HubConsent
    {
        $categories = array_values(array_intersect(self::CATEGORIES, $categories));
        if ($categories === []) {
            throw ValidationException::withMessages(['categories' => 'Choose what may be shared.']);
        }
        $this->revoke($identity, $tenantId);

        return HubConsent::create([
            'identity_id' => $identity->id, 'tenant_id' => $tenantId, 'scope' => HubConsent::SHARE_HISTORY, 'captured_via' => $via,
            'categories' => $categories, 'expires_at' => $until, 'granted_at' => now(),
        ]);
    }

    public function revoke(HubIdentity $identity, string $tenantId): void
    {
        HubConsent::query()->where('identity_id', $identity->id)->where('tenant_id', $tenantId)->where('scope', HubConsent::SHARE_HISTORY)
            ->whereNull('withdrawn_at')->update(['withdrawn_at' => now()]);
    }

    /**
     * @return list<string> categories currently shared with this practice
     */
    public function categories(?string $identityId, string $tenantId): array
    {
        if ($identityId === null) {
            return [];
        }
        $consent = HubConsent::query()->where('identity_id', $identityId)->where('tenant_id', $tenantId)->where('scope', HubConsent::SHARE_HISTORY)
            ->whereNull('withdrawn_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->first();

        return $consent === null ? [] : array_values((array) $consent->getAttribute('categories'));
    }

    private function key(HubIdentity $identity, Provider $provider): string
    {
        return "share-consent:{$identity->id}:{$provider->id}";
    }
}
