<?php

declare(strict_types=1);

namespace App\Domains\Messaging\WhatsApp;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The platform's WhatsApp supplier (super admin). One enabled at a time.
 * driver: meta | twilio | clickatell. sender: Meta phone-number ID or the
 * WhatsApp-enabled number (Twilio, Clickatell).
 *
 * @property int $id
 * @property string $driver
 * @property bool $enabled
 * @property array<string, string>|null $credentials
 * @property string|null $sender
 */
class WhatsAppProvider extends Model
{
    use CentralConnection;

    public const DRIVERS = [
        'meta' => ['label' => 'Meta WhatsApp Cloud API', 'fields' => ['access_token' => 'Permanent access token', 'waba_id' => 'WhatsApp Business Account ID'], 'sender' => 'Phone number ID'],
        'twilio' => ['label' => 'Twilio WhatsApp', 'fields' => ['account_sid' => 'Account SID', 'auth_token' => 'Auth token'], 'sender' => 'WhatsApp sender number (+27…)'],
        'clickatell' => ['label' => 'Clickatell WhatsApp', 'fields' => ['api_key' => 'API key'], 'sender' => 'WhatsApp sender number (+27…)'],
    ];

    protected $table = 'whatsapp_providers';

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'credentials' => 'encrypted:array'];
    }

    public static function active(): ?self
    {
        return static::query()->where('enabled', true)->first();
    }

    public function credential(string $key): string
    {
        return (string) ($this->credentials[$key] ?? '');
    }
}
