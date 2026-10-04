<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

use App\Domains\Messaging\Models\MessagingProvider;
use App\Domains\Telemedicine\Models\VideoConfig;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
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
        $results = [
            'app' => $this->probe(fn () => $this->db()->select('select 1')),
            'network' => $this->probe(fn () => DB::connection('hub')->select('select 1')),
            'messaging' => MessagingProvider::query()->where('enabled', true)->exists() ? 'operational' : 'degraded',
            'video' => VideoConfig::query()->where('enabled', true)->exists() ? 'operational' : 'degraded',
            'payments' => 'operational',
        ];
        foreach ($results as $key => $status) {
            $this->db()->table('status_components')->where('key', $key)->where('manual', false)->update(['status' => $status, 'checked_at' => now()]);
        }

        return $results;
    }

    public function setManual(string $key, ?string $status): void
    {
        $this->db()->table('status_components')->where('key', $key)->update($status === null ? ['manual' => false] : ['manual' => true, 'status' => $status]);
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

    public function update(int $incidentId, string $status, string $body): void
    {
        $done = in_array($status, ['resolved', 'completed'], true);
        $this->db()->table('status_updates')->insert(['status_incident_id' => $incidentId, 'status' => $status, 'body' => $body, 'created_at' => now()]);
        $this->db()->table('status_incidents')->where('id', $incidentId)->update(['status' => $status, 'resolved_at' => $done ? now() : null, 'updated_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $this->ensureComponents();
        $components = $this->db()->table('status_components')->orderBy('id')->get(['key', 'name', 'status', 'checked_at']);
        $worst = $components->contains('status', 'outage') ? 'outage' : ($components->contains('status', 'degraded') ? 'degraded' : 'operational');
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
