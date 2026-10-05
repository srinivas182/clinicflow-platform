<?php

declare(strict_types=1);

use App\Domains\Api\Fhir\FhirR4Controller;
use App\Domains\Api\Http\Controllers\ApiKeysController;
use App\Domains\Api\Http\Controllers\ApiV1Controller;
use App\Domains\Billing\Http\Controllers\BillingSettingsController;
use App\Domains\Billing\Http\Controllers\GatewayWebhookController;
use App\Domains\Billing\Http\Controllers\InvoiceController;
use App\Domains\Billing\Http\Controllers\PayLinkController;
use App\Domains\Billing\Http\Controllers\PaymentSettingsController;
use App\Domains\Billing\Http\Controllers\PrepaidController;
use App\Domains\Branches\Http\Controllers\BranchController;
use App\Domains\Claims\Http\Controllers\ClaimController;
use App\Domains\Clinical\Http\Controllers\CareController;
use App\Domains\Clinical\Http\Controllers\ConsultController;
use App\Domains\Clinical\Http\Controllers\DoctorQueueController;
use App\Domains\Clinical\Http\Controllers\QuoteController;
use App\Domains\Clinical\Http\Controllers\TriageController;
use App\Domains\Documents\Http\Controllers\TemplateController;
use App\Domains\Finance\Http\Controllers\FinanceController;
use App\Domains\Finance\Http\Controllers\FinanceOpsController;
use App\Domains\Hub\Http\Controllers\EscriptController;
use App\Domains\Hub\Http\Controllers\NetworkController;
use App\Domains\Identity\Http\Controllers\HandoffController;
use App\Domains\Lab\Http\Controllers\LabCatalogController;
use App\Domains\Lab\Http\Controllers\LabController;
use App\Domains\Lab\Inbound\LabInboundController;
use App\Domains\Lab\Inbound\LabOrdersOutController;
use App\Domains\Locums\Http\Controllers\LocumController;
use App\Domains\Messaging\Http\Controllers\MessagingSettingsController;
use App\Domains\Messaging\Http\Controllers\WhatsAppController;
use App\Domains\Patients\Http\Controllers\PatientAdminController;
use App\Domains\Patients\Http\Controllers\PatientController;
use App\Domains\Pharmacy\Http\Controllers\DeliveryController;
use App\Domains\Pharmacy\Http\Controllers\PharmacyController;
use App\Domains\Pharmacy\Http\Controllers\ProcurementController;
use App\Domains\Platform\Http\Controllers\CustomDomainController;
use App\Domains\Platform\Http\Controllers\ProviderSiteController;
use App\Domains\Platform\Http\Controllers\SubscriptionBillingController;
use App\Domains\Platform\Http\Controllers\SupportController;
use App\Domains\Platform\Http\Controllers\WebsiteSettingsController;
use App\Domains\Portal\Http\Controllers\PortalCareController;
use App\Domains\Portal\Http\Controllers\PortalController;
use App\Domains\Portal\Http\Controllers\PortalResultsController;
use App\Domains\Portal\Http\Middleware\EnsurePortalPatient;
use App\Domains\Prescribing\Http\Controllers\PrescriptionController;
use App\Domains\Reports\ReportsController;
use App\Domains\Scheduling\Http\Controllers\AppointmentController;
use App\Domains\Scheduling\Http\Controllers\CalendarController;
use App\Domains\Scheduling\Http\Controllers\RosterController;
use App\Domains\Scribe\Http\ScribeController;
use App\Domains\Scribe\Http\ScribeSessionController;
use App\Domains\Telemedicine\Http\Controllers\ChatController;
use App\Domains\Telemedicine\Http\Controllers\OnlineConsultController;
use App\Domains\Telemedicine\Http\Controllers\TeleConsultController;
use App\Domains\Visits\Http\Controllers\DeviceController;
use App\Domains\Visits\Http\Controllers\FrontDeskController;
use App\Domains\Wallet\Http\Controllers\WalletController;
use App\Domains\Website\Http\Controllers\WebsiteToolsController;
use App\Domains\Wellness\Http\Controllers\WellnessController;
use App\Http\Controllers\Provider\ProviderHomeController;
use App\Http\Middleware\SupportSessionGuard;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
 * Provider routes: answer on a provider's subdomain or verified custom
 * domain, inside that provider's own database.
 */
/*
 * Gateway webhooks: no session or CSRF; verified by the gateway adapter.
 */
Route::middleware([InitializeTenancyByDomain::class, PreventAccessFromCentralDomains::class])
    ->post('/webhooks/payments/{gateway}', [GatewayWebhookController::class, 'provider'])
    ->whereIn('gateway', ['payfast', 'paystack', 'peach', 'yoco'])
    ->name('webhooks.provider');

/*
 * Public practice API v1: key-authenticated, no session or CSRF.
 */
