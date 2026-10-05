<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Http\Controllers;

use App\Domains\Clinical\Models\Consultation;
use App\Domains\Documents\Models\IssuedDocument;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Platform\Storage\FileStore;
use App\Domains\Prescribing\Actions\AmendPrescription;
use App\Domains\Prescribing\Actions\RequestSigningPin;
use App\Domains\Prescribing\Actions\SaveDraftPrescription;
use App\Domains\Prescribing\Actions\SignPrescription;
use App\Domains\Prescribing\Models\Prescription;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PrescriptionController extends Controller
{
    public function saveDraft(Request $request, Consultation $consultation, SaveDraftPrescription $action): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $doctor = $this->staff($request);
        abort_unless($consultation->doctor_staff_id === $doctor->id, 403);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:15'],
            'items.*.medicine_id' => ['required', 'integer'],
            'items.*.dose' => ['required', 'string', 'max:120'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.repeats' => ['required', 'integer', 'min:0', 'max:11'],
            'items.*.override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var list<array{medicine_id: int, dose: string, quantity: int, repeats: int, override_reason?: ?string}> $items */
        $items = array_values($data['items']);
        $action->handle($consultation, $doctor, $items);

        return back()->with('success', 'Prescription saved as a draft.');
    }

    public function requestPin(Request $request, Prescription $prescription, RequestSigningPin $action): RedirectResponse
    {
        $this->authorize(Permission::SCRIPTS_SIGN);
        $action->handle($prescription, $this->staff($request));

        return back()->with('success', 'A signing PIN was sent to your phone.');
    }

    public function sign(Request $request, Prescription $prescription, SignPrescription $action): RedirectResponse
    {
        $this->authorize(Permission::SCRIPTS_SIGN);
        $data = $request->validate(['pin' => ['required', 'digits:6']]);
        $signed = $action->handle($prescription, $this->staff($request), $data['pin']);

        return back()->with('success', "Prescription version {$signed->version} signed.");
    }

    public function amend(Request $request, Prescription $prescription, AmendPrescription $action): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        abort_unless($prescription->prescriber_staff_id === $this->staff($request)->id, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $next = $action->handle($prescription, $data['reason']);

        return back()->with('success', "Version {$next->version} opened as a draft.");
    }

    public function pdf(Request $request, Prescription $prescription): Response
    {
        abort_unless($request->user()?->can(Permission::CONSULTS_WRITE) || $request->user()?->can(Permission::VISITS_MANAGE), 403);
        $document = $prescription->issued_document_id === null ? null : IssuedDocument::query()->find($prescription->issued_document_id);
        abort_if($document === null, 404);

        return response((string) Storage::disk(FileStore::DISK)->get($document->file_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"script-v{$prescription->version}.pdf\"",
        ]);
    }

    private function staff(Request $request): Staff
    {
        /** @var User $user */
        $user = $request->user();

        return Staff::query()->findOrFail($user->id);
    }
}
