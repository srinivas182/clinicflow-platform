<?php

use App\Domains\Api\Http\Middleware\AuthenticateApiKey;
use App\Domains\Identity\Http\Middleware\EnsureWorkspaceMember;
use App\Domains\Platform\Http\Middleware\EnsurePlatformAdmin;
use App\Domains\Platform\Http\Middleware\EnsureProviderWritable;
use App\Http\Middleware\CaptureResellerRef;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireAuthenticator;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Http\Middleware\ScanUploads;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackRecordAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Domains/Platform/Console',
        __DIR__.'/../app/Domains/Pharmacy/Console',
        __DIR__.'/../app/Domains/Wallet/Console',
        __DIR__.'/../app/Domains/Telemedicine/Console',
        __DIR__.'/../app/Domains/Lab/Console',
        __DIR__.'/../app/Domains/Clinical/Console',
        __DIR__.'/../app/Domains/Finance/Console',
        __DIR__.'/../app/Domains/Scheduling/Console',
        __DIR__.'/../app/Domains/Website/Console',
        __DIR__.'/../app/Domains/Billing/Console',
        __DIR__.'/../app/Domains/Locums/Console',
        __DIR__.'/../app/Domains/Api/Console',
        __DIR__.'/../app/Domains/Scribe/Console',
        __DIR__.'/../app/Domains/Reports/Console',
        __DIR__.'/../app/Domains/Platform/Support/Console',
        __DIR__.'/../app/Domains/Hub/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('workspaces'));
        $middleware->alias([
            'workspace' => EnsureWorkspaceMember::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'provider.writable' => EnsureProviderWritable::class,
            'api.key' => AuthenticateApiKey::class,
            'step-up' => RequireRecentConfirmation::class,
            'record-access' => TrackRecordAccess::class,
        ]);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            CaptureResellerRef::class,
            RequireAuthenticator::class,
            // "Sign out other devices" takes effect on their next request.
            AuthenticateSession::class,
            SecurityHeaders::class,
            ScanUploads::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
