<?php

declare(strict_types=1);

namespace App\Domains\Documents\Jobs;

use App\Domains\Documents\Models\DocumentTemplate;
use App\Domains\Documents\Support\DefaultTemplates;
use App\Domains\Documents\Support\DocumentType;
use App\Domains\Platform\Models\Provider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Provisioning step: give every new provider the default template set.
 */
class SeedDefaultTemplates implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public TenantWithDatabase $tenant) {}

    public function handle(): void
    {
        if (! $this->tenant instanceof Provider) {
            return;
        }

        $this->tenant->run(function (): void {
            foreach (DocumentType::cases() as $type) {
                DocumentTemplate::query()->firstOrCreate(
                    ['type' => $type->value, 'version' => 1],
                    ['is_active' => true, 'body' => DefaultTemplates::body($type), 'paper' => $type === DocumentType::Invoice ? 'A4' : 'A5'],
                );
            }
        });
    }
}
