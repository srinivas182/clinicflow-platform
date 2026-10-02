import { Head, router } from '@inertiajs/react';
import { Siren } from 'lucide-react';
import { Flash } from '@/components/Flash';
import { Badge, Button, Card } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';
import { flagTone, type LabRow } from './Worklist';

export default function Inbox({ orders }: { orders: LabRow[] }) {
    const step = (id: string, s: string, data: Record<string, string | Record<string, string>> = {}) => router.post(`/lab-orders/${id}/${s}`, data, { preserveScroll: true });

    return (
        <AppShell active="Results">
            <Head title="Results inbox" />
            <h1 className="mb-1 text-2xl font-semibold">Results inbox</h1>
            <p className="mb-5 text-sm text-muted">Critical results first. Review, then release to the patient — the patient gets an SMS that results are ready, never the values.</p>
            <Flash />
            <div className="flex flex-col gap-3">
                {orders.length === 0 && <p className="text-sm text-muted">Nothing waiting for review.</p>}
                {orders.map((o) => (
                    <Card key={o.id} title={o.patient} aside={o.critical ? <Badge tone="danger" icon={<Siren className="size-3" />}>Critical</Badge> : undefined}>
                        <ul className="space-y-1 text-sm">
                            {o.results.map((r) => (
                                <li key={r.test_code} className="flex gap-2">
                                    <span className="flex-1">{r.name}</span>
                                    <Badge tone={flagTone(r.flag)}>
                                        {r.value} {r.unit}
                                    </Badge>
                                    <span className="w-24 text-xs text-muted">{r.reference}</span>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {o.critical && !o.acknowledged && (
                                <Button
                                    size="sm"
                                    variant="danger"
                                    onClick={() => {
                                        const action = window.prompt('What did you do about the critical result? (e.g. phoned patient, referred)');
                                        if (action) step(o.id, 'acknowledge', { action });
                                    }}
                                >
                                    Acknowledge critical
                                </Button>
                            )}
                            {!o.reviewed && (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => {
                                        const comment = window.prompt('Comment for the patient (optional)') ?? '';
                                        step(o.id, 'review', { comment });
                                    }}
                                >
                                    Mark reviewed
                                </Button>
                            )}
                            <Button size="sm" disabled={!o.reviewed || (o.critical && !o.acknowledged)} onClick={() => step(o.id, 'release')}>
                                Release to patient
                            </Button>
                        </div>
                    </Card>
                ))}
            </div>
        </AppShell>
    );
}
