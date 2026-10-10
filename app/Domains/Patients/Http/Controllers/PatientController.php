<?php

declare(strict_types=1);

namespace App\Domains\Patients\Http\Controllers;

use App\Domains\Hub\Actions\NetworkIdentity;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Patients\Actions\RegisterPatient;
use App\Domains\Patients\Actions\SearchPatients;
use App\Domains\Patients\Http\Requests\RegisterPatientRequest;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patient register for the current provider: search first, then register.
 */
class PatientController extends Controller
{
    public function index(Request $request, SearchPatients $search): Response
    {
        $this->authorize(Permission::PATIENTS_VIEW);

        $term = $request->string('search')->toString();

        return Inertia::render('Patients/Index', [
            'search' => $term,
            'patients' => $search->handle($term)->map(fn (Patient $p): array => [
                'id' => $p->id,
                'name' => $p->fullName(),
                'age' => $p->ageInYears(),
                'idNumber' => $p->maskedIdNumber(),
                'cell' => $p->cell,
                'medicalAid' => $p->medical_aid_scheme,
            ])->values(),
            'canRegister' => $this->user($request)->can(Permission::PATIENTS_REGISTER),
        ]);
    }

    public function create(): Response
    {
        $this->authorize(Permission::PATIENTS_REGISTER);

        return Inertia::render('Patients/Register');
    }

    public function store(RegisterPatientRequest $request, RegisterPatient $action): RedirectResponse
    {
        $this->authorize(Permission::PATIENTS_REGISTER);

        $patient = $action->handle($request->toData(), $this->user($request));
        $provider = tenant();
        $message = "{$patient->fullName()} is registered.";

        if ($provider instanceof Provider) {
            $network = app(NetworkIdentity::class);
            $identity = $network->register($patient, $provider);
            if ($identity !== null && ! $network->isLinked($identity, $provider->id)) {
                $message .= ' This cell number is already on the Dr Business Flow network — use Network search to send the patient an approval code and link their records.';
            }
        }

        return redirect()->route('patients.index', ['search' => $patient->fullName()])->with('success', $message);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
