<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use Illuminate\Console\Command;

/**
 * Deployment self-check: fails if production is set up unsafely (debug on, plain HTTP, weak sessions, no CSP).
 */
class SecurityCheckCommand extends Command
{
    protected $signature = 'security:check';

    protected $description = 'Check production security settings (run after every deployment)';

    public function handle(): int
    {
        $production = app()->isProduction();
        $checks = [
            'Debug mode is off' => ! (bool) config('app.debug'),
            'Site address uses HTTPS' => str_starts_with((string) config('app.url'), 'https://'),
            'Session cookies are secure' => (bool) config('session.secure'),
            'Sessions are encrypted' => (bool) config('session.encrypt'),
            'Sessions time out within 60 minutes' => (int) config('session.lifetime') <= 60,
            'Content-security policy is on' => config('clinicflow.security.csp') === null ? $production : (bool) config('clinicflow.security.csp'),
            'Authenticator app required for admins' => (bool) config('clinicflow.security.require_authenticator_for_admins'),
        ];
        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '  ✓ ' : '  ✗ ').$label);
            $failed += $ok ? 0 : 1;
        }
        if ($failed > 0) {
            $this->error("{$failed} security setting(s) need attention.");

            return $production ? self::FAILURE : self::SUCCESS;
        }
        $this->info('All security settings are in place.');

        return self::SUCCESS;
    }
}
