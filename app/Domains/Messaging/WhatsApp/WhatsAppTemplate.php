<?php

declare(strict_types=1);

namespace App\Domains\Messaging\WhatsApp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A Clinic Flow message submitted to WhatsApp as a template. Business-initiated
 * WhatsApp messages may only use templates WhatsApp has approved.
 * category: utility | marketing | authentication.
 *
 * @property int $id
 * @property string $message_key
 * @property string $template_name
 * @property string $language
 * @property string $category
 * @property string $status pending|approved|rejected
 * @property string|null $rejected_reason
 * @property Carbon|null $synced_at
 */
class WhatsAppTemplate extends Model
{
    use CentralConnection;

    protected $table = 'whatsapp_templates';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    public static function categoryFor(string $catalogueCategory): string
    {
        return match ($catalogueCategory) {
            'marketing' => 'marketing',
            'system' => 'authentication',
            default => 'utility',
        };
    }
}
