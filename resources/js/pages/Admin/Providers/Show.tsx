import { Head, router } from '@inertiajs/react';
import { Flash } from '@/components/Flash';
import { Badge, Button, Card } from '@/components/ui';
import { AdminShell } from '@/layouts/AdminShell';
import { statusTone } from './Index';

interface Check {
    id: number;
    label: string;
    reference: string | null;
    status: string;
    notes: string | null;
}

export default function ProviderShow({ provider, checks }: { provider: { id: string; name: string; type: string; status: string }; checks: Check[] }) {
    const review = (id: number, status: string) => router.post(`/admin/verification-checks/${id}`, { status }, { preserveScroll: true });

    return (
        <AdminShell active="Verification">
            <Head title={provider.name} />
            <div className="mb-5 flex items-end gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">{provider.name}</h1>
                    <p className="text-sm text-muted">{provider.type}</p>
                </div>
                <Badge tone={statusTone[provider.status] ?? 'neutral'}>{provider.status.replace('_', ' ')}</Badge>
                <div className="ml-auto">
                    <Button onClick={() => router.post(`/admin/providers/${provider.id}/approve`)}>Approve — list and open bookings</Button>
                </div>
            </div>
            <Flash />
            <Card title="Registration checks">
                <table className="w-full text-sm">
                    <tbody>
                        {checks.map((c) => (
                            <tr key={c.id} className="border-t border-[#EBF0EE] first:border-0">
                                <td className="py-3 font-medium">{c.label}</td>
                                <td className="py-3 text-muted">{c.reference ?? 'Not supplied'}</td>
                                <td className="py-3">
                                    <Badge tone={c.status === 'verified' ? 'success' : c.status === 'rejected' ? 'danger' : 'warning'}>{c.status}</Badge>
                                </td>
                                <td className="py-3 text-right">
                                    <span className="inline-flex gap-2">
                                        <Button size="sm" variant="secondary" onClick={() => review(c.id, 'rejected')}>
                                            Reject
                                        </Button>
                                        <Button size="sm" onClick={() => review(c.id, 'verified')}>
                                            Verify
                                        </Button>
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </AdminShell>
    );
}
