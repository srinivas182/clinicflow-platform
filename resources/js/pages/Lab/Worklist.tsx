import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Flash } from '@/components/Flash';
import { Badge, Button, Card } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';

export interface LabRow {
    id: string;
    patient: string;
    status: string;
    barcode: string | null;
    critical: boolean;
    acknowledged: boolean;
    reviewed: boolean;
    results: { test_code: string; name: string; unit: string; reference: string | null; value: string | null; flag: string | null }[];
}

export const flagTone = (flag: string | null) => (flag?.startsWith('critical') ? 'danger' : flag === 'high' || flag === 'low' ? 'warning' : 'success');

export default function Worklist({ orders }: { orders: LabRow[] }) {
    const [values, setValues] = useState<Record<string, Record<string, string>>>({});
    const step = (id: string, s: string, data: Record<string, string | Record<string, string>> = {}) => router.post(`/lab-orders/${id}/${s}`, data, { preserveScroll: true });

    return (
        <AppShell active="Lab">
            <Head title="Lab worklist" />
            <h1 className="mb-1 text-2xl font-semibold">Lab worklist</h1>
            <p className="mb-5 text-sm text-muted">Collect → enter results → a second person verifies. Critical values are flagged for the doctor.</p>
            <Flash />
            <div className="flex flex-col gap-3">
                {orders.length === 0 && <p className="text-sm text-muted">No open orders.</p>}
                {orders.map((o) => (
                    <Card key={o.id} title={o.patient} aside={<Badge tone={o.status === 'resulted' ? 'warning' : 'teal'}>{o.status}</Badge>}>
                        {o.barcode && <p className="mb-2 font-mono text-xs">{o.barcode}</p>}
                        <table className="w-full text-sm">
                            <tbody>
                                {o.results.map((r) => (
                                    <tr key={r.test_code} className="border-t border-[#EBF0EE] first:border-0">
                                        <td className="py-1.5">{r.name}</td>
                                        <td className="py-1.5 text-xs text-muted">
                                            {r.reference} {r.unit}
                                        </td>
                                        <td className="py-1.5">
                                            {o.status === 'collected' ? (
                                                <input
                                                    aria-label={`${r.name} value`}
                                                    className="w-24 rounded border border-line px-2 py-1"
                                                    value={values[o.id]?.[r.test_code] ?? ''}
                                                    onChange={(e) => setValues({ ...values, [o.id]: { ...(values[o.id] ?? {}), [r.test_code]: e.target.value } })}
                                                />
                                            ) : (
                                                r.value !== null && <Badge tone={flagTone(r.flag)}>{r.value} {r.flag !== 'normal' ? r.flag?.replace('_', ' ') : ''}</Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="mt-3">
                            {o.status === 'ordered' && <Button size="sm" onClick={() => step(o.id, 'collect')}>Collect sample</Button>}
                            {o.status === 'collected' && <Button size="sm" onClick={() => step(o.id, 'results', { values: values[o.id] ?? {} })}>Save results</Button>}
                            {o.status === 'resulted' && <Button size="sm" onClick={() => step(o.id, 'verify')}>Verify</Button>}
                        </div>
                    </Card>
                ))}
            </div>
        </AppShell>
    );
}
