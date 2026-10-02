<?php

declare(strict_types=1);

namespace App\Domains\Documents\Http\Controllers;

use App\Domains\Documents\Actions\PublishTemplate;
use App\Domains\Documents\Actions\RenderDocument;
use App\Domains\Documents\Models\DocumentTemplate;
use App\Domains\Documents\Support\DocumentType;
use App\Domains\Documents\Support\PracticeData;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Models\Setting;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Template studio: branding details, template bodies (versioned) and previews.
 */
class TemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $type = DocumentType::tryFrom($request->string('type')->toString()) ?? DocumentType::Invoice;
        $active = DocumentTemplate::active($type);

        return Inertia::render('Settings/Templates', [
            'type' => $type->value,
            'types' => array_map(fn (DocumentType $t) => ['value' => $t->value, 'label' => $t->label()], DocumentType::cases()),
            'template' => ['version' => $active->version, 'body' => $active->body, 'paper' => $active->paper],
            'legalBlock' => $type->legalBlock(),
            'versions' => DocumentTemplate::query()->where('type', $type->value)->orderByDesc('version')->get(['version', 'is_active', 'created_at']),
            'practice' => PracticeData::get(),
            'fields' => self::fields($type),
        ]);
    }

    /**
     * Merge fields available for a document type, e.g. patient.name.
     *
     * @return list<string>
     */
    private static function fields(DocumentType $type): array
    {
        $fields = [];
        foreach ($type->sampleData() as $group => $values) {
            if (is_array($values) && ! array_is_list($values)) {
                foreach (array_keys($values) as $key) {
                    $fields[] = "{$group}.{$key}";
                }
            }
        }

        return $fields;
    }

    public function update(Request $request, string $type, PublishTemplate $action): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $docType = DocumentType::from($type);
        $data = $request->validate(['body' => ['required', 'string', 'max:50000'], 'paper' => ['required', Rule::in(['A4', 'A5'])]]);
        $template = $action->handle($docType, $data['body'], $data['paper'], $request->user() instanceof User ? $request->user() : null);

        return back()->with('success', "{$docType->label()} version {$template->version} published. Documents already issued keep their version.");
    }

    public function preview(string $type, RenderDocument $render): HttpResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $docType = DocumentType::from($type);
        $data = array_merge($docType->sampleData(), ['practice' => PracticeData::get()]);

        return response($render->pdf(DocumentTemplate::active($docType), $data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$docType->value.'-preview.pdf"',
        ]);
    }

    public function branding(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate([
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'bhf' => ['nullable', 'string', 'max:20'],
            'vat_number' => ['nullable', 'string', 'max:20'],
            'colour' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        foreach (PracticeData::FIELDS as $field) {
            Setting::put('branding', $field, $data[$field] ?? '');
        }

        return back()->with('success', 'Branding saved.');
    }
}
