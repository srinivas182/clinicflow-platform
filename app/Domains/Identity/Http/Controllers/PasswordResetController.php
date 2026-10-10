<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\TrustedDevices;
use App\Domains\Identity\Support\PasswordRules;
use App\Domains\Messaging\Actions\SendMessage;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot your password?": a single-use link by email, valid 60 minutes. The answer is the same
 * whether or not the email has an account, so it cannot be used to find out who has one.
 */
class PasswordResetController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function send(Request $request): RedirectResponse
    {
        $email = strtolower(trim((string) $request->validate(['email' => ['required', 'email', 'max:190']])['email']));
        $user = User::query()->where('email', $email)->first();
        if ($user instanceof User) {
            $token = $this->broker()->createToken($user);
            $link = url('/reset-password/'.$token).'?email='.urlencode($email);
            app(SendMessage::class)->handle('email', $email,
                "Reset your Dr Business Flow password with this link (valid for 60 minutes, works once):\n\n{$link}\n\nIf you didn't ask for this, ignore this email — your password stays the same.",
                'Reset your Dr Business Flow password');
            activity('auth')->causedBy($user)->log('Password reset link sent');
        }

        return back()->with('success', 'If an account exists for that email, we have sent a link to reset the password. It works once, for 60 minutes.');
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRules::make()]]);
        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();
        if (! $user instanceof User || ! $this->broker()->tokenExists($user, $data['token'])) {
            throw ValidationException::withMessages(['email' => 'This reset link has expired or was already used. Ask for a new one.']);
        }
        $user->forceFill(['password' => $data['password']])->save();
        $this->broker()->deleteToken($user);
        // Other sessions end (their saved password no longer matches); trusted devices and any lockout are cleared.
        app(TrustedDevices::class)->forget($user);
        Cache::forget('login-locked:'.$user->id);
        Cache::forget('login-failures:'.$user->id);
        activity('auth')->causedBy($user)->log('Password reset with an emailed link');

        return redirect('/login')->with('success', 'Your password has been changed. Sign in with the new one.');
    }

    private function broker(): PasswordBroker
    {
        $broker = Password::broker();
        abort_unless($broker instanceof PasswordBroker, 500);

        return $broker;
    }
}
