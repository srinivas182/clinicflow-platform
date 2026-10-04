<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Support\StatusPage;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public status page and JSON feed; super admin incident and maintenance tools.
 */
class StatusController extends Controller
{
    public function show(StatusPage $status): Response
    {
        return Inertia::render('Status/Show', $status->summary());
    }

    public function json(StatusPage $status): JsonResponse
    {
        return response()->json($status->summary())->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'public, max-age=30');
    }

    public function admin(StatusPage $status): Response
    {
        return Inertia::render('Admin/Status', [...$status->summary(), 'componentNames' => StatusPage::COMPONENTS]);
    }

    public function act(Request $request, string $action, StatusPage $status): RedirectResponse
    {
        match ($action) {
            'report' => (function () use ($request, $status): void {
                $d = $request->validate(['title' => ['required', 'string', 'max:160'], 'kind' => ['required', Rule::in(['incident', 'maintenance'])], 'impact' => ['required', Rule::in(['minor', 'major', 'critical'])],
                    'components' => ['array'], 'components.*' => ['string'], 'body' => ['required', 'string', 'max:2000'], 'scheduled_for' => ['nullable', 'date']]);
                $status->report($d['title'], $d['kind'], $d['impact'], array_values($d['components'] ?? []), $d['body'], $d['scheduled_for'] ?? null);
            })(),
            'update' => (function () use ($request, $status): void {
                $d = $request->validate(['incident_id' => ['required', 'integer'], 'status' => ['required', Rule::in(['investigating', 'identified', 'monitoring', 'resolved', 'scheduled', 'in_progress', 'completed'])], 'body' => ['required', 'string', 'max:2000']]);
                $status->update((int) $d['incident_id'], $d['status'], $d['body']);
            })(),
            'component' => (function () use ($request, $status): void {
                $d = $request->validate(['key' => ['required', Rule::in(array_keys(StatusPage::COMPONENTS))], 'status' => ['nullable', Rule::in(['operational', 'degraded', 'outage'])]]);
                $status->setManual($d['key'], $d['status'] ?? null);
            })(),
            default => abort(404),
        };

        return back()->with('success', 'Status page updated.');
    }
}
