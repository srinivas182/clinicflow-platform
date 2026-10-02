<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Contracts\OtpSender;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Local and test environments: write the code to the log instead of sending SMS.
 * The SMS gateway adapter replaces this with the messaging module.
 */
class LogOtpSender implements OtpSender
{
    /** @var array<int, string> last code per user id, read by tests */
    public array $sent = [];

    public function send(User $user, string $code): void
    {
        $this->sent[$user->id] = $code;

        Log::info('Sign-in code issued', ['user_id' => $user->id, 'code' => app()->isProduction() ? '[redacted]' : $code]);
    }
}
