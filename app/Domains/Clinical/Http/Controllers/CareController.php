<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Http\Controllers;

use App\Domains\Clinical\Actions\BreakGlass;
use App\Domains\Clinical\Actions\ClinicianMessaging;
use App\Domains\Clinical\Actions\Referrals;
use App\Domains\Clinical\Care\ChronicCare;
use App\Domains\Clinical\Care\ChronicRegistration;
use App\Domains\Clinical\Care\Pregnancy;
use App\Domains\Clinical\Care\Prevention;
use App\Domains\Clinical\Models\MessageThread;
use App\Domains\Clinical\Models\Problem;
use App\Domains\Clinical\Models\Referral;
use App\Domains\Clinical\Models\ThreadMessage;
use App\Domains\Hub\Actions\ShareConsent;
use App\Domains\Hub\Models\HubIdentity;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Domains\Prescribing\Models\Prescription;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The clinician side of Sprint 13A: patient care record (problems, monitoring,
 * immunisations, pregnancy, recalls, chronic registrations, share consent),
 * clinician messages, referrals and break-glass reviews.
 */
class CareController extends Controller
{
    public function care(Patient $patient, ChronicCare $chronic, Prevention $prevention, Pregnancy $pregnancy, ShareConsent $consent): Response
    {
        $this->authorize(Permission::CONSULTS_WRITE);

        return Inertia::render('Patients/Care', [
            'patient' => ['id' => $patient->id, 'name' => $patient->fullName(), 'age' => $patient->ageInYears(), 'sex' => $patient->sex?->value, 'medicalAid' => $patient->medical_aid_scheme, 'whatsappOptIn' => $patient->getAttribute('whatsapp_opt_in_at') !== null],
            'problems' => Problem::query()->where('patient_id', $patient->id)->orderBy('status')->get(['id', 'icd10_code', 'description', 'status', 'chronic', 'onset_date']),
            'monitoring' => $chronic->monitoringDue($patient),
            'chronicScripts' => Prescription::query()->where('patient_id', $patient->id)->where('chronic', true)->where('status', Prescription::SIGNED)->latest('signed_at')->get(['id', 'signed_at']),
            'immunisationsDue' => $prevention->immunisationsDue($patient),
            'immunisations' => DB::table('immunisations')->where('patient_id', $patient->id)->orderByDesc('given_on')->get(['vaccine', 'dose', 'given_on', 'batch']),
            'pregnancy' => $pregnancy->summary($patient),
            'registrations' => DB::table('chronic_registrations')->where('patient_id', $patient->id)->latest('id')->get(),
            'shared' => $consent->categories($patient->getAttribute('hub_identity_id'), $this->provider()->id),
            'specialties' => Referrals::SPECIALTIES,
            'referrals' => Referral::query()->where('patient_id', $patient->id)->latest()->get(['id', 'direction', 'other_name', 'specialty', 'status', 'urgency', 'feedback', 'created_at']),
        ]);
    }

    public function careAction(Request $request, Patient $patient, string $action, ChronicCare $chronic, Prevention $prevention, Pregnancy $pregnancy, ChronicRegistration $registrations, ShareConsent $consent): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $me = $this->staff($request);
        $identity = HubIdentity::query()->find($patient->getAttribute('hub_identity_id'));

