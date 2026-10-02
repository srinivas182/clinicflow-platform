<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Platform\Models\Provider;
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

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'version' => config('clinicflow.version'),
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
