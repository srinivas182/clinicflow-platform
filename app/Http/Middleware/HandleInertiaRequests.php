<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Shares app-wide props with every Inertia page.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $provider = tenant();
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'version' => config('clinicflow.version'),
            ],
            'auth' => [
                'user' => $user instanceof User ? ['name' => $user->name, 'email' => $user->email] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'provider' => $provider instanceof Provider ? [
                'id' => $provider->id,
                'name' => $provider->name,
                'type' => $provider->type->value,
                'typeLabel' => $provider->type->label(),
            ] : null,
        ];
    }
}
