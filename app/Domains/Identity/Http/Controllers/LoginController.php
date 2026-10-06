<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\RecordSignIn;
use App\Domains\Identity\Actions\StartLogin;
use App\Domains\Identity\Actions\VerifyLoginChallenge;
use App\Domains\Identity\Http\Requests\LoginRequest;
use App\Domains\Identity\Models\LoginChallenge;
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
        $challenge = $action->handle($request->string('login')->toString(), $request->string('password')->toString(), $request->ip());

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

        return redirect()->route('workspaces');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
