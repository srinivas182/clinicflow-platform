<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Scheduling\Actions\CreateRosterSession;
use App\Domains\Scheduling\Enums\SessionType;
use App\Domains\Scheduling\Models\Room;
use App\Domains\Scheduling\Models\RosterSession;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rosters and rooms for the week.
 */
class RosterController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permission::ROSTERS_MANAGE);

        $weekStart = CarbonImmutable::parse($request->string('week')->toString() ?: 'now')->startOfWeek();

        return Inertia::render('Rosters/Index', [
            'weekStart' => $weekStart->toDateString(),
            'sessions' => RosterSession::query()->with(['staff', 'room'])
                ->whereBetween('starts_at', [$weekStart, $weekStart->endOfWeek()])
                ->orderBy('starts_at')->get()
                ->map(fn (RosterSession $s): array => [
                    'id' => $s->id,
                    'staff' => $s->staff->name,
                    'room' => $s->room?->name,
                    'type' => $s->session_type->value,
                    'startsAt' => $s->starts_at->toIso8601String(),
                    'endsAt' => $s->ends_at->toIso8601String(),
                    'slotMinutes' => $s->slot_minutes,
                ])->values(),
            'staff' => Staff::query()->orderBy('name')->get(['id', 'name', 'role']),
            'rooms' => Room::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, CreateRosterSession $action): RedirectResponse
    {
        $this->authorize(Permission::ROSTERS_MANAGE);

        $data = $request->validate([
            'staff_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'slot_minutes' => ['required', 'integer'],
            'session_type' => ['required', Rule::enum(SessionType::class)],
        ]);

        $action->handle(
            Staff::query()->findOrFail($data['staff_id']),
            CarbonImmutable::parse($data['starts_at']),
            CarbonImmutable::parse($data['ends_at']),
            isset($data['room_id']) ? Room::query()->findOrFail($data['room_id']) : null,
            SessionType::from($data['session_type']),
            (int) $data['slot_minutes'],
        );

        return back()->with('success', 'Session added to the roster.');
    }

    public function storeRoom(Request $request): RedirectResponse
    {
        $this->authorize(Permission::ROSTERS_MANAGE);

        $data = $request->validate(['name' => ['required', 'string', 'max:60', 'unique:rooms,name']]);
        Room::create(['name' => $data['name']]);

        return back()->with('success', "{$data['name']} added.");
    }
}
