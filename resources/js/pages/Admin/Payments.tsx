import { Head } from '@inertiajs/react';
import { Flash } from '@/components/Flash';
import { GatewayCard, type GatewayRow } from '@/components/payments/GatewayCard';
import { Badge, Card } from '@/components/ui';
import { AdminShell } from '@/layouts/AdminShell';
import { rand } from '@/lib/money';

interface Inv {
    number: string;
    provider: string;
    total: number;
    status: string;
    gateway: string | null;
    due: string;
}

export default function AdminPayments({ gateways, invoices }: { gateways: GatewayRow[]; invoices: Inv[] }) {
    return (
        <AdminShell active="Payments">
            <Head title="Payments" />
            <h1 className="mb-1 text-2xl font-semibold">Payments</h1>
            <p className="mb-5 text-sm text-muted">
                The platform's own accounts collect provider subscriptions. "Offer to providers" controls which gateways practices may connect for their patients.
            </p>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                {gateways.map((g) => (
                    <GatewayCard key={g.gateway} row={g} base="/admin/payments" showOffered />
                ))}
            </div>
            <Card title="Recent subscription invoices" className="mt-6">
                <table className="w-full text-sm">
                    <tbody>
                        {invoices.length === 0 && (
                            <tr>
                                <td className="py-3 text-muted">No invoices yet.</td>
                            </tr>
                        )}
                        {invoices.map((i) => (
                            <tr key={i.number} className="border-t border-[#EBF0EE] first:border-0">
                                <td className="py-2.5 font-medium">{i.number}</td>
                                <td className="py-2.5">{i.provider}</td>
                                <td className="py-2.5">{rand(i.total, 2)}</td>
                                <td className="py-2.5 text-muted">Due {i.due}</td>
                                <td className="py-2.5">
                                    <Badge tone={i.status === 'paid' ? 'success' : 'warning'}>{i.status}</Badge>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </AdminShell>
    );
}
