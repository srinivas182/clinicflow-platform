<?php

use App\Domains\Identity\Http\Middleware\EnsureWorkspaceMember;
use App\Domains\Platform\Http\Middleware\EnsurePlatformAdmin;
use App\Domains\Platform\Http\Middleware\EnsureProviderWritable;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

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
        __DIR__.'/../app/Domains/Hub/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('workspaces'));
        $middleware->alias([
            'workspace' => EnsureWorkspaceMember::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'provider.writable' => EnsureProviderWritable::class,
        ]);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
