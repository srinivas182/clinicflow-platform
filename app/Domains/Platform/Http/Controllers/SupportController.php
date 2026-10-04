<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Identity\Actions\ConsumeWorkspaceHandoff;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Membership;
use App\Domains\Identity\Models\WorkspaceHandoff;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\SupportDesk\SupportDesk;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Practice side: tickets and support access grants. Super admin side: replies and
 * opening a practice under an active grant (read-only, logged).
 */
class SupportController extends Controller
{
    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    public function practice(): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = $this->provider();

        return Inertia::render('Support/Practice', [
            'tickets' => $this->tickets($provider->id),
            'grants' => $this->db()->table('support_grants')->where('tenant_id', $provider->id)->latest('id')->limit(10)->get()->map(fn ($g) => [
                'id' => $g->id, 'expires' => (string) $g->expires_at, 'active' => $g->revoked_at === null && now()->lt($g->expires_at), 'ticket' => $g->support_ticket_id,
            ])->values(),
            'maxHours' => SupportDesk::MAX_HOURS,
        ]);
    }

    public function practiceAction(Request $request, string $action, SupportDesk $desk): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = $this->provider();
        $user = $this->user($request);
        $ticket = $request->integer('ticket_id') ?: null;
        if ($ticket !== null) {
            abort_unless($this->db()->table('support_tickets')->where('id', $ticket)->where('tenant_id', $provider->id)->exists(), 404);
        }
        // Only the practice owner may let Clinic Flow support into the workspace; anyone managing settings may end it.
        if ($action === 'grant') {
            abort_unless(Membership::query()->where('tenant_id', $provider->id)->where('user_id', $user->id)->where('role', 'owner')->exists(), 403, 'Only the practice owner can grant support access.');
        }
        match ($action) {
            'open' => $desk->open($provider, $user, $request->string('subject')->toString(), $request->string('body')->toString()),
            'reply' => $desk->reply((int) $ticket, $user, 'practice', $request->string('body')->toString()),
            'grant' => $desk->grant($provider, $user, $request->integer('hours'), $ticket),
            'revoke' => $desk->revoke($provider, $request->integer('grant_id'), $user),
            default => abort(404),
        };

        return back()->with('success', match ($action) {
            'grant' => 'Support may view (not change) your workspace until the time shown. You can end it at any time.', 'revoke' => 'Support access ended.', default => 'Sent.'
        });
    }

    public function admin(): Response
    {
        return Inertia::render('Admin/Support', [
            'tickets' => $this->tickets(null),
            'grants' => $this->db()->table('support_grants')->whereNull('revoked_at')->where('expires_at', '>', now())->get()->map(fn ($g) => [
                'id' => $g->id, 'practice' => Provider::query()->whereKey($g->tenant_id)->value('name'), 'expires' => (string) $g->expires_at, 'ticket' => $g->support_ticket_id,
            ])->values(),
        ]);
    }

    public function adminAction(Request $request, string $action, SupportDesk $desk): HttpResponse
    {
        $user = $this->user($request);

        return match ($action) {
            'reply' => tap(back()->with('success', 'Reply sent.'), fn () => $desk->reply($request->integer('ticket_id'), $user, 'support', $request->string('body')->toString())),
            'close' => tap(back()->with('success', 'Ticket closed.'), fn () => $desk->close($request->integer('ticket_id'))),
            'enter' => redirect()->away($desk->handoff($request->integer('grant_id'), $user)),
            default => abort(404),
        };
    }

    /**
     * Practice domain: sign the support person in under the grant.
     */
    public function enter(Request $request, string $token, ConsumeWorkspaceHandoff $consume): RedirectResponse
    {
        $provider = $this->provider();
        $handoff = WorkspaceHandoff::query()->where('token_hash', hash('sha256', $token))->first();
        $grantId = $handoff?->getAttribute('support_grant_id');
        abort_unless(is_numeric($grantId) && app(SupportDesk::class)->activeGrant((int) $grantId, $provider->id) !== null, 403, 'Support access is not active.');
        $user = $consume->handle($token, $provider->id);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('support_grant_id', (int) $grantId);
        activity('support')->causedBy($user)->withProperties(['grant' => (int) $grantId])->log('Support session started');

        return redirect()->route('provider.home');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tickets(?string $tenantId): array
    {
        return array_values($this->db()->table('support_tickets')->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByRaw("status = 'closed'")->orderByDesc('updated_at')->limit(100)->get()->map(fn ($t) => [
                'id' => $t->id, 'subject' => $t->subject, 'status' => $t->status, 'practice' => Provider::query()->whereKey($t->tenant_id)->value('name'),
                'messages' => $this->db()->table('support_messages')->where('support_ticket_id', $t->id)->orderBy('id')->get(['author', 'body', 'created_at']),
            ])->all());
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
