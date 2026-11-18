<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Support\StatusPage;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                    // Known components only (beta #226); maintenance is scheduled in the future (beta #227).
                    'components' => ['array'], 'components.*' => ['string', Rule::in(array_keys(StatusPage::COMPONENTS))], 'body' => ['required', 'string', 'max:2000'],
                    'scheduled_for' => ['nullable', 'date', 'after:now', Rule::requiredIf($request->input('kind') === 'maintenance')]],
                    ['components.*.in' => 'Choose components from the list.', 'scheduled_for.after' => 'Maintenance must be scheduled in the future.', 'scheduled_for.required' => 'Give the maintenance date and time.']);
                $status->report($d['title'], $d['kind'], $d['impact'], array_values($d['components'] ?? []), $d['body'], $d['scheduled_for'] ?? null);
            })(),
            'update' => (function () use ($request, $status): void {
                // Unknown incident: not found (beta #229). Statuses depend on the kind (beta #230).
                $incident = DB::connection((string) config('tenancy.database.central_connection'))->table('status_incidents')->where('id', $request->integer('incident_id'))->first();
                abort_if($incident === null, 404);
                $allowed = $incident->kind === 'maintenance' ? ['scheduled', 'in_progress', 'completed'] : ['investigating', 'identified', 'monitoring', 'resolved'];
                $d = $request->validate(['status' => ['required', Rule::in($allowed)], 'body' => ['required', 'string', 'max:2000']],
                    ['status.in' => 'For '.($incident->kind === 'maintenance' ? 'maintenance' : 'an incident').' choose: '.implode(', ', $allowed).'.']);
                $status->update((int) $incident->id, $d['status'], $d['body']);
            })(),
            'component' => (function () use ($request, $status): void {
                $d = $request->validate(['key' => ['required', Rule::in(array_keys(StatusPage::COMPONENTS))], 'status' => ['nullable', Rule::in(['operational', 'degraded', 'outage'])],
                    'hours' => ['nullable', 'integer', 'min:1', 'max:72']]);
                $status->setManual($d['key'], $d['status'] ?? null, (int) ($d['hours'] ?? 4));
            })(),
            default => abort(404),
        };

        return back()->with('success', 'Status page updated.');
    }

    /** One-click opt-out from incident emails (link in each email; signed so only the recipient can use it). */
    public function emailsOff(int $user): \Symfony\Component\HttpFoundation\Response
    {
        User::query()->whereKey($user)->update(['status_emails' => false]);

        return response()->view('payments.done', ['title' => 'Unsubscribed', 'message' => 'You will no longer receive service incident emails. The status page always shows the current state.']);
    }
}
