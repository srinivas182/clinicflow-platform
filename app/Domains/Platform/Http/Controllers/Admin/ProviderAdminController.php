<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Platform\Actions\ApproveProvider;
use App\Domains\Platform\Actions\ReviewVerificationCheck;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\VerificationStatus;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\VerificationCheck;
use App\Domains\Platform\Storage\FileStore;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Super admin: providers, verification queue and approval.
 */
class ProviderAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $rows = [];
        // Verification: practices waiting for approval, or with a corrected check to review.
        $verification = $request->query('filter') === 'verification';
        $query = Provider::query()->with('subscription.package')->latest();
        if ($verification) {
            $query->where(fn ($q) => $q->where('status', ProviderStatus::PendingVerification->value)
                ->orWhereHas('verificationChecks', fn ($c) => $c->whereNotNull('resubmitted_at')->where('status', VerificationStatus::Pending->value)));
        }

        foreach ($query->get() as $provider) {
            if ($provider instanceof Provider) {
                $rows[] = $this->row($provider);
            }
        }

        return Inertia::render('Admin/Providers/Index', ['providers' => $rows, 'filter' => $verification ? 'verification' : null]);
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
            'provider' => ['id' => $model->id, 'name' => $model->name, 'type' => $model->type->label(), 'status' => $model->status->value,
                'canApprove' => $model->status === ProviderStatus::PendingVerification, 'live' => $model->status->isLive()],
            'checks' => $model->verificationChecks->map(fn (VerificationCheck $c): array => [
                'id' => $c->id,
                'label' => $c->type->label(),
                'reference' => $c->reference,
                'status' => $c->status->value,
                'notes' => $c->notes,
                'resubmitted' => $c->resubmitted_at?->toDateString(),
                'document' => $c->document_name, 'documentAt' => $c->document_uploaded_at?->toDateString(),
                // Who reviewed and when (beta #18).
                'reviewedBy' => $c->reviewed_by === null ? null : User::query()->whereKey($c->reviewed_by)->value('name'),
                'reviewedAt' => $c->reviewed_at?->toDateString(),
            ])->values(),
        ]);
    }

    public function review(Request $request, VerificationCheck $check, ReviewVerificationCheck $action): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(VerificationStatus::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'suspend' => ['boolean'],
        ]);

        $status = VerificationStatus::from($data['status']);
        $action->handle($check, $status, $this->admin($request), $data['notes'] ?? null, (bool) ($data['suspend'] ?? false));

        return back()->with('success', $status === VerificationStatus::Rejected ? 'Check rejected. The practice has been emailed the reason.' : 'Check updated.');
    }

    public function approve(Request $request, string $provider, ApproveProvider $action): RedirectResponse
    {
        $action->handle(Provider::query()->findOrFail($provider), $this->admin($request));

        return back()->with('success', 'Provider approved and live. The owner has been emailed.');
    }

    private function admin(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** Opens a check's uploaded document from the practice's own file store (logged). */
    public function document(Request $request, VerificationCheck $check): HttpResponse
    {
        abort_if($check->document_path === null, 404);
        $provider = $check->provider;
        $path = (string) $check->document_path;
        $file = $provider->run(fn (): ?array => Storage::disk(FileStore::DISK)->exists($path)
            ? ['body' => (string) Storage::disk(FileStore::DISK)->get($path), 'mime' => (string) Storage::disk(FileStore::DISK)->mimeType($path)] : null);
        abort_unless(is_array($file), 404);
        $body = (string) $file['body'];
        $mime = (string) $file['mime'];
        activity('platform')->causedBy($request->user())->performedOn($check)->log('Verification document viewed');

        return response($body, 200, ['Content-Type' => (string) $mime, 'Content-Disposition' => 'inline; filename="'.addslashes((string) $check->document_name).'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
