<?php

declare(strict_types=1);

namespace App\Domains\Hub\Http\Controllers;

use App\Domains\Hub\Actions\EscriptExchange;
use App\Domains\Hub\Actions\NetworkIdentity;
use App\Domains\Hub\Models\HubEscript;
use App\Domains\Hub\Models\HubIdentity;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Portal\Actions\PortalSignIn;
use App\Domains\Prescribing\Models\Prescription;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Doctors send e-scripts; pharmacies receive, accept, reject and dispense them;
 * patients see and revoke the practices linked to them.
 */
class EscriptController extends Controller
{
    public function pharmacies(Request $request): JsonResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $q = trim($request->string('q')->toString());

        return response()->json(Provider::query()->where('type', ProviderType::Pharmacy->value)
            ->whereIn('status', [ProviderStatus::Trial->value, ProviderStatus::Active->value])
            ->when($q !== '', fn ($w) => $w->where('name', 'like', "%{$q}%"))->orderBy('name')->limit(20)->get(['id', 'name']));
    }

    public function send(Request $request, Prescription $prescription, EscriptExchange $exchange): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $data = $request->validate(['pharmacy_id' => ['required', 'string']]);
        $escript = $exchange->send($prescription, $this->provider(), $data['pharmacy_id']);

        return back()->with('success', 'E-script sent to '.Provider::query()->whereKey($escript->pharmacy_tenant_id)->value('name').'.');
    }

    public function inbox(): Response
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);
        $pharmacy = $this->provider();
        $issuers = Provider::query()->pluck('name', 'id');

        return Inertia::render('Pharmacy/Escripts', [
            'escripts' => HubEscript::query()->where('pharmacy_tenant_id', $pharmacy->id)->latest('sent_at')->limit(100)->get()
                ->map(fn (HubEscript $e) => [
                    'id' => $e->id, 'status' => $e->status, 'note' => $e->status_note, 'version' => $e->version,
                    'sentAt' => $e->sent_at->format('j M H:i'), 'practice' => $issuers[$e->issuer_tenant_id] ?? 'A practice',
                    'payload' => $e->payload, 'fingerprint' => substr($e->signature_hash, 0, 16),
                ])->values(),
        ]);
    }

    public function act(Request $request, HubEscript $escript, string $action, EscriptExchange $exchange): RedirectResponse
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);
        $pharmacy = $this->provider();
        match ($action) {
            'accept' => $exchange->accept($escript, $pharmacy),
            'reject' => $exchange->reject($escript, $pharmacy, $request->string('reason')->toString()),
            'dispense' => $exchange->dispense($escript, $pharmacy),
            default => abort(404),
        };

        return back()->with('success', 'E-script updated.');
    }

    public function patientPractices(Request $request, PortalSignIn $signIn, NetworkIdentity $network): Response
    {
        $identity = $this->portalIdentity($request, $signIn);

        return Inertia::render('Portal/Practices', [
            'practices' => $identity === null ? [] : $network->linkedProviders($identity),
            'providerName' => $this->provider()->name,
        ]);
    }

    public function patientRevoke(Request $request, string $provider, PortalSignIn $signIn, NetworkIdentity $network): RedirectResponse
    {
        $identity = $this->portalIdentity($request, $signIn);
        abort_if($identity === null, 404);
        $network->revoke($identity, $provider);

        return back()->with('success', 'That practice can no longer link to your records or receive your e-scripts.');
    }

    private function portalIdentity(Request $request, PortalSignIn $signIn): ?HubIdentity
    {
        $cell = (string) $request->session()->get('portal_cell');
        abort_if($signIn->profiles($cell)->isEmpty(), 403);

        return HubIdentity::query()->where('cell', $cell)->first();
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