Route::middleware(['api', InitializeTenancyByDomain::class, PreventAccessFromCentralDomains::class])->prefix('api/v1')->name('practice.api.')->group(function (): void {
    Route::get('openapi.json', [ApiV1Controller::class, 'openapi'])->middleware('throttle:30,1')->name('openapi');
    Route::get('availability', [ApiV1Controller::class, 'availability'])->middleware('api.key:availability:read')->name('availability');
    Route::get('appointments', [ApiV1Controller::class, 'appointments'])->middleware('api.key:appointments:read')->name('appointments');
    Route::get('patients', [ApiV1Controller::class, 'patient'])->middleware('api.key:patients:read')->name('patients');
    Route::get('invoices', [ApiV1Controller::class, 'invoices'])->middleware('api.key:invoices:read')->name('invoices');
    Route::get('prices', [ApiV1Controller::class, 'prices'])->middleware('api.key:prices:read')->name('prices');
    Route::post('appointments/book', [ApiV1Controller::class, 'book'])->middleware('api.key:appointments:write')->name('appointments.book');
    Route::post('appointments/{appointment}/reschedule', [ApiV1Controller::class, 'reschedule'])->middleware('api.key:appointments:write')->name('appointments.reschedule');
    Route::post('appointments/{appointment}/cancel', [ApiV1Controller::class, 'cancel'])->middleware('api.key:appointments:write')->name('appointments.cancel');
    Route::post('patients/register', [ApiV1Controller::class, 'registerPatient'])->middleware('api.key:patients:write')->name('patients.register');
});

/*
 * Connected lab systems send results (HL7 v2 over HTTPS, or FHIR); lab:write permission.
 */
Route::middleware(['api', InitializeTenancyByDomain::class, PreventAccessFromCentralDomains::class, 'api.key:lab:orders'])->prefix('api/lab/v1')->name('practice.lab-orders.')->group(function (): void {
    Route::get('orders', [LabOrdersOutController::class, 'fhir'])->name('fhir');
    Route::get('orders.hl7', [LabOrdersOutController::class, 'hl7'])->name('hl7');
    Route::post('orders/{order}/received', [LabOrdersOutController::class, 'received'])->name('received');
});

Route::middleware(['api', InitializeTenancyByDomain::class, PreventAccessFromCentralDomains::class, 'api.key:lab:write'])->prefix('api/lab/v1')->name('practice.lab-inbound.')->group(function (): void {
    Route::post('hl7', [LabInboundController::class, 'hl7'])->name('hl7');
    Route::post('fhir', [LabInboundController::class, 'fhir'])->name('fhir');
});

/*
 * FHIR R4 read API: same practice API keys, fhir:read permission, per-patient consent.
 */
