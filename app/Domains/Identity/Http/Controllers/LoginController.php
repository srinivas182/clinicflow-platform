<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\RecordSignIn;
use App\Domains\Identity\Actions\StartLogin;
use App\Domains\Identity\Actions\TrustedDevices;
use App\Domains\Identity\Actions\VerifyLoginChallenge;
use App\Domains\Identity\Http\Requests\LoginRequest;
use App\Domains\Identity\Models\LoginChallenge;
use App\Domains\Identity\Support\TwoFactorPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central sign-in (accounts domain): password, then a one-time code.
 */
class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, StartLogin $action): RedirectResponse
    {
        $user = $action->checkPassword($request->string('login')->toString(), $request->string('password')->toString(), $request->ip());
        // A device the user trusted in the last 30 days skips the code step (never for super admins).
        $devices = app(TrustedDevices::class);
        $cookie = $request->cookie($devices::COOKIE);
        $twoStepOff = ! TwoFactorPolicy::enabled();
        if ($twoStepOff || $devices->trusted($user, is_string($cookie) ? $cookie : null)) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $user->forceFill(['last_login_at' => now()])->save();
            activity('auth')->causedBy($user)->log($twoStepOff ? 'Signed in (two-step sign-in is off)' : 'Signed in on a trusted device');
            app(RecordSignIn::class)->handle($user, (string) $request->ip(), (string) $request->userAgent());

            return redirect()->route('workspaces');
        }
        $challenge = $action->challenge($user, $request->ip());

        $request->session()->put('login_challenge', $challenge->id);

        return redirect()->route('login.verify');
    }

    public function verifyForm(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login_challenge')) {
            return redirect()->route('login');
        }

        $challenge = LoginChallenge::query()->find((string) $request->session()->get('login_challenge'));

        return Inertia::render('Auth/VerifyCode', ['method' => $challenge?->getAttribute('method') ?? 'message']);
    }

    public function verify(Request $request, VerifyLoginChallenge $action): RedirectResponse
    {
        // Six digits (message or authenticator app) or a recovery code (xxxxx-xxxxx).
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        $user = $action->handle((string) $request->session()->get('login_challenge'), $request->string('code')->toString());

        Auth::guard('web')->login($user);
        $request->session()->forget('login_challenge');
        $request->session()->regenerate();
        app(RecordSignIn::class)->handle($user, (string) $request->ip(), (string) $request->userAgent());
        $response = redirect()->route('workspaces');
        $devices = app(TrustedDevices::class);
        if ($request->boolean('trust_device') && $devices->allowed($user)) {
            $response->withCookie($devices->remember($user, (string) $request->userAgent()));
        }

        return $response;
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
