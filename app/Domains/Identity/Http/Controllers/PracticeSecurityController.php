<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Practice Settings → Security (owner only): require an authenticator app for all staff.
 */
class PracticeSecurityController extends Controller
{
    public function show(Request $request): Response
    {
        $provider = $this->ownerPractice($request);
        $staffIds = Membership::query()->where('tenant_id', $provider->id)->usable()->pluck('user_id');

        return Inertia::render('Settings/Security', [
            // Read fresh: the resolved practice record may be cached by the tenancy package.
            'required' => (bool) Provider::query()->whereKey($provider->id)->value('require_authenticator'),
            'staff' => $staffIds->count(),
            'withoutApp' => User::query()->whereIn('id', $staffIds)->whereNull('totp_confirmed_at')->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $provider = $this->ownerPractice($request);
        $on = $request->boolean('required');
        Provider::query()->whereKey($provider->id)->update(['require_authenticator' => $on]);
        activity('security')->withProperties(['require_authenticator' => $on])->log($on ? 'Authenticator app required for all staff' : 'Authenticator app no longer required for all staff');

        return back()->with('success', $on ? 'All staff must now use an authenticator app. Those without one are asked to set it up when they next open Clinic Flow.' : 'Saved.');
    }

    private function ownerPractice(Request $request): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $isOwner = Membership::query()->where('tenant_id', $provider->id)->where('user_id', (int) $request->user()?->getAuthIdentifier())
            ->where('role', StaffRole::Owner->value)->usable()->exists();
        abort_unless($isOwner, 403, 'Only the practice owner can change this.');

        return $provider;
    }
}