Route::middleware(['api', InitializeTenancyByDomain::class, PreventAccessFromCentralDomains::class])->prefix('api/fhir/r4')->name('practice.fhir.')->group(function (): void {
    Route::get('metadata', [FhirR4Controller::class, 'metadata'])->middleware('throttle:30,1')->name('metadata');
    Route::get('Patient', [FhirR4Controller::class, 'patients'])->middleware('api.key:fhir:read')->name('patients');
    Route::get('Patient/{id}', [FhirR4Controller::class, 'patient'])->middleware('api.key:fhir:read')->name('patient');
    Route::get('{type}', [FhirR4Controller::class, 'search'])->whereIn('type', ['AllergyIntolerance', 'Condition', 'MedicationRequest', 'Immunization', 'Observation'])->middleware('api.key:fhir:read')->name('search');
});

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    SupportSessionGuard::class,
])->group(function (): void {
    Route::get('/auth/handoff/{token}', HandoffController::class)->name('provider.handoff');
    Route::get('/auth/support/{token}', [SupportController::class, 'enter'])->name('provider.support.enter');

    Route::get('/pay/{token}', [PayLinkController::class, 'show'])->middleware('throttle:30,1')->name('paylink.show');
    Route::get('/pay/{token}/done', [PayLinkController::class, 'done'])->name('paylink.done');
    Route::get('/calendar/{token}.ics', [CalendarController::class, 'ical'])->middleware('throttle:60,1')->name('calendar.ical');
    Route::get('/wellness/{token}', [WellnessController::class, 'publicForm'])->where('token', '[A-Za-z0-9]{32}')->name('wellness.public');
    Route::post('/wellness/{token}', [WellnessController::class, 'publicRegister'])->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:10,1')->name('wellness.register');
    Route::get('/wellness-report/{token}/{doc?}', [WellnessController::class, 'employerLink'])->where(['token' => '[A-Za-z0-9]{40}', 'doc' => 'invoice'])->middleware('throttle:30,1')->name('wellness.employer');
    Route::get('/media/{media}/{size?}', [WebsiteToolsController::class, 'serveMedia'])->whereIn('size', ['thumb'])->name('media.serve');
    Route::get('/feedback/{token}', [WebsiteToolsController::class, 'feedbackForm'])->name('feedback.form');
    Route::post('/feedback/{token}', [WebsiteToolsController::class, 'feedbackSubmit'])->middleware('throttle:10,1')->name('feedback.submit');
    Route::get('/widget.js', [WebsiteToolsController::class, 'widgetScript'])->name('widget.script');
    Route::get('/widget/slots', [WebsiteToolsController::class, 'widgetSlots'])->middleware('throttle:60,1')->name('widget.slots');
    Route::get('/sitemap.xml', [WebsiteToolsController::class, 'sitemap'])->name('site.sitemap');
    Route::get('/robots.txt', [WebsiteToolsController::class, 'robots'])->name('site.robots');

    // Patient portal (patients sign in with their cell number and an SMS code).
    Route::prefix('my')->name('portal.')->group(function (): void {
        Route::get('/login', [PortalController::class, 'login'])->name('login');
        Route::post('/login', [PortalController::class, 'start'])->middleware('throttle:5,1')->name('start');
        Route::get('/verify', [PortalController::class, 'verifyForm'])->name('verify');
        Route::post('/verify', [PortalController::class, 'verify'])->middleware('throttle:10,1')->name('verify.store');
        Route::middleware(EnsurePortalPatient::class)->group(function (): void {
            Route::get('/', [PortalController::class, 'home'])->name('home');
            Route::post('/logout', [PortalController::class, 'logout'])->name('logout');
            Route::post('/profiles/{patient}', [PortalController::class, 'switchProfile'])->name('profile');
            Route::post('/appointments', [PortalController::class, 'book'])->middleware('throttle:10,1')->name('book');
            Route::post('/appointments/{appointment}/cancel', [PortalController::class, 'cancel'])->name('cancel');
            Route::post('/invoices/{invoice}/pay', [PortalController::class, 'pay'])->name('pay');
            Route::get('/consults/{appointment}', [TeleConsultController::class, 'patientCall'])->name('consult');
            Route::get('/online', [OnlineConsultController::class, 'portal'])->name('online');
            Route::get('/online/slots', [OnlineConsultController::class, 'slots'])->name('online.slots');
            Route::post('/online', [OnlineConsultController::class, 'patientBook'])->middleware('throttle:10,1')->name('online.book');
            Route::post('/online/{appointment}/cancel', [OnlineConsultController::class, 'patientCancel'])->name('online.cancel');
            Route::get('/online/{appointment}/state', [OnlineConsultController::class, 'patientState'])->name('online.state');
            Route::get('/chats/{thread}', [ChatController::class, 'patientShow'])->name('chats.show');
            Route::post('/chats/{thread}', [ChatController::class, 'patientPost'])->middleware('throttle:60,1')->name('chats.post');
            Route::get('/practices', [EscriptController::class, 'patientPractices'])->name('practices');
            Route::get('/results', [PortalResultsController::class, 'index'])->name('results');
            Route::get('/care', [PortalCareController::class, 'index'])->name('care');
            Route::get('/pharmacies', [DeliveryController::class, 'portalCompare'])->name('pharmacies');
            Route::post('/care/sharing', [PortalCareController::class, 'sharing'])->name('care.sharing');
            Route::post('/care/connected', [PortalCareController::class, 'connected'])->name('care.connected');
            Route::post('/scribe/{session}/{answer}', [ScribeSessionController::class, 'patientAnswer'])->whereIn('answer', ['agree', 'decline'])->name('scribe.answer');
            Route::post('/whatsapp', [WhatsAppController::class, 'portalOptIn'])->middleware('throttle:10,1')->name('whatsapp');
            Route::post('/results/{order}/request', [PortalResultsController::class, 'request'])->middleware('throttle:10,1')->name('results.request');
            Route::get('/results/{order}/download/{kind}', [PortalResultsController::class, 'download'])->whereIn('kind', ['report', 'lab'])->name('results.download');
            Route::post('/practices/{provider}/revoke', [EscriptController::class, 'patientRevoke'])->name('practices.revoke');
        });
    });

    // The provider's public website.
    Route::get('/', [ProviderSiteController::class, 'show'])->name('site.home');
    Route::get('/p/{slug}', [ProviderSiteController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('site.page');

    Route::get('/kiosk/{token}', [DeviceController::class, 'kiosk'])->name('kiosk');
    Route::post('/kiosk/{token}', [DeviceController::class, 'kioskCheckIn'])->middleware('throttle:20,1')->name('kiosk.checkin');
    Route::get('/display/{token}', [DeviceController::class, 'display'])->name('display');

    Route::middleware(['auth', 'workspace', 'provider.writable'])->group(function (): void {
        Route::get('/workspace', ProviderHomeController::class)->name('provider.home');

        Route::get('/settings/website', [WebsiteSettingsController::class, 'index'])->name('settings.website');
        Route::get('/settings/website/media', [WebsiteToolsController::class, 'media'])->name('website.media');
        Route::post('/settings/website/media', [WebsiteToolsController::class, 'upload'])->middleware('throttle:30,1')->name('website.media.upload');
        Route::put('/settings/website/media/{media}', [WebsiteToolsController::class, 'updateMedia'])->name('website.media.update');
        Route::delete('/settings/website/media/{media}', [WebsiteToolsController::class, 'deleteMedia'])->name('website.media.delete');
        Route::get('/settings/website/feedback', [WebsiteToolsController::class, 'reviews'])->name('website.reviews');
        Route::put('/settings/website/feedback', [WebsiteToolsController::class, 'reviewSettings'])->name('website.reviews.settings');
        Route::post('/settings/website/feedback/{review}/{action}', [WebsiteToolsController::class, 'reviewAction'])->whereIn('action', ['reply', 'flag'])->name('website.reviews.act');
        Route::put('/settings/website/pages/{page}', [WebsiteSettingsController::class, 'updatePage'])->name('settings.website.page');
        Route::put('/settings/website/details', [WebsiteSettingsController::class, 'updateDetails'])->name('settings.website.details');
        Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
        Route::get('/patients/register', [PatientController::class, 'create'])->name('patients.create');
        Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');

        Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');

        Route::get('/rosters', [RosterController::class, 'index'])->name('rosters.index');
        Route::post('/rosters', [RosterController::class, 'store'])->name('rosters.store');
        Route::post('/rooms', [RosterController::class, 'storeRoom'])->name('rooms.store');

        Route::get('/front-desk', [FrontDeskController::class, 'index'])->name('frontdesk');
        Route::post('/visits', [FrontDeskController::class, 'checkIn'])->name('visits.checkin');
        Route::post('/visits/{visit}/stage', [FrontDeskController::class, 'move'])->name('visits.move');
        Route::post('/visits/{visit}/remove', [FrontDeskController::class, 'remove'])->name('visits.remove');

        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/invoices/{invoice}/lines', [InvoiceController::class, 'addLine'])->name('invoices.lines.store');
        Route::delete('/invoice-lines/{line}', [InvoiceController::class, 'removeLine'])->name('invoices.lines.destroy');
        Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'pay'])->name('invoices.pay');
        Route::post('/payments/{payment}/refunds', [InvoiceController::class, 'refund'])->name('payments.refund');

        Route::get('/settings/payments', [PaymentSettingsController::class, 'index'])->name('settings.payments');
        Route::put('/settings/payments/{gateway}', [PaymentSettingsController::class, 'update'])->name('settings.payments.update');
        Route::post('/settings/payments/{gateway}/test', [PaymentSettingsController::class, 'test'])->name('settings.payments.test');
        Route::get('/settings/subscription', [SubscriptionBillingController::class, 'index'])->name('settings.subscription');
        Route::post('/settings/subscription/invoices/{number}/auto-pay', [SubscriptionBillingController::class, 'payWithAutoDebit'])->name('settings.subscription.autopay');
        Route::post('/settings/subscription/auto-debit/stop', [SubscriptionBillingController::class, 'stopAutoDebit'])->name('settings.subscription.autodebit.stop');

        Route::get('/settings/billing', [BillingSettingsController::class, 'edit'])->name('settings.billing');
        Route::put('/settings/billing', [BillingSettingsController::class, 'update'])->name('settings.billing.update');

        Route::get('/triage', [TriageController::class, 'index'])->name('triage.index');
        Route::post('/triage/suggest', [TriageController::class, 'suggest'])->name('triage.suggest');
        Route::get('/triage/{visit}', [TriageController::class, 'show'])->name('triage.show');
        Route::post('/triage/{visit}', [TriageController::class, 'store'])->name('triage.store');
        Route::post('/patients/{patient}/allergies', [TriageController::class, 'addAllergy'])->name('allergies.store');
        Route::post('/allergies/{allergy}/remove', [TriageController::class, 'removeAllergy'])->name('allergies.remove');

        Route::get('/doctor', [DoctorQueueController::class, 'index'])->name('doctor.queue');

        Route::get('/consults/{visit}', [ConsultController::class, 'show'])->name('consults.show');
        Route::put('/consultations/{consultation}', [ConsultController::class, 'save'])->name('consults.save');
        Route::post('/consultations/{consultation}/complete', [ConsultController::class, 'complete'])->name('consults.complete');
        Route::get('/reference/icd10', [ConsultController::class, 'icd10'])->name('reference.icd10');
        Route::get('/reference/medicines', [ConsultController::class, 'medicines'])->name('reference.medicines');
        Route::post('/consultations/{consultation}/prescription', [PrescriptionController::class, 'saveDraft'])->name('prescriptions.draft');
        Route::post('/prescriptions/{prescription}/pin', [PrescriptionController::class, 'requestPin'])->middleware('throttle:10,1')->name('prescriptions.pin');
        Route::post('/prescriptions/{prescription}/sign', [PrescriptionController::class, 'sign'])->middleware('throttle:20,1')->name('prescriptions.sign');
        Route::post('/prescriptions/{prescription}/amend', [PrescriptionController::class, 'amend'])->name('prescriptions.amend');
        Route::get('/prescriptions/{prescription}/pdf', [PrescriptionController::class, 'pdf'])->name('prescriptions.pdf');

        Route::get('/claims', [ClaimController::class, 'index'])->name('claims.index');
        Route::post('/invoices/{invoice}/claim', [ClaimController::class, 'submit'])->name('claims.submit');
        Route::post('/patients/{patient}/eligibility', [ClaimController::class, 'eligibility'])->name('eligibility.check');
        Route::post('/claims/remittances/import', [ClaimController::class, 'importRemittances'])->name('claims.remittances');

        Route::get('/pharmacy', [PharmacyController::class, 'index'])->name('pharmacy.index');
        Route::post('/pharmacy/visits/{visit}/dispense', [PharmacyController::class, 'dispense'])->name('pharmacy.dispense');
        Route::post('/pharmacy/visits/{visit}/query', [PharmacyController::class, 'query'])->name('pharmacy.query');
        Route::post('/pharmacy/visits/{visit}/collect', [PharmacyController::class, 'collect'])->middleware('throttle:30,1')->name('pharmacy.collect');
        Route::post('/pharmacy/stock', [PharmacyController::class, 'receive'])->name('pharmacy.stock');
        Route::post('/pharmacy/owing/{owing}/fulfil', [PharmacyController::class, 'fulfil'])->name('pharmacy.owing.fulfil');

        Route::post('/visits/{visit}/quotes', [QuoteController::class, 'store'])->name('quotes.store');
        Route::post('/quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('quotes.accept');
        Route::post('/quotes/{quote}/decline', [QuoteController::class, 'decline'])->name('quotes.decline');

        Route::post('/visits/{visit}/lab-orders', [LabController::class, 'order'])->name('lab.order');
        Route::get('/lab', [LabController::class, 'worklist'])->name('lab.worklist');
        Route::get('/lab/unmatched', [LabInboundController::class, 'queue'])->name('lab.unmatched');
        Route::post('/lab/unmatched/{message}/{action}', [LabInboundController::class, 'act'])->whereIn('action', ['match', 'reject'])->name('lab.unmatched.act');
        Route::get('/results', [LabController::class, 'inbox'])->name('lab.inbox');
        Route::post('/lab-orders/{order}/explain', [LabController::class, 'explain'])->middleware('throttle:20,1')->name('lab.explain');
        Route::post('/lab-orders/{order}/{step}', [LabController::class, 'step'])->whereIn('step', ['collect', 'assign', 'results', 'verify', 'acknowledge', 'review', 'release', 'discuss'])->name('lab.step');
        Route::post('/visits/{visit}/network-lab-orders', [LabController::class, 'networkOrder'])->name('lab.network.order');
        Route::get('/reference/labs', [LabController::class, 'labs'])->name('reference.labs');
        Route::get('/reference/labs/{lab}/menu', [LabController::class, 'menu'])->name('reference.labs.menu');
        Route::post('/lab/requests/{hub}/{action}', [LabController::class, 'requestAction'])->whereIn('action', ['accept', 'reject'])->name('lab.requests.act');
        Route::post('/lab/self-orders', [LabController::class, 'selfOrder'])->name('lab.self');
        Route::put('/results/cover', [LabController::class, 'setCover'])->name('lab.cover');
        Route::put('/settings/lab', [LabController::class, 'settings'])->name('lab.settings');
        Route::get('/lab/catalogue', [LabCatalogController::class, 'index'])->name('lab.catalogue');
        Route::post('/lab/catalogue/import', [LabCatalogController::class, 'import'])->name('lab.catalogue.import');
        Route::put('/lab/catalogue/{test}', [LabCatalogController::class, 'update'])->name('lab.catalogue.update');
        Route::post('/lab/catalogue/{test}/ranges', [LabCatalogController::class, 'proposeRanges'])->name('lab.catalogue.ranges');
        Route::post('/lab/range-changes/{change}/approve', [LabCatalogController::class, 'approve'])->name('lab.ranges.approve');
        Route::post('/lab/panels', [LabCatalogController::class, 'savePanel'])->name('lab.panels.save');

        Route::get('/patients/import', [PatientAdminController::class, 'imports'])->name('patients.imports');
        Route::post('/patients/import', [PatientAdminController::class, 'import'])->name('patients.import');
        Route::post('/patients/{patient}/consent', [PatientAdminController::class, 'consent'])->name('patients.consent');
        Route::get('/compliance/audit', [PatientAdminController::class, 'audit'])->name('compliance.audit');
        Route::get('/compliance/audit/export', [PatientAdminController::class, 'auditExport'])->name('compliance.audit.export');
        Route::get('/compliance/patients/{patient}/export', [PatientAdminController::class, 'exportPatient'])->name('compliance.patient.export');

        Route::get('/settings/messaging', [MessagingSettingsController::class, 'index'])->name('settings.messaging');
        Route::put('/settings/messaging/sender', [MessagingSettingsController::class, 'saveSender'])->name('settings.messaging.sender');
        Route::put('/settings/messaging/wording', [MessagingSettingsController::class, 'saveWording'])->name('settings.messaging.wording');
        Route::get('/finance', [FinanceController::class, 'dashboard'])->name('finance.dashboard');

        Route::get('/network', [NetworkController::class, 'index'])->name('network.index');
        Route::get('/settings/whatsapp', [WhatsAppController::class, 'settings'])->name('whatsapp.settings');
        Route::put('/settings/whatsapp', [WhatsAppController::class, 'toggle'])->name('whatsapp.toggle');
        Route::post('/patients/{patient}/whatsapp', [WhatsAppController::class, 'optIn'])->name('patients.whatsapp');
        Route::get('/deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::put('/settings/delivery', [DeliveryController::class, 'settings'])->name('deliveries.settings');
        Route::put('/settings/couriers/{driver}', [DeliveryController::class, 'link'])->whereIn('driver', ['pargo', 'tcg', 'skynet'])->name('deliveries.link');
        Route::post('/visits/{visit}/delivery', [DeliveryController::class, 'request'])->name('deliveries.request');
        Route::post('/deliveries/{delivery}/{action}', [DeliveryController::class, 'act'])->whereIn('action', ['book', 'status', 'confirm'])->name('deliveries.act');
        Route::get('/prescriptions/{prescription}/pharmacies', [DeliveryController::class, 'compare'])->name('prescriptions.pharmacies');
        Route::get('/settings/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('/settings/branches', [BranchController::class, 'store'])->name('branches.store');
        Route::put('/settings/branches/{branch}/staff', [BranchController::class, 'staff'])->name('branches.staff');
        Route::post('/branches/switch', [BranchController::class, 'switch'])->name('branches.switch');
        Route::post('/branches/transfer', [BranchController::class, 'transfer'])->name('branches.transfer');
        Route::get('/settings/domains', [CustomDomainController::class, 'index'])->name('domains.index');
        Route::post('/settings/domains', [CustomDomainController::class, 'store'])->name('domains.store');
        Route::post('/settings/domains/{domain}/verify', [CustomDomainController::class, 'verify'])->name('domains.verify');
        Route::delete('/settings/domains/{domain}', [CustomDomainController::class, 'destroy'])->name('domains.destroy');
        Route::get('/me/calendar', [CalendarController::class, 'show'])->name('calendar.show');
        Route::get('/me/calendar/{driver}/connect', [CalendarController::class, 'connect'])->whereIn('driver', ['google', 'microsoft'])->name('calendar.connect');
        Route::put('/me/calendar', [CalendarController::class, 'update'])->name('calendar.update');
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
        Route::post('/reports/run', [ReportsController::class, 'run'])->middleware('throttle:60,1')->name('reports.run');
        Route::post('/reports/export/{format}', [ReportsController::class, 'export'])->whereIn('format', ['csv', 'pdf'])->middleware('throttle:20,1')->name('reports.export');
        Route::post('/reports', [ReportsController::class, 'save'])->name('reports.save');
        Route::delete('/reports/{report}', [ReportsController::class, 'destroy'])->name('reports.destroy');
        Route::get('/settings/ai-scribe', [ScribeController::class, 'practice'])->name('ai-scribe.settings');
        Route::post('/consultations/{consultation}/scribe/start', [ScribeSessionController::class, 'start'])->name('scribe.start');
        Route::post('/appointments/{appointment}/scribe/request', [ScribeSessionController::class, 'requestConsent'])->name('scribe.request');
        Route::get('/scribe/{session}', [ScribeSessionController::class, 'show'])->name('scribe.show');
        Route::post('/scribe/{session}/audio', [ScribeSessionController::class, 'audio'])->middleware('throttle:20,1')->name('scribe.audio');
        Route::post('/scribe/{session}/{action}', [ScribeSessionController::class, 'act'])->whereIn('action', ['chat', 'redraft', 'accept', 'discard'])->name('scribe.act');
        Route::post('/settings/ai-scribe', [ScribeController::class, 'toggle'])->name('ai-scribe.toggle');
        Route::get('/settings/api', [ApiKeysController::class, 'index'])->name('api.keys');
        Route::post('/settings/api/keys', [ApiKeysController::class, 'store'])->name('api.keys.store');
        Route::post('/settings/api/keys/{key}/revoke', [ApiKeysController::class, 'revoke'])->name('api.keys.revoke');
        Route::post('/settings/api/webhooks/{action}', [ApiKeysController::class, 'webhook'])->whereIn('action', ['add', 'on', 'off', 'test'])->name('api.webhooks');
        Route::post('/settings/api/lab/{action}', [ApiKeysController::class, 'lab'])->whereIn('action', ['outgoing', 'map', 'unmap'])->name('api.lab');
        Route::get('/corporate-wellness', [WellnessController::class, 'index'])->name('wellness.index');
        Route::post('/corporate-wellness/accounts', [WellnessController::class, 'saveAccount'])->name('wellness.accounts');
        Route::post('/corporate-wellness/events', [WellnessController::class, 'createEvent'])->name('wellness.events');
        Route::get('/corporate-wellness/events/{event}', [WellnessController::class, 'event'])->name('wellness.event');
        Route::post('/corporate-wellness/registrations/{registration}/screen', [WellnessController::class, 'screen'])->name('wellness.screen');
        Route::match(['get', 'post'], '/corporate-wellness/events/{event}/employer/{action}', [WellnessController::class, 'employer'])->whereIn('action', ['invoice', 'send', 'report.pdf', 'invoice.pdf'])->name('wellness.employer.act');
        Route::post('/corporate-wellness/invoices/{invoice}/paid', [WellnessController::class, 'invoicePaid'])->name('wellness.invoice.paid');
        Route::get('/locums', [LocumController::class, 'practice'])->name('locums.practice');
        Route::post('/locums/shifts', [LocumController::class, 'postShift'])->name('locums.shifts.store');
        Route::post('/locums/shifts/{shift}/cancel', [LocumController::class, 'cancel'])->name('locums.shifts.cancel');
        Route::post('/locums/applications/{application}/accept', [LocumController::class, 'accept'])->name('locums.accept');
        Route::match(['get', 'post'], '/locums/shifts/{shift}/{action}', [LocumController::class, 'practiceAction'])->whereIn('action', ['hours', 'paid', 'cancel-booked', 'rebook', 'invoice'])->name('locums.shift.act');
        Route::get('/packages', [PrepaidController::class, 'index'])->name('packages.index');
        Route::post('/packages', [PrepaidController::class, 'save'])->name('packages.save');
        Route::post('/patients/{patient}/packages', [PrepaidController::class, 'sell'])->name('packages.sell');
        Route::post('/invoice-lines/{line}/redeem', [PrepaidController::class, 'redeem'])->name('packages.redeem');
        Route::get('/support', [SupportController::class, 'practice'])->name('support.practice');
        Route::post('/support/{action}', [SupportController::class, 'practiceAction'])->whereIn('action', ['open', 'reply', 'grant', 'revoke'])->name('support.practice.act');
        Route::get('/finance/vat', [FinanceOpsController::class, 'vat'])->name('finance.vat');
        Route::put('/settings/vat', [FinanceOpsController::class, 'saveVat'])->name('settings.vat');
        Route::get('/finance/debtors', [FinanceOpsController::class, 'debtors'])->name('finance.debtors');
        Route::post('/finance/debtors/{action}', [FinanceOpsController::class, 'debtorAction'])->whereIn('action', ['statements', 'write-off', 'approve'])->name('finance.debtors.act');
        Route::get('/finance/exports/{type}', [FinanceOpsController::class, 'export'])->whereIn('type', ['journal', 'invoices', 'payments', 'vat'])->name('finance.export');
        Route::get('/settings/accounting', [FinanceOpsController::class, 'accounting'])->name('settings.accounting');
        Route::get('/settings/accounting/{driver}/connect', [FinanceOpsController::class, 'connect'])->whereIn('driver', ['xero', 'sage', 'zoho'])->name('settings.accounting.connect');
        Route::put('/settings/accounting/{driver}', [FinanceOpsController::class, 'updateConnection'])->whereIn('driver', ['xero', 'sage', 'zoho'])->name('settings.accounting.update');
        Route::post('/settings/accounting/export', [FinanceOpsController::class, 'exportNow'])->name('settings.accounting.export');
        Route::get('/procurement', [ProcurementController::class, 'index'])->name('procurement.index');
        Route::post('/procurement/{action}', [ProcurementController::class, 'act'])->whereIn('action', ['supplier', 'order', 'send', 'receive', 'stock-take', 'write-off-expired'])->name('procurement.act');
        Route::get('/patients/{patient}/care', [CareController::class, 'care'])->name('care.show');
        Route::post('/patients/{patient}/connected', [CareController::class, 'connected'])->name('care.connected');
        Route::post('/patients/{patient}/care/{action}', [CareController::class, 'careAction'])->whereIn('action', ['problem', 'immunisation', 'pregnancy', 'antenatal', 'recall-done', 'registration', 'consent-code', 'consent-confirm'])->middleware('throttle:30,1')->name('care.action');
        Route::post('/problems/{problem}/resolve', [CareController::class, 'resolveProblem'])->name('problems.resolve');
        Route::post('/chronic-registrations/{registration}/{action}', [CareController::class, 'registrationAction'])->whereIn('action', ['submit', 'approve', 'decline'])->name('registrations.act');
        Route::post('/prescriptions/{prescription}/renew', [CareController::class, 'renew'])->name('prescriptions.renew');
        Route::post('/prescriptions/{prescription}/chronic', [CareController::class, 'markChronic'])->name('prescriptions.chronic');
        Route::get('/messages', [CareController::class, 'inbox'])->name('messages.index');
        Route::post('/messages', [CareController::class, 'startThread'])->name('messages.start');
        Route::get('/messages/{thread}', [CareController::class, 'thread'])->name('messages.show');
        Route::post('/messages/{thread}', [CareController::class, 'postMessage'])->middleware('throttle:60,1')->name('messages.post');
        Route::post('/thread-messages/{message}/file', [CareController::class, 'fileMessage'])->name('messages.file');
        Route::get('/referrals', [CareController::class, 'referrals'])->name('referrals.index');
        Route::post('/patients/{patient}/referrals', [CareController::class, 'refer'])->name('referrals.store');
        Route::post('/referrals/{referral}/{action}', [CareController::class, 'referralAction'])->whereIn('action', ['accept', 'decline', 'book', 'seen', 'feedback'])->name('referrals.act');
        Route::get('/referrals/{referral}/letter', [CareController::class, 'letter'])->name('referrals.letter');
        Route::get('/compliance/break-glass', [CareController::class, 'breakGlass'])->name('breakglass.index');
        Route::post('/compliance/break-glass/{action}', [CareController::class, 'breakGlassAction'])->whereIn('action', ['request', 'approve'])->name('breakglass.act');
        Route::get('/compliance/break-glass/patients/{patient}', [CareController::class, 'breakGlassRead'])->name('breakglass.read');
        Route::get('/reference/pharmacies', [EscriptController::class, 'pharmacies'])->name('reference.pharmacies');
        Route::post('/prescriptions/{prescription}/escript', [EscriptController::class, 'send'])->name('escripts.send');
        Route::get('/escripts', [EscriptController::class, 'inbox'])->name('escripts.inbox');
        Route::post('/escripts/{escript}/{action}', [EscriptController::class, 'act'])->whereIn('action', ['accept', 'reject', 'dispense'])->name('escripts.act');
        Route::post('/network/identities/{identity}/request', [NetworkController::class, 'request'])->middleware('throttle:10,1')->name('network.request');
        Route::post('/network/confirm', [NetworkController::class, 'confirm'])->middleware('throttle:20,1')->name('network.confirm');

        Route::get('/settings/wallet', [WalletController::class, 'show'])->name('wallet.show');
        Route::put('/settings/wallet/telemedicine', [TeleConsultController::class, 'addon'])->name('telemedicine.addon');
        Route::get('/telemedicine', [TeleConsultController::class, 'index'])->name('telemedicine.index');
        Route::get('/telemedicine/{appointment}/call', [TeleConsultController::class, 'doctorCall'])->name('telemedicine.call');
        Route::get('/telemedicine/{appointment}/state', [TeleConsultController::class, 'state'])->name('telemedicine.state');
        Route::post('/telemedicine/{appointment}/extend', [TeleConsultController::class, 'extend'])->name('telemedicine.extend');
        Route::get('/telemedicine/slots', [OnlineConsultController::class, 'slots'])->name('telemedicine.slots');
        Route::post('/telemedicine/book', [OnlineConsultController::class, 'staffBook'])->name('telemedicine.book');
        Route::get('/settings/telemedicine', [OnlineConsultController::class, 'settings'])->name('telemedicine.settings');
        Route::put('/settings/telemedicine/availability/{staff}', [OnlineConsultController::class, 'saveAvailability'])->name('telemedicine.availability');
        Route::post('/settings/telemedicine/exceptions/{staff}', [OnlineConsultController::class, 'addException'])->name('telemedicine.exceptions.add');
        Route::delete('/settings/telemedicine/exceptions/{exception}', [OnlineConsultController::class, 'deleteException'])->name('telemedicine.exceptions.delete');
        Route::put('/settings/telemedicine/prices', [OnlineConsultController::class, 'savePrices'])->name('telemedicine.prices');
        Route::put('/settings/telemedicine/rules', [OnlineConsultController::class, 'saveRules'])->name('telemedicine.rules');
        Route::post('/refund-tasks/{task}/complete', [OnlineConsultController::class, 'completeRefund'])->name('refund-tasks.complete');
        Route::get('/chats', [ChatController::class, 'doctorIndex'])->name('chats.index');
        Route::get('/chats/{thread}', [ChatController::class, 'doctorShow'])->name('chats.show');
        Route::post('/chats/{thread}', [ChatController::class, 'doctorPost'])->middleware('throttle:60,1')->name('chats.post');
        Route::post('/settings/wallet/topups', [WalletController::class, 'topup'])->name('wallet.topup');
        Route::put('/settings/wallet/auto-topup', [WalletController::class, 'autoTopup'])->name('wallet.auto');
        Route::get('/cash-up', [FinanceController::class, 'cashUp'])->name('finance.cashup');
        Route::post('/cash-up', [FinanceController::class, 'closeCashUp'])->name('finance.cashup.close');
        Route::post('/doctor/call-next', [DoctorQueueController::class, 'callNext'])->name('doctor.call');
        Route::post('/doctor/accept/{visit}', [DoctorQueueController::class, 'accept'])->name('doctor.accept');

        Route::get('/settings/templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::put('/settings/templates/{type}', [TemplateController::class, 'update'])->name('templates.update');
        Route::get('/settings/templates/{type}/preview', [TemplateController::class, 'preview'])->name('templates.preview');
        Route::put('/settings/branding', [TemplateController::class, 'branding'])->name('branding.update');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    });
});
