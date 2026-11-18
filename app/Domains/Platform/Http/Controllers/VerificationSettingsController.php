<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Actions\ResubmitVerificationCheck;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\VerificationCheck;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Practice side: Settings → Verification. Each registration check with its status and the
 * reviewer's reason; a rejected check can be corrected and sent back.
 */
class VerificationSettingsController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = $this->provider();

        return Inertia::render('Settings/Verification', [
            'status' => $provider->status->value,
            'checks' => $provider->verificationChecks()->orderBy('id')->get()->map(fn (VerificationCheck $c): array => [
                'id' => $c->id, 'label' => $c->type->label(), 'reference' => $c->reference, 'status' => $c->status->value,
                'reason' => $c->notes, 'resubmitted' => $c->resubmitted_at?->toDateString(),
            ])->values(),
        ]);
    }

    public function resubmit(Request $request, int $check, ResubmitVerificationCheck $action): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate(['reference' => ['required', 'string', 'max:80']]);
        $model = $this->provider()->verificationChecks()->whereKey($check)->firstOrFail();
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $action->handle($model, (string) $data['reference'], $user);

        return back()->with('success', 'Sent back for review. We will email you once it is checked.');
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
