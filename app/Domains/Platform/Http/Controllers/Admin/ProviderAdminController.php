<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Platform\Actions\ApproveProvider;
use App\Domains\Platform\Actions\ReviewVerificationCheck;
use App\Domains\Platform\Enums\VerificationStatus;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\VerificationCheck;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: providers, verification queue and approval.
 */
class ProviderAdminController extends Controller
{
    public function index(): Response
    {
        $rows = [];

        foreach (Provider::query()->with('subscription.package')->latest()->get() as $provider) {
            if ($provider instanceof Provider) {
                $rows[] = $this->row($provider);
            }
        }

        return Inertia::render('Admin/Providers/Index', ['providers' => $rows]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Provider $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type->label(),
            'status' => $p->status->value,
            'package' => $p->subscription?->package->name,
            'address' => $p->domains()->value('domain'),
            'pendingChecks' => $p->verificationChecks()->where('status', VerificationStatus::Pending->value)->count(),
        ];
    }

    public function show(string $provider): Response
    {
        $model = Provider::query()->with('verificationChecks')->findOrFail($provider);

        return Inertia::render('Admin/Providers/Show', [
            'provider' => ['id' => $model->id, 'name' => $model->name, 'type' => $model->type->label(), 'status' => $model->status->value],
            'checks' => $model->verificationChecks->map(fn (VerificationCheck $c): array => [
                'id' => $c->id,
                'label' => $c->type->label(),
                'reference' => $c->reference,
                'status' => $c->status->value,
                'notes' => $c->notes,
            ])->values(),
        ]);
    }

    public function review(Request $request, VerificationCheck $check, ReviewVerificationCheck $action): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(VerificationStatus::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $action->handle($check, VerificationStatus::from($data['status']), $this->admin($request), $data['notes'] ?? null);

        return back()->with('success', 'Check updated.');
    }

    public function approve(Request $request, string $provider, ApproveProvider $action): RedirectResponse
    {
        $action->handle(Provider::query()->findOrFail($provider), $this->admin($request));

        return back()->with('success', 'Provider approved and live.');
    }

    private function admin(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
