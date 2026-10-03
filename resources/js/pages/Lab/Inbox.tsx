import { Head, router } from '@inertiajs/react';
import { Siren } from 'lucide-react';
import { Flash } from '@/components/Flash';
import { Badge, Button, Card } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';
import { flagTone, type LabRow } from './Worklist';

export default function Inbox({ orders, away, doctors }: { orders: LabRow[]; away: { until: string | null; covering: number | null }; doctors: { id: number; name: string }[] }) {
    const step = (id: string, s: string, data: Record<string, string> = {}) => router.post(`/lab-orders/${id}/${s}`, data, { preserveScroll: true });

    return (
        <AppShell active="Results">
            <Head title="Results inbox" />
            <h1 className="mb-1 text-2xl font-semibold">Results inbox</h1>
            <p className="mb-3 text-sm text-muted">Critical first, then results patients asked for. Normal results are released automatically after 48 hours; abnormal ones are escalated.</p>
            <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
                Away until
                <input aria-label="Away until" type="date" className="rounded border border-line px-2 py-1" defaultValue={away.until ?? ''} id="away-until" />
                covered by
                <select aria-label="Covering doctor" className="rounded border border-line px-2 py-1" defaultValue={away.covering ?? ''} id="covering">
                    <option value="">Nobody</option>
                    {doctors.map((d) => (
                        <option key={d.id} value={d.id}>
                            {d.name}
                        </option>
                    ))}
                </select>
                <Button
                    size="sm"
                    variant="secondary"
                    onClick={() =>
                        router.put('/results/cover', {
                            away_until: (document.getElementById('away-until') as HTMLInputElement).value || null,
                            covering_staff_id: (document.getElementById('covering') as HTMLSelectElement).value || null,
                        })
                    }
                >
                    Save cover
                </Button>
            </div>
            <Flash />
            <div className="flex flex-col gap-3">
                {orders.length === 0 && <p className="text-sm text-muted">Nothing waiting for review.</p>}
                {orders.map((o) => (
                    <Card
                        key={o.id}
                        title={o.patient}
                        aside={
                            <span className="flex gap-1">
                                {o.critical && <Badge tone="danger" icon={<Siren className="size-3" />}>Critical</Badge>}
                                {o.requested && <Badge tone="warning">Patient asked</Badge>}
                                {o.escalated && <Badge tone="danger">Escalated</Badge>}
                                {o.covering && <Badge>Covering</Badge>}
                                <Badge>{o.classification}</Badge>
                                {o.waitingHours !== null && <Badge>{o.waitingHours} h</Badge>}
                            </span>
                        }
                    >
                        <ul className="space-y-1 text-sm">
                            {o.results.map((r) => (
                                <li key={r.test_code} className="flex gap-2">
                                    <span className="flex-1">{r.name}</span>
                                    <Badge tone={flagTone(r.flag)}>
                                        {r.result_text ?? r.value} {r.unit}
                                    </Badge>
                                    <span className="w-24 text-xs text-muted">{r.reference}</span>
                                </li>
                            ))}
                        </ul>
                        {o.status === 'discuss' && <p className="mt-2 text-xs text-muted">Held for discussion: {o.note}</p>}
                        <div className="mt-3 flex flex-wrap gap-2">
                            {o.critical && !o.acknowledged && (
                                <Button
                                    size="sm"
                                    variant="danger"
                                    onClick={() => {
                                        const action = window.prompt('What did you do about the critical result?');
                                        if (action) step(o.id, 'acknowledge', { action });
                                    }}
                                >
                                    Acknowledge critical
                                </Button>
                            )}
                            <Button size="sm" disabled={o.critical && !o.acknowledged} onClick={() => step(o.id, 'release')}>
                                Release
                            </Button>
                            <Button
                                size="sm"
                                variant="secondary"
                                disabled={o.critical && !o.acknowledged}
                                onClick={() => {
                                    const note = window.prompt('Note for the patient');
                                    if (note) step(o.id, 'release', { note });
                                }}
                            >
                                Release with note
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    const note = window.prompt('Message to the patient (values stay hidden)', 'Please book a follow-up so we can discuss your results.');
                                    if (note) step(o.id, 'discuss', { note });
                                }}
                            >
                                Discuss in person
                            </Button>
                        </div>
                    </Card>
                ))}
            </div>
        </AppShell>
    );
}