        match ($action) {
            'problem' => $chronic->addProblem($patient, $request->string('icd10_code')->toString(), $request->input('onset_date'), $me, $request->boolean('chronic', true)),
            'immunisation' => $prevention->recordImmunisation($patient, $request->string('vaccine')->toString(), $request->string('dose')->toString(), $request->string('given_on')->toString(), $request->input('batch'), $me->id),
            'pregnancy' => $pregnancy->start($patient, $request->string('lmp')->toString()),
            'antenatal' => $pregnancy->recordVisit($request->integer('pregnancy_id'), $request->string('visit_date')->toString(), $request->input('bp'), $request->filled('weight_kg') ? (float) $request->input('weight_kg') : null, $request->filled('fundal_height_cm') ? $request->integer('fundal_height_cm') : null, $request->input('notes'), $me->id),
            'recall-done' => $prevention->markRecallDone($patient, $request->integer('rule_id')),
            'registration' => $registrations->create($patient, $request->string('icd10_code')->toString(), array_values(array_filter(array_map('trim', explode(',', $request->string('medicines')->toString()))))),
            'consent-code' => $identity instanceof HubIdentity ? $consent->requestCode($identity, $this->provider()) : abort(422, 'Link the patient to the network first.'),
            'consent-confirm' => $identity instanceof HubIdentity ? $consent->confirmCode($identity, $this->provider(), $request->string('code')->toString(), array_values((array) $request->input('categories', [])), $request->input('until')) : abort(422),
            default => abort(404),
        };

