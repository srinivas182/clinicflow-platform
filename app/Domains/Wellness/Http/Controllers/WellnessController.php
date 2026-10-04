<?php

declare(strict_types=1);

namespace App\Domains\Wellness\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Models\Provider;
use App\Domains\Wellness\Actions\CorporateWellness;
use App\Domains\Wellness\Actions\EmployerReporting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Corporate wellness: accounts and events (front office), screening capture
 * (nurses), and the public employee registration page.
 */
class WellnessController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::APPOINTMENTS_BOOK);

        return Inertia::render('Wellness/Index', [
            'accounts' => DB::table('corporate_accounts')->orderBy('name')->get()->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'contact' => $a->contact_name, 'email' => $a->contact_email, 'rate' => $a->rate_cents / 100, 'active' => (bool) $a->active])->values(),
            'events' => DB::table('wellness_events')->join('corporate_accounts', 'corporate_accounts.id', '=', 'wellness_events.corporate_account_id')->orderByDesc('wellness_events.starts_at')->limit(50)
                ->get(['wellness_events.*', 'corporate_accounts.name as company'])->map(fn ($e) => [
                    'id' => $e->id, 'company' => $e->company, 'title' => $e->title, 'location' => $e->location, 'starts' => $e->starts_at, 'ends' => $e->ends_at, 'status' => $e->status,
                    'link' => url('/wellness/'.$e->token), 'registered' => DB::table('wellness_registrations')->where('wellness_event_id', $e->id)->count(),
                    'screened' => DB::table('wellness_registrations')->where('wellness_event_id', $e->id)->where('status', 'screened')->count(),
                    'invoice' => ($inv = DB::table('corporate_invoices')->where('wellness_event_id', $e->id)->first()) === null ? null
                        : ['id' => $inv->id, 'number' => $inv->number, 'total' => $inv->total_cents / 100, 'paid' => $inv->paid_at !== null],
                    'reportSent' => DB::table('wellness_report_links')->where('wellness_event_id', $e->id)->exists(),
                ])->values(),
            'services' => CorporateWellness::SERVICES,
        ]);
    }

    public function saveAccount(Request $request, CorporateWellness $wellness): RedirectResponse
    {
        $this->authorize(Permission::APPOINTMENTS_BOOK);
        $data = $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:160'], 'contact_name' => ['nullable', 'string', 'max:120'], 'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'string', 'max:20'], 'billing_address' => ['nullable', 'string', 'max:500'], 'vat_number' => ['nullable', 'regex:/^4\d{9}$/'], 'rate' => ['required', 'numeric', 'min:0']]);
        $wellness->saveAccount(isset($data['id']) ? (int) $data['id'] : null, ['name' => $data['name'], 'contact_name' => $data['contact_name'] ?? null, 'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null, 'billing_address' => $data['billing_address'] ?? null, 'vat_number' => $data['vat_number'] ?? null, 'rate_cents' => (int) round(((float) $data['rate']) * 100)]);

        return back()->with('success', 'Corporate account saved.');
    }

    public function createEvent(Request $request, CorporateWellness $wellness): RedirectResponse
    {
        $this->authorize(Permission::APPOINTMENTS_BOOK);
        $data = $request->validate(['corporate_account_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:160'], 'location' => ['required', 'string', 'max:200'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date'], 'slot_minutes' => ['required', 'integer'], 'per_slot' => ['required', 'integer'],
            'services' => ['required', 'array', 'min:1'], 'services.*' => [Rule::in(CorporateWellness::SERVICES)]]);
        $wellness->createEvent((int) $data['corporate_account_id'], $data['title'], $data['location'], $data['starts_at'], $data['ends_at'], (int) $data['slot_minutes'], (int) $data['per_slot'], array_values($data['services']));

        return back()->with('success', 'Wellness day created. Share the registration link with the employer.');
    }

    public function event(int $event): Response
    {
        $this->authorize(Permission::TRIAGE_RECORD);
        $e = DB::table('wellness_events')->where('id', $event)->first();
        abort_if($e === null, 404);

        return Inertia::render('Wellness/Event', [
            'event' => ['id' => $e->id, 'title' => $e->title, 'location' => $e->location, 'starts' => $e->starts_at, 'services' => json_decode((string) $e->services, true)],
            'registrations' => DB::table('wellness_registrations')->join('patients', 'patients.id', '=', 'wellness_registrations.patient_id')->leftJoin('wellness_screenings', 'wellness_screenings.wellness_registration_id', '=', 'wellness_registrations.id')
                ->where('wellness_event_id', $event)->orderBy('slot_at')->get(['wellness_registrations.id', 'wellness_registrations.slot_at', 'wellness_registrations.status', 'patients.first_names', 'patients.surname', 'wellness_screenings.flags'])
                ->map(fn ($r) => ['id' => $r->id, 'slot' => substr((string) $r->slot_at, 11, 5), 'name' => "{$r->first_names} {$r->surname}", 'status' => $r->status, 'flags' => $r->flags === null ? [] : json_decode((string) $r->flags, true)])->values(),
        ]);
    }

    public function screen(Request $request, int $registration, CorporateWellness $wellness): RedirectResponse
    {
        $this->authorize(Permission::TRIAGE_RECORD);
        $v = $request->validate(['bp_systolic' => ['nullable', 'integer', 'between:60,260'], 'bp_diastolic' => ['nullable', 'integer', 'between:30,160'], 'glucose' => ['nullable', 'numeric', 'between:1,40'],
            'cholesterol' => ['nullable', 'numeric', 'between:1,20'], 'height_cm' => ['nullable', 'numeric', 'between:100,230'], 'weight_kg' => ['nullable', 'numeric', 'between:25,300'], 'flu_vaccinated' => ['boolean']]);
        $flags = $wellness->screen($registration, $v, (int) $request->user()?->getAuthIdentifier());

        return back()->with('success', $flags === [] ? 'Saved. All results in the healthy range.' : 'Saved. Follow-up suggested: '.implode(', ', $flags).'.');
    }

    // ---------------- employer billing and reports ----------------

    public function employer(Request $request, int $event, string $action, EmployerReporting $reporting): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize(Permission::BILLING_COLLECT);
        abort_unless(DB::table('wellness_events')->where('id', $event)->exists(), 404);

        return match ($action) {
            'invoice' => tap(back()->with('success', 'Invoice created.'), fn () => $reporting->invoice($event)),
            'send' => tap(back()->with('success', 'Summary and invoice link emailed to the employer contact.'), fn () => $reporting->sendToEmployer($event)),
            'report.pdf' => response($reporting->reportPdf($event), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="wellness-summary.pdf"']),
            'invoice.pdf' => response($reporting->invoicePdf($event), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="wellness-invoice.pdf"']),
            default => abort(404),
        };
    }

    public function invoicePaid(Request $request, int $invoice, EmployerReporting $reporting): RedirectResponse
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $reporting->markPaid($invoice, $request->string('reference')->toString());

        return back()->with('success', 'Invoice marked paid.');
    }

    public function employerLink(string $token, ?string $doc, EmployerReporting $reporting): \Symfony\Component\HttpFoundation\Response
    {
        $event = $reporting->eventForToken($token);
        abort_if($event === null, 404, 'This link has expired. Ask the practice for a new one.');
        $pdf = $doc === 'invoice' ? $reporting->invoicePdf($event) : $reporting->reportPdf($event);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="wellness-'.($doc === 'invoice' ? 'invoice' : 'summary').'.pdf"']);
    }

    // ---------------- public employee registration ----------------

    public function publicForm(string $token, CorporateWellness $wellness): Response
    {
        $e = DB::table('wellness_events')->join('corporate_accounts', 'corporate_accounts.id', '=', 'wellness_events.corporate_account_id')->where('token', $token)->first(['wellness_events.*', 'corporate_accounts.name as company']);
        abort_if($e === null, 404);
        $provider = tenant();

        return Inertia::render('Wellness/Register', [
            'token' => $token, 'practice' => $provider instanceof Provider ? $provider->name : '', 'company' => $e->company, 'title' => $e->title, 'location' => $e->location,
            'open' => $e->status === 'open', 'slots' => array_values(array_filter($wellness->slots((int) $e->id), fn ($s) => $s['free'] > 0)),
            'services' => json_decode((string) $e->services, true),
        ]);
    }

    public function publicRegister(Request $request, string $token, CorporateWellness $wellness): RedirectResponse
    {
        $data = $request->validate(['first_names' => ['required', 'string', 'max:100'], 'surname' => ['required', 'string', 'max:100'], 'id_number' => ['nullable', 'digits:13'],
            'date_of_birth' => ['required_without:id_number', 'nullable', 'date', 'before:today'], 'cell' => ['required', 'regex:/^0[6-8]\d{8}$/'], 'email' => ['nullable', 'email'],
            'slot_at' => ['required', 'date'], 'consent' => ['accepted']], ['cell.regex' => 'Enter a South African cell number, e.g. 0821234567.', 'consent.accepted' => 'Please agree to take part.']);
        $wellness->register($token, ['first_names' => $data['first_names'], 'surname' => $data['surname'], 'id_number' => $data['id_number'] ?? null, 'date_of_birth' => $data['date_of_birth'] ?? null,
            'cell' => $data['cell'], 'email' => $data['email'] ?? null, 'slot_at' => $data['slot_at'], 'consent' => true]);

        return back()->with('success', 'You are registered. We sent your booking by SMS.');
    }
}
