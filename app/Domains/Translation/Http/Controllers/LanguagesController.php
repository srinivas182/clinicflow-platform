<?php

declare(strict_types=1);

namespace App\Domains\Translation\Http\Controllers;

use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Translation\Actions\Translations;
use App\Domains\Translation\Models\Language;
use App\Domains\Translation\Models\Translation;
use App\Domains\Translation\Models\TranslatorInvitation;
use App\Domains\Translation\Models\TranslatorLanguage;
use App\Domains\Translation\Support\TranslationUnits;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Languages: switch languages on or off, see review progress, and manage reviewers. */
class LanguagesController extends Controller
{
    public function index(Translations $translations): Response
    {
        $units = TranslationUnits::all();
        $languages = Language::query()->orderByDesc('enabled')->orderBy('name')->get()->map(function (Language $l) use ($units, $translations): array {
            if ($l->code !== 'en') {
                $translations->sync($l->code);
            }
            $rows = Translation::query()->where('language', $l->code)->get();
            $live = $rows->filter(fn (Translation $t) => isset($units[$t->unit]) && $t->isLive($units[$t->unit]['source']))->count();

            return ['code' => $l->code, 'name' => $l->name, 'nativeName' => $l->native_name, 'enabled' => $l->enabled,
                'live' => $l->code === 'en' ? count($units) : $live, 'total' => count($units),
                'reviewers' => TranslatorLanguage::query()->where('language', $l->code)->get()->map(fn (TranslatorLanguage $r) => [
                    'id' => $r->id, 'name' => (string) User::query()->whereKey($r->user_id)->value('name'), 'clinical' => $r->clinical,
                ])->values()];
        })->values();

        return Inertia::render('Admin/Languages', [
            'languages' => $languages,
            'invitations' => TranslatorInvitation::query()->whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get()
                ->map(fn (TranslatorInvitation $i) => ['name' => $i->name, 'contact' => $i->email ?? $i->phone, 'languages' => $i->languages, 'clinical' => $i->clinical,
                    'expires' => $i->expires_at->format('j M Y')])->values(),
        ]);
    }

    public function toggle(Request $request, string $code): RedirectResponse
    {
        $language = Language::query()->findOrFail($code);
        abort_if($code === 'en', 422, 'English is always on.');
        $on = $request->boolean('enabled');
        $language->forceFill(['enabled' => $on, 'enabled_at' => $on ? now() : null])->save();
        activity('translation')->causedBy($request->user())->withProperties(['language' => $code])->log($on ? 'Language switched on' : 'Language switched off');

        return back()->with('success', $on ? "{$language->name} is on. Anything not yet approved shows in English." : "{$language->name} is off.");
    }

    public function invite(Request $request, SendMessage $messages): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:190', 'required_without:phone'], 'phone' => ['nullable', 'string', 'max:20', 'required_without:email'],
            'languages' => ['required', 'array', 'min:1'], 'languages.*' => ['string', Rule::exists('languages', 'code')->whereNot('code', 'en')], 'clinical' => ['boolean'],
        ]);
        $token = Str::random(48);
        TranslatorInvitation::query()->create(['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
            'languages' => array_values(array_unique($data['languages'])), 'clinical' => (bool) ($data['clinical'] ?? false),
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'invited_by' => $request->user()?->getAuthIdentifier()]);
        $link = url("/translate/invite/{$token}");
        $text = "You are invited to review Dr Business Flow translations. Open this link to start (it works once, for 7 days): {$link}";
        ! empty($data['email']) ? $messages->handle('email', (string) $data['email'], $text, 'Help review Dr Business Flow translations')
            : $messages->handle('sms', (string) $data['phone'], $text);
        activity('translation')->causedBy($request->user())->withProperties(['languages' => $data['languages']])->log('Translation reviewer invited');

        return back()->with('success', "Invitation sent to {$data['name']}.");
    }

    public function removeReviewer(Request $request, TranslatorLanguage $reviewer): RedirectResponse
    {
        $reviewer->delete();
        activity('translation')->causedBy($request->user())->withProperties(['language' => $reviewer->language])->log('Translation reviewer removed');

        return back()->with('success', 'Reviewer removed for this language.');
    }
}
