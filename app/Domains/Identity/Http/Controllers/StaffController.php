<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\StaffManagement;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Practice → Staff (owners and practice admins).
 */
class StaffController extends Controller
{
    public function index(Request $request, StaffManagement $staff): Response
    {
        [$provider, $actor] = $this->context($request, $staff);
        $members = Membership::query()->where('tenant_id', $provider->id)->with('user')->get();
        $branches = DB::table('branch_staff')->get()->groupBy('staff_id')->map(fn ($rows) => $rows->pluck('branch_id')->map(fn ($v) => (int) $v)->values());
        $lastSignIn = DB::connection((string) config('tenancy.database.central_connection'))->table('login_events')
            ->whereIn('user_id', $members->pluck('user_id'))->groupBy('user_id')->selectRaw('user_id, MAX(created_at) as at')->pluck('at', 'user_id');

        return Inertia::render('Staff/Index', [
            'members' => $members->sortBy(fn (Membership $m) => $m->user->name)->values()->map(fn (Membership $m) => [
                'id' => $m->user_id, 'name' => $m->user->name, 'email' => $m->user->email, 'phone' => $m->user->phone, 'role' => $m->role->value, 'roleLabel' => $m->role->label(),
                'active' => $m->status->value === 'active', 'expires' => $m->expires_at?->toDateString(), 'authenticator' => $m->user->totp_confirmed_at !== null,
                'branches' => $branches->get($m->user_id, collect())->all(), 'lastSignIn' => isset($lastSignIn[$m->user_id]) ? substr((string) $lastSignIn[$m->user_id], 0, 16) : null,
                'self' => $m->user_id === $actor->id, 'owner' => $m->role === StaffRole::Owner,
            ]),
            'invitations' => DB::connection((string) config('tenancy.database.central_connection'))->table('staff_invitations')->where('tenant_id', $provider->id)
                ->whereNull('accepted_at')->whereNull('revoked_at')->orderByDesc('created_at')->get()
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'contact' => $i->email ?? $i->phone, 'role' => StaffRole::from((string) $i->role)->label(),
                    'expired' => now()->greaterThan($i->expires_at), 'expires' => substr((string) $i->expires_at, 0, 10)])->values(),
            'roles' => collect(StaffRole::forProviderType($provider->type))->reject(fn (StaffRole $r) => $r === StaffRole::Owner)
                ->map(fn (StaffRole $r) => ['value' => $r->value, 'label' => $r->label()])->values(),
            'branches' => DB::table('branches')->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function invite(Request $request, StaffManagement $staff): RedirectResponse
    {
        [$provider, $actor] = $this->context($request, $staff);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::enum(StaffRole::class)], 'branches' => ['array'], 'branches.*' => ['integer']]);
        $staff->invite($provider, $actor, $data['name'], $data['email'] ?? null, $data['phone'] ?? null, StaffRole::from($data['role']), $data['branches'] ?? []);

        return back()->with('success', 'Invitation sent. The link works for '.StaffManagement::INVITE_DAYS.' days.');
    }

    public function update(Request $request, int $user, StaffManagement $staff): RedirectResponse
    {
        [$provider, $actor] = $this->context($request, $staff);
        $data = $request->validate(['role' => ['required', Rule::enum(StaffRole::class)], 'branches' => ['array'], 'branches.*' => ['integer']]);
        $staff->update($provider, $actor, $user, StaffRole::from($data['role']), $data['branches'] ?? []);

        return back()->with('success', 'Saved.');
    }

    public function status(Request $request, int $user, string $action, StaffManagement $staff): RedirectResponse
    {
        [$provider, $actor] = $this->context($request, $staff);
        $staff->setActive($provider, $actor, $user, $action === 'reactivate');

        return back()->with('success', $action === 'reactivate' ? 'Access restored.' : 'Access suspended. They can no longer open this practice.');
    }

    public function invitation(Request $request, int $invitation, string $action, StaffManagement $staff): RedirectResponse
    {
        [$provider, $actor] = $this->context($request, $staff);
        $action === 'resend' ? $staff->resend($provider, $actor, $invitation) : $staff->revoke($provider, $actor, $invitation);

        return back()->with('success', $action === 'resend' ? 'Invitation sent again.' : 'Invitation withdrawn.');
    }

    /**
     * @return array{0: Provider, 1: User}
     */
    private function context(Request $request, StaffManagement $staff): array
    {
        $provider = tenant();
        $actor = $request->user();
        abort_unless($provider instanceof Provider && $actor instanceof User && $staff->canManage($provider, $actor), 403, 'Only the practice owner or a practice admin can manage staff.');

        return [$provider, $actor];
    }
}
