<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Models\GatewayConfig;
use App\Domains\Billing\Models\PlatformGatewayConfig;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Saves a gateway account (provider's or platform's). Blank secret fields keep
 * the stored value, so secrets never need to travel back to the browser.
 */
class SaveGatewayConfig
{
    /**
     * @param  class-string<GatewayConfig|PlatformGatewayConfig>  $model
     * @param  array<string, string|null>  $credentials
     * @param  array<string, mixed>  $extra
     */
    public function handle(string $model, Gateway $gateway, GatewayMode $mode, bool $enabled, bool $isDefault, array $credentials, ?User $by = null, array $extra = []): GatewayConfig|PlatformGatewayConfig
    {
        $config = $model::query()->firstOrNew(['gateway' => $gateway->value]);
        $merged = $config->credentials ?? [];

        foreach ($gateway->credentialFields() as $field) {
            $value = trim((string) ($credentials[$field['key']] ?? ''));
            if ($value !== '' || ! $field['secret']) {
                $merged[$field['key']] = $value;
            }
        }

        if ($enabled) {
            $missing = array_filter($gateway->credentialFields(), fn (array $f) => ($merged[$f['key']] ?? '') === '' && $f['key'] !== 'passphrase');
            if ($missing !== []) {
                throw ValidationException::withMessages(['credentials' => 'Enter: '.implode(', ', array_map(fn (array $f) => $f['label'], $missing)).'.']);
            }
        }

        $config->fill(['mode' => $mode, 'enabled' => $enabled, 'is_default' => $enabled && $isDefault, ...$extra]);
        $config->credentials = $merged;
        $config->save();

        if ($config->is_default) {
            $model::query()->whereKeyNot($config->getKey())->update(['is_default' => false]);
        }

        activity('payments')->causedBy($by)->withProperties(['gateway' => $gateway->value, 'mode' => $mode->value, 'enabled' => $enabled])->log('Payment gateway settings changed');

        return $config;
    }
}
