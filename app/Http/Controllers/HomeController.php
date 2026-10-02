<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Central (platform) home. The marketing site replaces this in Sprint 8.
 */
class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Welcome', [
            'region' => config('clinicflow.region'),
        ]);
    }
}
