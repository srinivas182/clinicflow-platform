<?php

declare(strict_types=1);

namespace App\Domains\Translation\Http\Controllers;

use App\Domains\Identity\Support\PasswordRules;
use App\Domains\Translation\Models\TranslatorInvitation;
use App\Domains\Translation\Models\TranslatorLanguage;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** A translation reviewer's invitation link: create an account (or use an existing one) and start reviewing. */
class TranslatorInvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $inv = $this->find($token);

        return Inertia::render('Translate/Invitation', ['token' => $token, 'name' => $inv?->name, 'contact' => $inv !== null ? ($inv->email ?? $inv->phone) : null,
            'valid' => $inv !== null, 'signedIn' => $request->user() !== null]);
    }

    public function register(Request $request, string $token): RedirectResponse
    {
        $inv = $this->find($token) ?? throw ValidationException::withMessages(['password' => 'This invitation has expired or was already used.']);
        $data = $request->validate(['email' => ['required', 'email', 'max:190', 'unique:users,email'], 'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', PasswordRules::make()]]);
        $user = new User;
        $user->forceFill(['name' => $inv->name, 'email' => strtolower(trim($data['email'])), 'phone' => $data['phone'], 'password' => $data['password']])->save();
        $this->grant($inv, $user);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/translate')->with('success', 'Welcome! Your account is ready. Start with the texts marked "To review".');
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $inv = $this->find($token) ?? throw ValidationException::withMessages(['token' => 'This invitation has expired or was already used.']);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->grant($inv, $user);

        return redirect('/translate')->with('success', 'You can now review translations.');
    }

    private function find(string $token): ?TranslatorInvitation
    {
        return TranslatorInvitation::query()->where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at', '>', now())->first();
    }

    private function grant(TranslatorInvitation $inv, User $user): void
    {
        foreach ($inv->languages as $language) {
            TranslatorLanguage::query()->updateOrCreate(['user_id' => $user->id, 'language' => $language], ['clinical' => $inv->clinical]);
        }
        $inv->forceFill(['accepted_at' => now()])->save();
        activity('translation')->causedBy($user)->withProperties(['languages' => $inv->languages])->log('Translation reviewer joined');
    }
}
