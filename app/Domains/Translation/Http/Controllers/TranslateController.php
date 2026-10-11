<?php

declare(strict_types=1);

namespace App\Domains\Translation\Http\Controllers;

use App\Domains\Translation\Actions\Translations;
use App\Domains\Translation\Models\Language;
use App\Domains\Translation\Models\Translation;
use App\Domains\Translation\Models\TranslatorLanguage;
use App\Domains\Translation\Support\TranslationUnits;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The review screen for invited native speakers: only their languages, no patient data. */
class TranslateController extends Controller
{
    public function index(Request $request, Translations $translations): Response
    {
        $user = $this->user($request);
        $mine = TranslatorLanguage::query()->where('user_id', $user->id)->get()->keyBy('language');
        $asked = $request->query('language');
        $language = is_string($asked) && $asked !== '' ? $asked : (string) $mine->keys()->first();
        abort_unless($mine->has($language), 403);
        $translations->sync($language);
        $units = TranslationUnits::all();
        $rows = Translation::query()->where('language', $language)->get()->keyBy('unit');

        return Inertia::render('Translate/Index', [
            'language' => $language,
            'languages' => Language::query()->whereIn('code', $mine->keys())->orderBy('name')->get(['code', 'name'])->values(),
            'clinicalReviewer' => (bool) $mine->get($language)?->clinical,
            'items' => collect($units)->map(function (array $u, string $unit) use ($rows): array {
                $t = $rows->get($unit);

                return ['unit' => $unit, 'english' => $u['source'], 'context' => $u['context'], 'placeholders' => $u['placeholders'], 'medical' => $u['medical'], 'sms' => $u['sms'],
                    'text' => $t?->text, 'status' => $t !== null ? $t->status : 'missing', 'englishChanged' => $t !== null && $t->text !== null && $t->source !== $u['source'],
                    'approved' => $t?->approved_at !== null, 'clinicalApproved' => $t?->clinical_approved_at !== null, 'live' => $t?->isLive($u['source']) ?? false];
            })->values(),
        ]);
    }

    public function save(Request $request, Translations $translations, string $language): RedirectResponse
    {
        $data = $request->validate(['unit' => ['required', 'string'], 'text' => ['nullable', 'string', 'max:2000']]);
        $translations->save($this->user($request), $language, $data['unit'], (string) ($data['text'] ?? ''));

        return back()->with('success', 'Saved. Approve it when you are happy with it.');
    }

    public function approve(Request $request, Translations $translations, string $language): RedirectResponse
    {
        $data = $request->validate(['unit' => ['required', 'string'], 'clinical' => ['boolean']]);
        $request->boolean('clinical')
            ? $translations->approveClinical($this->user($request), $language, $data['unit'])
            : $translations->approve($this->user($request), $language, $data['unit']);

        return back()->with('success', 'Approved.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
