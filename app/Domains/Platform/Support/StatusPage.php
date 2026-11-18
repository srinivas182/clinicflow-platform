<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Identity\Models\Membership;
use App\Domains\Messaging\Models\MessagingProvider;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Telemedicine\Models\VideoConfig;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The public status page: component health (checked automatically unless the
 * super admin has set it by hand), incidents with updates, and planned maintenance.
 */
class StatusPage
{
    public const COMPONENTS = ['app' => 'Web app and portal', 'messaging' => 'SMS, email and WhatsApp', 'video' => 'Video and audio consults', 'payments' => 'Online payments', 'network' => 'Network Hub (e-scripts, labs, referrals)'];

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    public function ensureComponents(): void
    {
        foreach (self::COMPONENTS as $key => $name) {
            $this->db()->table('status_components')->insertOrIgnore(['key' => $key, 'name' => $name, 'status' => 'operational']);
        }
    }

    /**
     * Automatic checks; components set by hand are left alone.
     *
     * @return array<string, string>
     */
    public function check(): array
    {
        $this->ensureComponents();
        // A hand-set status that has run out goes back to automatic (beta #232).
        $this->db()->table('status_components')->where('manual', true)->whereNotNull('manual_until')->where('manual_until', '<', now())
            ->update(['manual' => false, 'manual_until' => null]);
        $results = [
            'app' => $this->probe(fn () => $this->db()->select('select 1')),
            'network' => $this->probe(fn () => DB::connection('hub')->select('select 1')),
            'messaging' => MessagingProvider::query()->where('enabled', true)->exists() ? 'operational' : 'degraded',
            'video' => VideoConfig::query()->where('enabled', true)->exists() ? 'operational' : 'degraded',
            'payments' => $this->paymentsStatus(),
        ];
        foreach ($results as $key => $status) {
            $this->db()->table('status_components')->where('key', $key)->where('manual', false)->update(['status' => $status, 'checked_at' => now()]);
        }

        return $results;
    }

    /**
     * Sets a component's status by hand for $hours (default 4), or back to automatic (null), which
     * re-checks at once (beta #232).
     */
    public function setManual(string $key, ?string $status, int $hours = 4): void
    {
        $this->db()->table('status_components')->where('key', $key)->update($status === null
            ? ['manual' => false, 'manual_until' => null]
            : ['manual' => true, 'status' => $status, 'manual_until' => now()->addHours(max(1, min(72, $hours)))]);
        if ($status === null) {
            $this->check();
        }
    }

    /**
     * Online payments: the platform's default gateway is switched on and answers a connection test
     * (beta #233). Cached for 5 minutes so the status page never hammers the gateway.
     */
    private function paymentsStatus(): string
    {
        return Cache::remember('status:payments', 300, function (): string {
            $config = GatewayFactory::platformDefault();
            if ($config === null) {
                return 'degraded';
            }
            try {
                return GatewayFactory::fromConfig($config)->testConnection()->ok ? 'operational' : 'outage';
            } catch (Throwable) {
                return 'outage';
            }
        });
    }

    /**
     * @param  list<string>  $components
     */
    public function report(string $title, string $kind, string $impact, array $components, string $body, ?string $scheduledFor = null): int
    {
        $id = (int) $this->db()->table('status_incidents')->insertGetId(['title' => $title, 'kind' => $kind, 'status' => $kind === 'maintenance' ? 'scheduled' : 'investigating',
            'impact' => $impact, 'components' => json_encode(array_values(array_intersect($components, array_keys(self::COMPONENTS)))), 'scheduled_for' => $scheduledFor, 'created_at' => now(), 'updated_at' => now()]);
        $this->update($id, $kind === 'maintenance' ? 'scheduled' : 'investigating', $body);

        return $id;
    }

    /**
     * Major and critical incidents: practice owners are emailed when one starts and when it is
     * resolved (beta #236). Each email has a one-click opt-out.
     */
    private function tellOwners(object $incident, string $status, string $body): void
    {
        $incident = (array) $incident;
        if (($incident['kind'] ?? null) !== 'incident' || ! in_array($incident['impact'] ?? null, ['major', 'critical'], true)) {
            return;
        }
        $resolved = $status === 'resolved';
        $owners = User::query()->where('status_emails', true)->whereIn('id', Membership::query()->where('role', 'owner')
            ->whereIn('tenant_id', Provider::query()->whereIn('status', [ProviderStatus::Trial->value, ProviderStatus::Active->value, ProviderStatus::ReadOnly->value])->pluck('id'))
            ->pluck('user_id'))->get();
        $page = rtrim((string) config('app.url'), '/').'/status';
        foreach ($owners as $owner) {
            $owner->notify(new PracticeNotice(
                ($resolved ? 'Resolved: ' : 'Service problem: ').(string) ($incident['title'] ?? ''),
                array_values(array_filter([$resolved ? 'This problem has been fixed.' : 'We are working on a problem that may affect your practice.', $body,
                    'Stop these emails: '.URL::signedRoute('status-emails.off', ['user' => $owner->id])])),
                'Status page', $page, $resolved ? 'incident_resolved' : 'incident_started',
            ));
        }
    }

    public function update(int $incidentId, string $status, string $body): void
    {
        $done = in_array($status, ['resolved', 'completed'], true);
        $this->db()->table('status_updates')->insert(['status_incident_id' => $incidentId, 'status' => $status, 'body' => $body, 'created_at' => now()]);
        $before = $this->db()->table('status_incidents')->where('id', $incidentId)->first();
        $this->db()->table('status_incidents')->where('id', $incidentId)->update(['status' => $status, 'resolved_at' => $done ? now() : null, 'updated_at' => now()]);
        $firstUpdate = $this->db()->table('status_updates')->where('status_incident_id', $incidentId)->count() === 1;
        if ($before !== null && ($firstUpdate || ($status === 'resolved' && $before->status !== 'resolved'))) {
            $this->tellOwners($before, $status, $body);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $this->ensureComponents();
        $components = $this->db()->table('status_components')->orderBy('id')->get(['key', 'name', 'status', 'checked_at', 'manual', 'manual_until']);
        $worst = $components->contains('status', 'outage') ? 'outage' : ($components->contains('status', 'degraded') ? 'degraded' : 'operational');
        // Open major or critical incidents set the top banner too (beta #234).
        $open = $this->db()->table('status_incidents')->where('kind', 'incident')->whereNull('resolved_at')->pluck('impact');
        if ($open->contains('critical')) {
            $worst = 'outage';
        } elseif ($open->contains('major') && $worst === 'operational') {
            $worst = 'degraded';
        }
        $incidents = $this->db()->table('status_incidents')->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>', now()->subDays(14)))
            ->orderByDesc('created_at')->get()->map(fn ($i) => [
                'id' => $i->id, 'title' => $i->title, 'kind' => $i->kind, 'status' => $i->status, 'impact' => $i->impact,
                'components' => json_decode((string) $i->components, true), 'scheduledFor' => $i->scheduled_for, 'resolvedAt' => $i->resolved_at,
                'updates' => $this->db()->table('status_updates')->where('status_incident_id', $i->id)->orderByDesc('id')->get(['status', 'body', 'created_at']),
            ])->values();

        return ['overall' => $worst, 'components' => $components, 'incidents' => $incidents, 'updatedAt' => now()->toIso8601String()];
    }

    private function probe(callable $fn): string
    {
        try {
            $fn();

            return 'operational';
        } catch (Throwable) {
            return 'outage';
        }
    }
}
