<?php

declare(strict_types=1);

use App\Domains\Billing\Http\Controllers\BillingSettingsController;
use App\Domains\Billing\Http\Controllers\GatewayWebhookController;
use App\Domains\Billing\Http\Controllers\InvoiceController;
use App\Domains\Billing\Http\Controllers\PayLinkController;
use App\Domains\Billing\Http\Controllers\PaymentSettingsController;
use App\Domains\Claims\Http\Controllers\ClaimController;
use App\Domains\Clinical\Http\Controllers\ConsultController;
use App\Domains\Clinical\Http\Controllers\DoctorQueueController;
use App\Domains\Clinical\Http\Controllers\QuoteController;
use App\Domains\Clinical\Http\Controllers\TriageController;
use App\Domains\Documents\Http\Controllers\TemplateController;
use App\Domains\Finance\Http\Controllers\FinanceController;
use App\Domains\Hub\Http\Controllers\EscriptController;
use App\Domains\Hub\Http\Controllers\NetworkController;
use App\Domains\Identity\Http\Controllers\HandoffController;
use App\Domains\Lab\Http\Controllers\LabCatalogController;
use App\Domains\Lab\Http\Controllers\LabController;
use App\Domains\Messaging\Http\Controllers\MessagingSettingsController;
use App\Domains\Patients\Http\Controllers\PatientAdminController;
use App\Domains\Patients\Http\Controllers\PatientController;
use App\Domains\Pharmacy\Http\Controllers\PharmacyController;
use App\Domains\Platform\Http\Controllers\ProviderSiteController;
use App\Domains\Platform\Http\Controllers\SubscriptionBillingController;
use App\Domains\Platform\Http\Controllers\WebsiteSettingsController;
use App\Domains\Portal\Http\Controllers\PortalController;
use App\Domains\Portal\Http\Controllers\PortalResultsController;
use App\Domains\Portal\Http\Middleware\EnsurePortalPatient;
use App\Domains\Prescribing\Http\Controllers\PrescriptionController;
use App\Domains\Scheduling\Http\Controllers\AppointmentController;
use App\Domains\Scheduling\Http\Controllers\RosterController;
use App\Domains\Telemedicine\Http\Controllers\ChatController;
use App\Domains\Telemedicine\Http\Controllers\OnlineConsultController;
use App\Domains\Telemedicine\Http\Controllers\TeleConsultController;
use App\Domains\Visits\Http\Controllers\DeviceController;
use App\Domains\Visits\Http\Controllers\FrontDeskController;
use App\Domains\Wallet\Http\Controllers\WalletController;
use App\Http\Controllers\Provider\ProviderHomeController;
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

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function (): void {
    Route::get('/auth/handoff/{token}', HandoffController::class)->name('provider.handoff');

    Route::get('/pay/{token}', [PayLinkController::class, 'show'])->middleware('throttle:30,1')->name('paylink.show');
    Route::get('/pay/{token}/done', [PayLinkController::class, 'done'])->name('paylink.done');

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
        Route::get('/results', [LabController::class, 'inbox'])->name('lab.inbox');
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
