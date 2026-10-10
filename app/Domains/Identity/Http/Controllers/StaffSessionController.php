<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Sign Out" in a practice: ends the session on the practice's address, then passes through a signed,
 * two-minute central link that ends the central session too, so the person is signed out everywhere.
 */
class StaffSessionController extends Controller
{
    public function destroy(Request $request): Response
    {
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $central = rtrim((string) config('app.url'), '/');
        if ($user === null) {
            return Inertia::location($central.'/login');
        }

        return Inertia::location($central.URL::temporarySignedRoute('logout.signed', now()->addMinutes(2), ['user' => $user->getAuthIdentifier()], absolute: false));
    }
}
