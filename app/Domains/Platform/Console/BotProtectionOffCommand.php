<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Wallet\Support\WalletSettings;
use Illuminate\Console\Command;

/**
 * Way back in: switches bot protection off from the server when nobody can sign in
 * (for example the Turnstile widget fails because of a wrong site key).
 */
class BotProtectionOffCommand extends Command
{
    protected $signature = 'security:bot-protection-off';

    protected $description = 'Switch Cloudflare Turnstile bot protection off (use when sign-in is blocked)';

    public function handle(): int
    {
        WalletSettings::put('security.bot_protection_enabled', false);
        activity('security')->log('Bot protection switched off from the server');
        $this->info('Bot protection is off. Sign in and check the keys in Admin → Security.');

        return self::SUCCESS;
    }
}