        return back()->with('success', 'Saved.');
    }

    public function resolveProblem(Problem $problem, ChronicCare $chronic): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $chronic->resolveProblem($problem);

        return back()->with('success', 'Problem resolved.');
    }

    public function registrationAction(Request $request, int $registration, string $action, ChronicRegistration $registrations): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $action === 'submit' ? $registrations->submit($registration) : $registrations->decide($registration, $action === 'approve', $request->input('reference'), $request->input('notes'));

        return back()->with('success', 'Registration updated.');
    }

    public function renew(Request $request, Prescription $prescription, ChronicCare $chronic): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $draft = $chronic->renew($prescription, $this->staff($request));
        $visitId = DB::table('consultations')->where('id', $draft->consultation_id)->value('visit_id');

        return redirect("/consults/{$visitId}")->with('success', 'Repeat prescription ready. Check it and sign with your PIN.');
    }

    public function markChronic(Prescription $prescription): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        abort_unless($prescription->status === Prescription::DRAFT, 422, 'Mark the script as chronic before signing.');
        $prescription->forceFill(['chronic' => true])->save();

        return back()->with('success', 'Marked as chronic medication.');
    }

    // ---------------- messages ----------------

    public function inbox(Request $request): Response
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $me = $this->staff($request)->id;

        return Inertia::render('Messages/Index', [
            'threads' => MessageThread::query()->whereJsonContains('local_staff_ids', $me)->latest('updated_at')->limit(100)->get()->map(fn (MessageThread $t) => [
                'id' => $t->id, 'subject' => $t->subject, 'urgent' => $t->urgent, 'escalated' => $t->escalated_at !== null, 'external' => $t->other_tenant_id !== null,
                'patient' => $t->patient_id === null ? null : Patient::query()->find($t->patient_id)?->fullName(),
                'unread' => $t->messages()->get()->filter(fn (ThreadMessage $m) => ! in_array($me, $m->read_by ?? [], true))->count(),
            ])->values(),
            'colleagues' => Staff::query()->whereKeyNot($me)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function thread(Request $request, MessageThread $thread, ClinicianMessaging $messaging): Response
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $me = $this->staff($request);
        abort_unless(in_array($me->id, $thread->local_staff_ids, true), 403);
        $messaging->markRead($thread, $me);

        return $this->renderThread($thread, true);
    }

    public function startThread(Request $request, ClinicianMessaging $messaging): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:5000'], 'patient_id' => ['nullable', 'string'],
            'staff_ids' => ['array'], 'staff_ids.*' => ['integer'], 'other_tenant_id' => ['nullable', 'string'], 'urgent' => ['boolean'],
            'context_type' => ['nullable', Rule::in(['referral', 'escript', 'lab', 'general'])], 'context_id' => ['nullable', 'string'],
        ]);
        $me = $this->staff($request);
        $patient = isset($data['patient_id']) ? Patient::query()->findOrFail((string) $data['patient_id']) : null;
        $thread = $messaging->start($me, $data['subject'], $patient, array_values(array_map('intval', $data['staff_ids'] ?? [])), $data['other_tenant_id'] ?? null, $data['context_type'] ?? null, $data['context_id'] ?? null, (bool) ($data['urgent'] ?? false));
        $messaging->post($thread, $me, $data['body']);

        return redirect("/messages/{$thread->id}");
    }

    public function postMessage(Request $request, MessageThread $thread, ClinicianMessaging $messaging): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'visible_to_patient' => ['boolean'], 'corrects_id' => ['nullable', 'integer'], 'attachment' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:10240']]);
        $path = $request->hasFile('attachment') && $request->file('attachment') instanceof UploadedFile ? ($request->file('attachment')->store('message-attachments', 'local') ?: null) : null;
        $messaging->post($thread, $this->staff($request), $data['body'], $path, (bool) ($data['visible_to_patient'] ?? false), isset($data['corrects_id']) ? (int) $data['corrects_id'] : null);

        return back();
    }

    public function fileMessage(ThreadMessage $message, ClinicianMessaging $messaging): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $messaging->file($message);

        return back()->with('success', 'Filed to the patient record.');
    }

    // ---------------- referrals ----------------

    public function referrals(): Response
    {
        $this->authorize(Permission::CONSULTS_WRITE);

        return Inertia::render('Referrals/Index', [
            'referrals' => Referral::query()->with('patient')->latest()->limit(200)->get()->map(fn (Referral $r) => [
                'id' => $r->id, 'direction' => $r->direction, 'patient' => $r->patient->fullName(), 'other' => $r->other_name, 'specialty' => $r->specialty,
                'urgency' => $r->urgency, 'status' => $r->status, 'reason' => $r->reason, 'summary' => $r->summary, 'feedback' => $r->feedback,
                'appointment' => $r->appointment_at?->format('j M Y H:i'), 'network' => $r->other_tenant_id !== null,
            ])->values(),
        ]);
    }

    public function refer(Request $request, Patient $patient, Referrals $referrals): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $data = $request->validate([
            'to_tenant_id' => ['nullable', 'string'], 'to_name' => ['required_without:to_tenant_id', 'nullable', 'string', 'max:160'],
            'specialty' => ['required', Rule::in(Referrals::SPECIALTIES)], 'urgency' => ['required', Rule::in(['routine', 'soon', 'urgent'])],
            'reason' => ['required', 'string', 'max:3000'], 'categories' => ['array'], 'categories.*' => [Rule::in(ShareConsent::CATEGORIES)], 'consultation_id' => ['nullable', 'string'],
        ]);
        $referral = $referrals->refer($patient, $this->staff($request), $data['to_tenant_id'] ?? null, (string) ($data['to_name'] ?? ''), $data['specialty'], $data['urgency'], $data['reason'], array_values($data['categories'] ?? []), $data['consultation_id'] ?? null);

        return back()->with('success', $referral->other_tenant_id === null ? 'Referral saved. Print or email the referral letter.' : "Referral sent to {$referral->other_name}.");
    }

    public function referralAction(Request $request, Referral $referral, string $action, Referrals $referrals): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $referrals->update($referral, $action, $request->input('value'));

        return back()->with('success', 'Referral updated.');
    }

    public function letter(Referral $referral): HttpResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $summary = collect($referral->summary)->map(fn ($items, $k) => '<p><b>'.$e(ucfirst((string) $k)).':</b> '.$e(implode('; ', (array) $items)).'</p>')->implode('');
        $doctor = Staff::query()->find($referral->staff_id);
        $html = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 11px"><h2>'.$e($this->provider()->name).'</h2><h3>Referral letter</h3>'
            .'<p><b>To:</b> '.$e($referral->other_name).' ('.$e($referral->specialty).') &nbsp; <b>Urgency:</b> '.$e($referral->urgency).'</p>'
            .'<p><b>Patient:</b> '.$e($referral->patient->fullName()).', born '.$e($referral->patient->date_of_birth->toDateString()).'</p>'
            .'<p><b>Reason:</b> '.nl2br($e($referral->reason)).'</p>'.$summary
            .'<p style="margin-top:20px">'.$e($doctor?->name).' &nbsp; HPCSA '.$e($doctor?->professional_number).'</p></body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->render();

        return response((string) $pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="referral.pdf"']);
    }

    // ---------------- break-glass ----------------

    public function breakGlass(Request $request, BreakGlass $breakGlass): Response
    {
        $this->authorize(Permission::AUDIT_VIEW);
        $me = $this->user($request)->id;

        return Inertia::render('Compliance/BreakGlass', [
            'reasons' => BreakGlass::REASONS,
            'reviews' => DB::table('break_glass_reviews')->latest('id')->limit(50)->get()->map(fn ($r) => [
                'id' => $r->id, 'patient' => Patient::query()->whereKey((string) $r->patient_id)->first()?->fullName(), 'patientId' => $r->patient_id, 'reason' => BreakGlass::REASONS[$r->reason] ?? $r->reason,
                'details' => $r->details, 'mine' => (int) $r->requested_by === $me, 'approved' => $r->approved_at !== null,
                'open' => $breakGlass->canRead((string) $r->patient_id, $me) && (int) $r->requested_by === $me, 'expires' => $r->expires_at,
            ])->values(),
        ]);
    }

    public function breakGlassAction(Request $request, string $action, BreakGlass $breakGlass): RedirectResponse
    {
        $this->authorize(Permission::AUDIT_VIEW);
        $me = $this->user($request)->id;
        if ($action === 'request') {
            $breakGlass->request(Patient::query()->findOrFail($request->string('patient_id')->toString()), $me, $request->string('reason')->toString(), $request->string('details')->toString());
        } else {
            $breakGlass->approve($request->integer('review_id'), $me);
        }

        return back()->with('success', $action === 'request' ? 'Review requested. A second senior person must approve it.' : 'Review approved for 7 days, read-only.');
    }

    public function breakGlassRead(Request $request, Patient $patient, BreakGlass $breakGlass): Response
    {
        $this->authorize(Permission::AUDIT_VIEW);
        abort_unless($breakGlass->canRead($patient->id, $this->user($request)->id), 403, 'No approved review is open for this patient.');
        activity('compliance')->performedOn($patient)->log('Break-glass review: conversations read');
        $threads = MessageThread::query()->where('patient_id', $patient->id)->get();

        return Inertia::render('Compliance/BreakGlassRead', [
            'patient' => $patient->fullName(),
            'threads' => $threads->map(fn (MessageThread $t) => ['subject' => $t->subject, 'messages' => $t->messages()->orderBy('id')->get()->map(fn (ThreadMessage $m) => [
                'from' => $m->sender_label, 'at' => $m->created_at->format('j M Y H:i'), 'body' => $m->plainBody(), 'corrects' => $m->corrects_id,
            ])->values()])->values(),
        ]);
    }

    private function renderThread(MessageThread $thread, bool $canPost): Response
    {
        return Inertia::render('Messages/Show', [
            'thread' => ['id' => $thread->id, 'subject' => $thread->subject, 'urgent' => $thread->urgent, 'external' => $thread->other_tenant_id !== null,
                'patient' => $thread->patient_id === null ? null : Patient::query()->whereKey($thread->patient_id)->first()?->fullName()],
            'messages' => $thread->messages()->orderBy('id')->get()->map(fn (ThreadMessage $m) => [
                'id' => $m->id, 'from' => $m->sender_label, 'at' => $m->created_at->format('j M H:i'), 'body' => $m->plainBody(), 'corrects' => $m->corrects_id,
                'visible' => $m->visible_to_patient, 'filed' => $m->filed_at !== null, 'attachment' => $m->attachment_path !== null,
            ])->values(),
            'canPost' => $canPost,
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function staff(Request $request): Staff
    {
        return Staff::query()->findOrFail($this->user($request)->id);
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
