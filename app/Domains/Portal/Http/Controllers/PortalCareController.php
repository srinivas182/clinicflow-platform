<?php

declare(strict_types=1);

namespace App\Domains\Portal\Http\Controllers;

use App\Domains\Api\Fhir\FhirConsents;
use App\Domains\Clinical\Care\Pregnancy;
use App\Domains\Clinical\Care\Prevention;
use App\Domains\Clinical\Models\MessageThread;
use App\Domains\Clinical\Models\Referral;
use App\Domains\Clinical\Models\ThreadMessage;
use App\Domains\Hub\Actions\ShareConsent;
use App\Domains\Hub\Models\HubIdentity;
use App\Domains\Messaging\WhatsApp\WhatsAppRouter;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Domains\Portal\Actions\PortalSignIn;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The patient's view of their care: who discussed it and what was shared,
 * referrals and outcomes, messages the doctor made visible, immunisations,
 * pregnancy schedule, and what this practice may see (with revoke).
 */
class PortalCareController extends Controller
{
    public function index(Request $request, PortalSignIn $signIn, Prevention $prevention, Pregnancy $pregnancy, ShareConsent $consent): Response
    {
        $profiles = $signIn->profiles((string) $request->session()->get('portal_cell'));
        $ids = $profiles->pluck('id');
        $patient = $profiles->first();
        abort_unless($patient instanceof Patient, 403);
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return Inertia::render('Portal/Care', [
            'providerName' => $provider->name,
            'log' => DB::table('patient_access_log')->whereIn('patient_id', $ids)->latest('id')->limit(100)->get(['kind', 'summary', 'created_at']),
            'referrals' => Referral::query()->whereIn('patient_id', $ids)->where('direction', 'out')->latest()->get()->map(fn (Referral $r) => [
                'to' => $r->other_name, 'specialty' => $r->specialty, 'status' => $r->status, 'appointment' => $r->appointment_at?->format('j M Y H:i'),
                'outcome' => in_array($r->status, ['feedback'], true) ? $r->feedback : null,
            ])->values(),
            'messages' => ThreadMessage::query()->where('visible_to_patient', true)->whereIn('message_thread_id', MessageThread::query()->whereIn('patient_id', $ids)->select('id'))
                ->latest('id')->get()->map(fn (ThreadMessage $m) => ['from' => $m->sender_label, 'at' => $m->created_at->format('j M Y'), 'body' => $m->plainBody()])->values(),
            'immunisations' => ['due' => $prevention->immunisationsDue($patient), 'given' => DB::table('immunisations')->where('patient_id', $patient->id)->orderByDesc('given_on')->get(['vaccine', 'dose', 'given_on'])],
            'pregnancy' => $pregnancy->summary($patient),
            'sharing' => $consent->categories($patient->getAttribute('hub_identity_id'), $provider->id),
            'categories' => ShareConsent::CATEGORIES,
            'connected' => $this->connectedSystems(array_values($ids->map(fn ($v) => (string) $v)->all())),
            'fhirCategories' => FhirConsents::CATEGORIES,
            // The employee's own wellness screening results (never shared with the employer).
            'wellness' => DB::table('wellness_screenings')->join('wellness_registrations', 'wellness_registrations.id', '=', 'wellness_screenings.wellness_registration_id')
                ->join('wellness_events', 'wellness_events.id', '=', 'wellness_registrations.wellness_event_id')->whereIn('wellness_registrations.patient_id', $ids)
                ->orderByDesc('wellness_events.starts_at')->get(['wellness_events.title', 'wellness_events.starts_at', 'wellness_screenings.*'])
                ->map(fn ($w) => ['title' => $w->title, 'date' => substr((string) $w->starts_at, 0, 10), 'bp' => $w->bp_systolic ? "{$w->bp_systolic}/{$w->bp_diastolic}" : null,
                    'glucose' => $w->glucose, 'cholesterol' => $w->cholesterol, 'bmi' => $w->bmi, 'flu' => (bool) $w->flu_vaccinated, 'flags' => json_decode((string) $w->flags, true)])->values(),
            'whatsapp' => ['available' => WhatsAppRouter::enabledFor($provider->id), 'optedIn' => $patient->getAttribute('whatsapp_opt_in_at') !== null],
        ]);
    }

    /**
     * Systems the practice has connected with clinical-record (FHIR) access, and what each patient profile has allowed.
     *
     * @param  list<string>  $patientIds
     * @return list<array<string, mixed>>
     */
    private function connectedSystems(array $patientIds): array
    {
        $consents = app(FhirConsents::class);

        $systems = DB::table('api_keys')->whereNull('revoked_at')->where('scopes', 'like', '%fhir:read%')->orderBy('name')->get()
            ->map(fn ($k) => ['key' => (int) $k->id, 'name' => (string) $k->name,
                'patients' => collect($patientIds)->mapWithKeys(fn (string $id) => [$id => $consents->allowed($id, (int) $k->id)])->all()])->values()->all();

        return array_values($systems);
    }

    public function connected(Request $request, PortalSignIn $signIn, FhirConsents $consents): RedirectResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'string'], 'key_id' => ['required', 'integer'], 'categories' => ['array'], 'categories.*' => ['string']]);
        $mine = $signIn->profiles((string) $request->session()->get('portal_cell'))->pluck('id')->map(fn ($v) => (string) $v)->all();
        abort_unless(in_array($data['patient_id'], $mine, true), 403);
        $categories = array_values($data['categories'] ?? []);
        $categories === [] ? $consents->revoke($data['patient_id'], (int) $data['key_id'])
            : $consents->grant($data['patient_id'], (int) $data['key_id'], $categories, 'portal', null);

        return back()->with('success', $categories === [] ? 'Sharing withdrawn.' : 'Sharing updated.');
    }

    public function sharing(Request $request, PortalSignIn $signIn, ShareConsent $consent): RedirectResponse
    {
        $patient = $signIn->profiles((string) $request->session()->get('portal_cell'))->first();
        abort_unless($patient instanceof Patient, 403);
        $identity = HubIdentity::query()->find($patient->getAttribute('hub_identity_id'));
        abort_unless($identity instanceof HubIdentity, 422, 'Your records are not on the Clinic Flow network yet.');
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        $categories = array_values((array) $request->input('categories', []));
        $categories === [] ? $consent->revoke($identity, $provider->id) : $consent->grant($identity, $provider->id, $categories, $request->input('until'), 'portal');

        return back()->with('success', $categories === [] ? 'This practice can no longer see your shared history.' : 'Your sharing choices are saved.');
    }
}
