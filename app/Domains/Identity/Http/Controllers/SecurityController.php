<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\Authenticator;
use App\Domains\Identity\Actions\TrustedDevices;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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
            'trustedDevices' => DB::table('trusted_devices')->where('user_id', $user->id)->where('expires_at', '>', now())->latest('created_at')->get()
                ->map(fn ($d) => ['id' => $d->id, 'label' => (string) $d->label, 'lastUsed' => substr((string) $d->last_used_at, 0, 16), 'expires' => substr((string) $d->expires_at, 0, 10)])->values(),
            'signIns' => DB::table('login_events')->where('user_id', $user->id)->latest('created_at')->limit(10)->get()
                ->map(fn ($e) => ['at' => substr((string) $e->created_at, 0, 16), 'ip' => $e->ip, 'device' => mb_substr((string) $e->user_agent, 0, 80), 'newDevice' => (bool) $e->new_device])->values(),
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

    public function forgetDevice(Request $request, int $device): JsonResponse
    {
        app(TrustedDevices::class)->forget($this->user($request), $device);

        return response()->json(['ok' => true]);
    }

    /** Signs out every other browser and device (needs the password). */
    public function signOutOthers(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $user = $this->user($request);
        if (! Hash::check($request->string('password')->toString(), (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }
        $guard = Auth::guard('web');
        abort_unless($guard instanceof SessionGuard, 500);
        $guard->logoutOtherDevices($request->string('password')->toString());
        app(TrustedDevices::class)->forget($user);
        activity('auth')->causedBy($user)->log('Signed out other devices');

        return response()->json(['ok' => true]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
