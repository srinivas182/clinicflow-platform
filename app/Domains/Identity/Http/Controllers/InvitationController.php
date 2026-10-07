<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\StaffManagement;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accepting a staff invitation (platform domain): an existing account signs in and accepts;
 * a new person creates an account (same password rules as sign-up), then signs in normally.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token, StaffManagement $staff): Response
    {
        $inv = $staff->findByToken($token);
        $user = Auth::guard('web')->user();
        $existing = $inv === null ? false : User::query()->when($inv->email !== null, fn ($q) => $q->where('email', $inv->email), fn ($q) => $q->where('phone', $inv->phone))->exists();

        return Inertia::render('Auth/Invitation', [
            'valid' => $inv !== null,
            'token' => $token,
            'practice' => $inv === null ? null : (string) Provider::query()->whereKey($inv->tenant_id)->value('name'),
            'role' => $inv === null ? null : StaffRole::from((string) $inv->role)->label(),
            'name' => $inv?->name,
            'signedIn' => $user !== null,
            'existingAccount' => $existing,
        ]);
    }

    public function accept(Request $request, string $token, StaffManagement $staff): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $provider = $staff->accept($token, $user);

        return redirect()->route('workspaces')->with('success', "You have joined {$provider->name}.");
    }

    public function register(Request $request, string $token, StaffManagement $staff): RedirectResponse
    {
        $inv = $staff->findByToken($token);
        abort_if($inv === null, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', config('clinicflow.security.check_leaked_passwords', true)
                ? Password::min(10)->letters()->numbers()->uncompromised() : Password::min(10)->letters()->numbers()],
        ]);
        abort_if(User::query()->when($inv->email !== null, fn ($q) => $q->where('email', $inv->email), fn ($q) => $q->where('phone', $inv->phone))->exists(), 409, 'An account already exists — sign in to accept.');
        $user = User::query()->create(['name' => trim($data['name']), 'email' => $inv->email ?? ('staff-'.$inv->id.'@users.clinicflow.invalid'), 'phone' => $inv->phone, 'password' => $data['password']]);
        $provider = $staff->accept($token, $user);

        return redirect()->route('login')->with('success', "Your account is ready and you have joined {$provider->name}. Sign in to continue.");
    }
}
