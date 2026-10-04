<?php

use App\Domains\Finance\Http\Controllers\AccountingAdminController;
use App\Domains\Finance\Http\Controllers\AccountingCallbackController;
use App\Domains\Identity\Http\Controllers\LoginController;
use App\Domains\Identity\Http\Controllers\WorkspaceController;
use App\Domains\Locums\Http\Controllers\LocumController;
use App\Domains\Messaging\Http\Controllers\MessagingAdminController;
use App\Domains\Messaging\Http\Controllers\WhatsAppController;
use App\Domains\Pharmacy\Http\Controllers\DeliveryController;
use App\Domains\Platform\Http\Controllers\Admin\AutoDebitAdminController;
use App\Domains\Platform\Http\Controllers\Admin\CmsAdminController;
use App\Domains\Platform\Http\Controllers\Admin\PackageAdminController;
use App\Domains\Platform\Http\Controllers\Admin\PaymentAdminController;
use App\Domains\Platform\Http\Controllers\Admin\ProviderAdminController;
use App\Domains\Platform\Http\Controllers\CustomDomainController;
use App\Domains\Platform\Http\Controllers\GroupController;
use App\Domains\Platform\Http\Controllers\PricingController;
use App\Domains\Platform\Http\Controllers\PublicSiteController;
use App\Domains\Platform\Http\Controllers\ResellerController;
use App\Domains\Platform\Http\Controllers\SignupController;
use App\Domains\Platform\Http\Controllers\StatusController;
use App\Domains\Platform\Http\Controllers\SubscriptionBillingController;
use App\Domains\Platform\Http\Controllers\SupportController;
use App\Domains\Scheduling\Http\Controllers\CalendarAdminController;
use App\Domains\Scheduling\Http\Controllers\CalendarController;
use App\Domains\Telemedicine\Http\Controllers\TelemedicineAdminController;
use App\Domains\Wallet\Http\Controllers\WalletController;
use App\Domains\Website\Http\Controllers\FlaggedReviewsController;
use Illuminate\Support\Facades\Route;

/*
 * Central routes: only answer on the platform's own domains
 * (clinicflow.co.za, app., accounts., localhost). Provider addresses
 * are handled in routes/tenant.php. Route names are registered on the
 * first central domain; the others get a numbered prefix.
 */

/** @var list<string> $centralDomains */
$centralDomains = config('tenancy.central_domains', []);

