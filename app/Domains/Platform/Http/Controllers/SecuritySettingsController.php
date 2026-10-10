<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Identity\Support\TwoFactorPolicy;
use App\Domains\Messaging\Models\MessagingProvider;
use App\Domains\Wallet\Support\WalletSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Security: two-step sign-in on or off, and the order of methods.
 */
class SecuritySettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('Admin/Security', [
            'enabled' => TwoFactorPolicy::enabled(),
            'methods' => TwoFactorPolicy::methods(),
            'suppliers' => ['email' => MessagingProvider::activeFor('email') !== null, 'sms' => MessagingProvider::activeFor('sms') !== null],
            'me' => ['authenticator' => $user->totp_confirmed_at !== null, 'email' => $user->email !== '', 'sms' => is_string($user->phone) && $user->phone !== ''],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'methods' => ['required', 'array', 'min:1'], 'methods.*' => ['string', 'in:'.implode(',', TwoFactorPolicy::METHODS)]]);
        $methods = array_values(array_unique($data['methods']));
        $enabled = (bool) $data['enabled'];
        // Never let the admin lock themselves out.
        if ($enabled && ! TwoFactorPolicy::usableBy($user, $methods)) {
            throw ValidationException::withMessages(['methods' => 'You could not sign in with these settings. Set up your authenticator app (Account → Security), or add an email or SMS supplier (Messaging), first.']);
        }
        WalletSettings::put('security.two_factor_enabled', $enabled);
        WalletSettings::put('security.two_factor_methods', $methods);
        activity('security')->causedBy($user)->withProperties(['enabled' => $enabled, 'methods' => $methods])
            ->log($enabled ? 'Two-step sign-in switched on' : 'Two-step sign-in switched off');

        return back()->with('success', $enabled ? 'Two-step sign-in is on.' : 'Two-step sign-in is off — staff sign in with a password only. Switch it on before real patient data.');
    }
}
