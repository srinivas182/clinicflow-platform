<?php

declare(strict_types=1);

namespace App\Domains\Hub\Http\Controllers;

use App\Domains\Hub\Actions\NetworkIdentity;
use App\Domains\Hub\Models\HubIdentity;
use App\Domains\Hub\Models\HubLinkRequest;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Front desk: find a patient already on the network (masked), send them an
 * approval code and link their records to this practice.
 */
class NetworkController extends Controller
{
    public function index(Request $request, NetworkIdentity $network): Response
    {
        $this->authorize(Permission::PATIENTS_REGISTER);
        $cell = $request->string('cell')->toString();
        $saId = $request->string('sa_id')->toString();
        $identity = ($cell !== '' || $saId !== '') ? $network->find($cell !== '' ? $cell : null, $saId !== '' ? $saId : null) : null;

        return Inertia::render('Patients/Network', [
            'query' => ['cell' => $cell, 'sa_id' => $saId],
            'match' => $identity === null ? null : [
                'id' => $identity->id,
                'masked' => $identity->masked(),
                'linked' => $network->isLinked($identity, $this->provider()->id),
            ],
            'requestId' => session('link_request_id'),
        ]);
    }

    public function request(Request $request, string $identity, NetworkIdentity $network): RedirectResponse
    {
        $this->authorize(Permission::PATIENTS_REGISTER);
        $found = HubIdentity::query()->findOrFail($identity);
        $linkRequest = $network->requestLink($found, $this->provider(), $request->user() instanceof User ? $request->user() : null);

        return back()->with('success', 'An approval code was sent to the patient\'s phone.')->with('link_request_id', $linkRequest->id);
    }

    public function confirm(Request $request, NetworkIdentity $network): RedirectResponse
    {
        $this->authorize(Permission::PATIENTS_REGISTER);
        $data = $request->validate(['request_id' => ['required', 'integer'], 'code' => ['required', 'digits:6']]);
        $patient = $network->confirmLink(HubLinkRequest::query()->findOrFail((int) $data['request_id']), $data['code'], $this->provider());

        return redirect()->route('patients.index', ['search' => $patient->fullName()])
            ->with('success', "{$patient->fullName()} is linked. Capture POPIA and treatment consent at check-in.");
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
