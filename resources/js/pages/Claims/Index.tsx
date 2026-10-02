import { Head, Link, router } from '@inertiajs/react';
import { Flash } from '@/components/Flash';
import { Badge, Button, Card, type BadgeTone } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface ClaimRow {
    id: string;
    invoiceId: string;
    patient: string;
    scheme: string;
    member: string;
    total: number;
    status: string;
    reason: string | null;
    reference: string | null;
    submissions: number;
}

const tone: Record<string, BadgeTone> = { accepted: 'success', rejected: 'danger', draft: 'neutral', paid: 'success' };

export default function ClaimsIndex({ status, claims, unclaimed, ageing }: { status: string; claims: ClaimRow[]; unclaimed: { id: string; number: string; patient: string; total: number }[]; ageing: Record<string, number> }) {
    return (
        <AppShell active="Front desk">
            <Head title="Claims" />
            <div className="mb-5 flex items-end gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">Medical aid claims</h1>
                    <p className="text-sm text-muted">Claims are built from the invoice and the doctor's ICD-10 diagnoses. Fix rejections and resubmit.</p>
                </div>
                <div className="ml-auto flex gap-1">
                    {['', 'accepted', 'rejected'].map((s) => (
                        <Button key={s} size="sm" variant={status === s ? 'primary' : 'secondary'} onClick={() => router.get('/claims', s ? { status: s } : {})}>
                            {s === '' ? 'All' : s}
                        </Button>
                    ))}
                </div>
            </div>
            <Flash />
            <Card title="Unpaid claims by age (days)" className="mb-4" aside={<Button size="sm" variant="secondary" onClick={() => router.post('/claims/remittances/import', {}, { preserveScroll: true })}>Import remittances</Button>}>
                <div className="grid grid-cols-4 gap-3 text-sm">
                    {Object.entries(ageing).map(([bucket, amount]) => (
                        <div key={bucket} className="rounded-lg border border-line px-3 py-2">
                            <div className="text-xs text-muted">{bucket}</div>
                            <div className={`text-lg font-semibold ${bucket === '90+' && amount > 0 ? 'text-status-danger' : ''}`}>{rand(amount, 2)}</div>
                        </div>
                    ))}
                </div>
            </Card>
            {unclaimed.length > 0 && (
                <Card title="Ready to claim" className="mb-4">
                    <ul className="divide-y divide-[#EBF0EE] text-sm">
                        {unclaimed.map((i) => (
                            <li key={i.id} className="flex items-center gap-3 py-2.5">
                                <Link href={`/invoices/${i.id}`} className="font-medium text-teal-deep">
                                    {i.number}
                                </Link>
                                <span className="flex-1">{i.patient}</span>
                                <span>{rand(i.total, 2)}</span>
                                <Button size="sm" onClick={() => router.post(`/invoices/${i.id}/claim`, {}, { preserveScroll: true })}>
                                    Submit claim
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
            <Card title="Claims">
                <table className="w-full text-sm">
                    <tbody>
                        {claims.length === 0 && (
                            <tr>
                                <td className="py-3 text-muted">No claims yet.</td>
                            </tr>
                        )}
                        {claims.map((c) => (
                            <tr key={c.id} className="border-t border-[#EBF0EE] first:border-0">
                                <td className="py-2.5">
                                    <div className="font-medium">{c.patient}</div>
                                    <div className="text-xs text-muted">
                                        {c.scheme} · {c.member}
                                    </div>
                                </td>
                                <td className="py-2.5">{rand(c.total, 2)}</td>
                                <td className="py-2.5">
                                    <Badge tone={tone[c.status] ?? 'neutral'}>{c.status}</Badge>
                                    {c.reason && <div className="text-xs text-status-danger">{c.reason}</div>}
                                    {c.reference && <div className="text-xs text-muted">{c.reference}</div>}
                                </td>
                                <td className="py-2.5 text-right">
                                    {c.status === 'rejected' && (
                                        <Button size="sm" variant="secondary" onClick={() => router.post(`/invoices/${c.invoiceId}/claim`, {}, { preserveScroll: true })}>
                                            Resubmit
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </AppShell>
    );
}
