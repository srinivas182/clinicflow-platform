<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\Authenticator;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Confirm it's you" before a sensitive action: password, or a code from the authenticator app.
 */
class ConfirmIdentityController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('Auth/ConfirmIdentity', ['authenticator' => $user->totp_confirmed_at !== null, 'minutes' => RequireRecentConfirmation::MINUTES]);
    }

    public function store(Request $request, Authenticator $auth): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $request->validate(['secret' => ['required', 'string', 'max:200']]);
        $secret = $request->string('secret')->toString();
        $ok = $user->totp_confirmed_at !== null ? $auth->verify($user, $secret) : Hash::check($secret, (string) $user->password);
        if (! $ok) {
            activity('auth')->causedBy($user)->log('Identity confirmation failed');

            throw ValidationException::withMessages(['secret' => 'That is not correct.']);
        }
        $request->session()->put(RequireRecentConfirmation::SESSION_KEY, now()->getTimestamp());
        activity('auth')->causedBy($user)->log('Identity confirmed for a sensitive action');

        return redirect()->to((string) $request->session()->pull('url.step_up_intended', '/'));
    }
}
