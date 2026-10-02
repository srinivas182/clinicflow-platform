<?php

declare(strict_types=1);

namespace App\Domains\Documents\Actions;

use App\Domains\Documents\Models\DocumentTemplate;
use App\Domains\Documents\Models\IssuedDocument;
use App\Domains\Documents\Support\DefaultTemplates;
use App\Domains\Documents\Support\DocumentType;
use App\Domains\Documents\Support\TemplateRenderer;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a branded PDF from the provider's active template plus the locked
 * legal block. Remote resources are disabled. Issued documents are stored with
 * the template version and a SHA-256 so they can be verified later.
 */
class RenderDocument
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function html(DocumentTemplate $template, array $data): string
    {
        return '<html><head><meta charset="utf-8"><style>'.DefaultTemplates::css().'</style></head><body>'
            .TemplateRenderer::render($template->body, $data)
            .TemplateRenderer::render($template->type->legalBlock(), $data)
            .'</body></html>';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function pdf(DocumentTemplate $template, array $data): string
    {
        $html = $this->html($template, $data);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($template->paper, 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function issue(DocumentType $type, Model $subject, array $data, ?User $by = null): IssuedDocument
    {
        $template = DocumentTemplate::active($type);
        $pdf = $this->pdf($template, $data);
        $key = (string) $subject->getKey();
        $path = "documents/{$type->value}/".now()->format('Y/m')."/{$key}-v{$template->version}-".now()->format('His').'.pdf';

        Storage::disk('local')->put($path, $pdf);

        $issued = IssuedDocument::create([
            'type' => $type->value,
            'document_template_id' => $template->id,
            'template_version' => $template->version,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $key,
            'file_path' => $path,
            'sha256' => hash('sha256', $pdf),
            'issued_by' => $by?->id,
            'created_at' => now(),
        ]);

        activity('documents')->performedOn($subject)->causedBy($by)->withProperties(['type' => $type->value, 'version' => $template->version])->log('Document issued');

        return $issued;
    }
}
