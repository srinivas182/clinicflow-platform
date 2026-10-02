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
use App\Domains\Identity\Http\Controllers\HandoffController;
use App\Domains\Patients\Http\Controllers\PatientController;
use App\Domains\Pharmacy\Http\Controllers\PharmacyController;
use App\Domains\Platform\Http\Controllers\SubscriptionBillingController;
use App\Domains\Prescribing\Http\Controllers\PrescriptionController;
use App\Domains\Scheduling\Http\Controllers\AppointmentController;
use App\Domains\Scheduling\Http\Controllers\RosterController;
use App\Domains\Visits\Http\Controllers\DeviceController;
use App\Domains\Visits\Http\Controllers\FrontDeskController;
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

    Route::get('/kiosk/{token}', [DeviceController::class, 'kiosk'])->name('kiosk');
    Route::post('/kiosk/{token}', [DeviceController::class, 'kioskCheckIn'])->middleware('throttle:20,1')->name('kiosk.checkin');
    Route::get('/display/{token}', [DeviceController::class, 'display'])->name('display');

    Route::middleware(['auth', 'workspace', 'provider.writable'])->group(function (): void {
        Route::get('/', ProviderHomeController::class)->name('provider.home');
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
        Route::post('/doctor/call-next', [DoctorQueueController::class, 'callNext'])->name('doctor.call');
        Route::post('/doctor/accept/{visit}', [DoctorQueueController::class, 'accept'])->name('doctor.accept');

        Route::get('/settings/templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::put('/settings/templates/{type}', [TemplateController::class, 'update'])->name('templates.update');
        Route::get('/settings/templates/{type}/preview', [TemplateController::class, 'preview'])->name('templates.preview');
        Route::put('/settings/branding', [TemplateController::class, 'branding'])->name('branding.update');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    });
});
