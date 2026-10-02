<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\ConsumeWorkspaceHandoff;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Provider domain: completes the sign-in started on the central domain.
 */
class HandoffController extends Controller
{
    public function __invoke(Request $request, string $token, ConsumeWorkspaceHandoff $action): RedirectResponse
    {
        $provider = tenant();
        abort_if($provider === null, 404);

        $user = $action->handle($token, (string) $provider->getTenantKey());

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('provider.home');
    }
}
