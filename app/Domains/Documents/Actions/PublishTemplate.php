<?php

declare(strict_types=1);

namespace App\Domains\Documents\Actions;

use App\Domains\Documents\Models\DocumentTemplate;
use App\Domains\Documents\Support\DocumentType;
use App\Domains\Documents\Support\TemplateRenderer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishes a new template version and makes it the active one.
 */
class PublishTemplate
{
    public function handle(DocumentType $type, string $body, string $paper = 'A4', ?User $by = null): DocumentTemplate
    {
        if (! in_array($paper, ['A4', 'A5'], true)) {
            throw ValidationException::withMessages(['paper' => 'Choose A4 or A5.']);
        }
        if (mb_strlen($body) > 50000 || trim(strip_tags($body)) === '' && ! str_contains($body, '{{')) {
            throw ValidationException::withMessages(['body' => 'The template is empty or too long.']);
        }

        return DB::transaction(function () use ($type, $body, $paper, $by): DocumentTemplate {
            $version = (int) DocumentTemplate::query()->where('type', $type->value)->max('version') + 1;
            DocumentTemplate::query()->where('type', $type->value)->update(['is_active' => false]);

            $template = DocumentTemplate::create([
                'type' => $type,
                'version' => $version,
                'is_active' => true,
                'body' => TemplateRenderer::sanitise($body),
                'paper' => $paper,
                'created_by' => $by?->id,
            ]);

            activity('documents')->performedOn($template)->causedBy($by)->withProperties(['type' => $type->value, 'version' => $version])->log('Template published');

            return $template;
        });
    }
}
