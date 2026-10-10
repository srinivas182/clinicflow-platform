<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\CreateWorkspaceHandoff;
use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * "Where are you working today?" — lists the user's usable workspaces.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $workspaces = Membership::query()
            ->with('provider')
            ->where('user_id', $user->id)
            ->usable()
            ->get()
            ->map(fn (Membership $m): array => [
                'providerId' => $m->tenant_id,
                'name' => $m->provider->name,
                'type' => $m->provider->type->label(),
                'role' => $m->role->label(),
                'expiresAt' => $m->expires_at?->toDateString(),
            ])->values();

        return Inertia::render('Auth/Workspaces', [
            'userName' => $user->name,
            'workspaces' => $workspaces,
            'isPlatformAdmin' => (bool) $user->is_platform_admin,
            // Arriving from a practice's "Staff sign in": open that practice straight away.
            'autoOpen' => is_string($request->query('open')) && $workspaces->contains('providerId', $request->query('open')) ? $request->query('open') : null,
        ]);
    }

    public function open(Request $request, string $provider, CreateWorkspaceHandoff $action): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();

        $url = $action->handle($user, Provider::query()->findOrFail($provider));

        return Inertia::location($url);
    }
}