foreach ($centralDomains as $index => $domain) {
    Route::domain($domain)->name($index === 0 ? '' : "central{$index}.")->group(function (): void {
        Route::get('/', [PublicSiteController::class, 'home'])->name('home');
        Route::get('/find-care', [PublicSiteController::class, 'directory'])->name('directory');
        Route::get('/pages/{slug}', [PublicSiteController::class, 'page'])->where('slug', '[a-z0-9-]+')->name('cms.page');
        Route::get('/pricing', PricingController::class)->name('pricing');
        Route::get('/billing/pay/{token}', [SubscriptionBillingController::class, 'pay'])->middleware('throttle:30,1')->name('billing.pay');
        Route::get('/billing/done', [SubscriptionBillingController::class, 'done'])->name('billing.done');
        Route::get('/billing/topup/{token}', [WalletController::class, 'pay'])->middleware('throttle:30,1')->name('wallet.pay');

        Route::get('/start', [SignupController::class, 'create'])->name('signup');
        Route::post('/start', [SignupController::class, 'store'])->middleware('throttle:10,1')->name('signup.store');
        Route::get('/start/suggest', [SignupController::class, 'suggest'])->name('signup.suggest');
        Route::get('/start/done/{provider}', [SignupController::class, 'done'])->name('signup.done');

        Route::middleware('guest')->group(function (): void {
            Route::get('/login', [LoginController::class, 'create'])->name('login');
            Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
            Route::get('/login/verify', [LoginController::class, 'verifyForm'])->name('login.verify');
            Route::post('/login/verify', [LoginController::class, 'verify'])->middleware('throttle:login-code')->name('login.verify.store');
        });

        Route::get('/calendar/callback/{driver}', [CalendarController::class, 'callback'])->whereIn('driver', ['google', 'microsoft'])->middleware('throttle:20,1')->name('calendar.callback');
        Route::get('/internal/tls/allowed', [CustomDomainController::class, 'tlsAllowed'])->middleware('throttle:120,1')->name('tls.allowed');
        Route::get('/status', [StatusController::class, 'show'])->name('status.public');
        Route::get('/status.json', [StatusController::class, 'json'])->name('status.json');
        Route::get('/accounting/callback/{driver}', AccountingCallbackController::class)->whereIn('driver', ['xero', 'sage', 'zoho'])->middleware('throttle:20,1')->name('accounting.callback');
        Route::middleware('auth')->group(function (): void {
            Route::get('/groups', [GroupController::class, 'mine'])->name('groups.mine');
            Route::get('/reseller', [ResellerController::class, 'portal'])->name('reseller.portal');
            Route::get('/locum', [LocumController::class, 'portal'])->name('locum.portal');
            Route::post('/locum/profile', [LocumController::class, 'saveProfile'])->name('locum.profile');
            Route::post('/locum/documents', [LocumController::class, 'uploadDocument'])->middleware('throttle:20,1')->name('locum.documents');
            Route::post('/locum/shifts/{shift}/apply', [LocumController::class, 'apply'])->middleware('throttle:30,1')->name('locum.apply');
            Route::match(['get', 'post'], '/locum/shifts/{shift}/{action}', [LocumController::class, 'locumAction'])->whereIn('action', ['hours', 'cancel', 'invoice'])->name('locum.shift.act');
            Route::get('/groups/{group}', [GroupController::class, 'show'])->name('groups.show');
            Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces');
            Route::post('/workspaces/{provider}/open', [WorkspaceController::class, 'open'])->name('workspaces.open');
            Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
        });

        Route::middleware(['auth', 'platform.admin'])->prefix('admin')->name('admin.')->group(function (): void {
            Route::get('/providers', [ProviderAdminController::class, 'index'])->name('providers.index');
            Route::get('/providers/{provider}', [ProviderAdminController::class, 'show'])->name('providers.show');
            Route::post('/providers/{provider}/approve', [ProviderAdminController::class, 'approve'])->name('providers.approve');
            Route::post('/verification-checks/{check}', [ProviderAdminController::class, 'review'])->name('checks.review');
            Route::get('/packages', [PackageAdminController::class, 'index'])->name('packages.index');
            Route::get('/auto-debits', [AutoDebitAdminController::class, 'index'])->name('autodebits.index');
            Route::get('/wallet', [WalletController::class, 'adminSettings'])->name('wallet.settings');
            Route::get('/telemedicine', [TelemedicineAdminController::class, 'index'])->name('telemedicine.index');
            Route::get('/accounting', [AccountingAdminController::class, 'index'])->name('accounting.index');
            Route::get('/locums', [LocumController::class, 'admin'])->name('locums.index');
            Route::post('/locums/{profile}/review', [LocumController::class, 'review'])->name('locums.review');
            Route::get('/locums/documents/{document}', [LocumController::class, 'document'])->name('locums.document');
            Route::get('/support', [SupportController::class, 'admin'])->name('support.index');
            Route::post('/support/{action}', [SupportController::class, 'adminAction'])->whereIn('action', ['reply', 'close', 'enter'])->name('support.act');
            Route::get('/status', [StatusController::class, 'admin'])->name('status.admin');
            Route::post('/status/{action}', [StatusController::class, 'act'])->whereIn('action', ['report', 'update', 'component'])->name('status.act');
            Route::get('/resellers', [ResellerController::class, 'admin'])->name('resellers.index');
            Route::post('/resellers', [ResellerController::class, 'store'])->name('resellers.store');
            Route::post('/resellers/{reseller}/pay', [ResellerController::class, 'pay'])->name('resellers.pay');
            Route::get('/reviews', [FlaggedReviewsController::class, 'index'])->name('reviews.index');
            Route::post('/reviews/{flag}', [FlaggedReviewsController::class, 'decide'])->name('reviews.decide');
            Route::get('/groups', [GroupController::class, 'admin'])->name('groups.index');
            Route::get('/whatsapp', [WhatsAppController::class, 'admin'])->name('whatsapp.index');
            Route::put('/whatsapp/providers/{driver}', [WhatsAppController::class, 'saveProvider'])->whereIn('driver', ['meta', 'twilio', 'clickatell'])->name('whatsapp.provider');
            Route::post('/whatsapp/templates', [WhatsAppController::class, 'saveTemplate'])->name('whatsapp.template');
            Route::post('/whatsapp/sync', [WhatsAppController::class, 'sync'])->name('whatsapp.sync');
            Route::put('/whatsapp/prices', [WhatsAppController::class, 'savePrices'])->name('whatsapp.prices');
            Route::get('/couriers', [DeliveryController::class, 'adminPartners'])->name('couriers.index');
            Route::put('/couriers/{driver}', [DeliveryController::class, 'savePartner'])->whereIn('driver', ['pargo', 'tcg', 'skynet'])->name('couriers.save');
            Route::post('/groups/{action}', [GroupController::class, 'adminAction'])->whereIn('action', ['create', 'member', 'admin', 'billing', 'invoice', 'settle'])->name('groups.act');
            Route::get('/calendars', [CalendarAdminController::class, 'index'])->name('calendars.index');
            Route::put('/calendars/{driver}', [CalendarAdminController::class, 'save'])->whereIn('driver', ['google', 'microsoft'])->name('calendars.save');
            Route::put('/accounting/{driver}', [AccountingAdminController::class, 'save'])->whereIn('driver', ['xero', 'sage', 'zoho'])->name('accounting.save');
            Route::get('/accounting/{driver}/connect', [AccountingAdminController::class, 'connect'])->whereIn('driver', ['xero', 'sage', 'zoho'])->name('accounting.connect');
            Route::put('/accounting/{driver}/platform', [AccountingAdminController::class, 'updatePlatform'])->whereIn('driver', ['xero', 'sage', 'zoho'])->name('accounting.platform');
            Route::post('/accounting/export', [AccountingAdminController::class, 'exportPlatform'])->name('accounting.export');
            Route::put('/telemedicine/{driver}', [TelemedicineAdminController::class, 'save'])->whereIn('driver', ['cloud', 'self_hosted'])->name('telemedicine.save');
            Route::post('/telemedicine/{driver}/test', [TelemedicineAdminController::class, 'test'])->whereIn('driver', ['cloud', 'self_hosted'])->name('telemedicine.test');
            Route::put('/wallet', [WalletController::class, 'saveAdminSettings'])->name('wallet.settings.save');
            Route::get('/messaging', [MessagingAdminController::class, 'index'])->name('messaging.index');
            Route::put('/messaging/providers/{driver}', [MessagingAdminController::class, 'saveProvider'])->whereIn('driver', ['clickatell', 'bulksms', 'smsportal', 'twilio', 'ses', 'smtp', 'sendgrid', 'brevo'])->name('messaging.providers.save');
            Route::post('/messaging/providers/{driver}/test', [MessagingAdminController::class, 'testProvider'])->whereIn('driver', ['clickatell', 'bulksms', 'smsportal', 'twilio', 'ses', 'smtp', 'sendgrid', 'brevo'])->name('messaging.providers.test');
            Route::put('/messaging/templates', [MessagingAdminController::class, 'saveTemplate'])->name('messaging.templates.save');
            Route::put('/messaging/packages/{package}', [MessagingAdminController::class, 'savePackage'])->name('messaging.packages.save');
            Route::get('/pages', [CmsAdminController::class, 'index'])->name('pages.index');
            Route::post('/pages', [CmsAdminController::class, 'save'])->name('pages.save');
            Route::put('/packages/{package}', [PackageAdminController::class, 'update'])->name('packages.update');
            Route::get('/payments', [PaymentAdminController::class, 'index'])->name('payments.index');
            Route::put('/payments/{gateway}', [PaymentAdminController::class, 'update'])->name('payments.update');
            Route::post('/payments/{gateway}/test', [PaymentAdminController::class, 'test'])->name('payments.test');
        });
    });
}
