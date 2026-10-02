<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/health — liveness and version for load balancers,
 * monitoring and the mobile apps.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'clinicflow-platform',
            'version' => config('clinicflow.version'),
            'region' => config('clinicflow.region'),
            'time' => now()->toIso8601String(),
        ]);
    }
}
