<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\Authenticator;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account → Security: authenticator app and recovery codes.
 */
class SecurityController extends Controller
{
    public function show(Request $request, Authenticator $auth): Response
    {
        $user = $this->user($request);

        return Inertia::render('Account/Security', [
            'enabled' => $user->totp_confirmed_at !== null,
            'required' => $auth->required($user),
            'recoveryLeft' => count((array) ($user->recovery_codes ?? [])),
        ]);
    }

    public function start(Request $request, Authenticator $auth): JsonResponse
    {
        return response()->json($auth->start($this->user($request)));
    }

    public function confirm(Request $request, Authenticator $auth): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        return response()->json(['recoveryCodes' => $auth->confirm($this->user($request), $request->string('code')->toString())]);
    }

    public function regenerate(Request $request, Authenticator $auth): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        return response()->json(['recoveryCodes' => $auth->regenerateRecoveryCodes($this->user($request), $request->string('code')->toString())]);
    }

    public function disable(Request $request, Authenticator $auth): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $this->user($request);
        abort_if($auth->required($user), 422, 'Your role requires an authenticator app. Set it up on a new phone instead of turning it off.');
        $auth->disable($user, $request->string('code')->toString());

        return response()->json(['ok' => true]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
