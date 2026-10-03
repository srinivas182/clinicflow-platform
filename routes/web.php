<?php

use App\Domains\Identity\Http\Controllers\LoginController;
use App\Domains\Identity\Http\Controllers\WorkspaceController;
use App\Domains\Messaging\Http\Controllers\MessagingAdminController;
use App\Domains\Platform\Http\Controllers\Admin\AutoDebitAdminController;
use App\Domains\Platform\Http\Controllers\Admin\CmsAdminController;
use App\Domains\Platform\Http\Controllers\Admin\PackageAdminController;
use App\Domains\Platform\Http\Controllers\Admin\PaymentAdminController;
use App\Domains\Platform\Http\Controllers\Admin\ProviderAdminController;
use App\Domains\Platform\Http\Controllers\PricingController;
use App\Domains\Platform\Http\Controllers\PublicSiteController;
use App\Domains\Platform\Http\Controllers\SignupController;
use App\Domains\Platform\Http\Controllers\SubscriptionBillingController;
use App\Domains\Telemedicine\Http\Controllers\TelemedicineAdminController;
use App\Domains\Wallet\Http\Controllers\WalletController;
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

        Route::middleware('auth')->group(function (): void {
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
