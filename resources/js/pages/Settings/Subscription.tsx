import { Head } from '@inertiajs/react';
import { Badge, Button, Card } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Inv {
    number: string;
    period: string;
    total: number;
    status: string;
    due: string;
    payUrl: string | null;
}

export default function Subscription({ plan, invoices }: { plan: { package: string; status: string; trialEndsAt: string | null; periodEndsAt: string | null } | null; invoices: Inv[] }) {
    return (
        <AppShell active="Settings">
            <Head title="Subscription" />
            <h1 className="mb-5 text-2xl font-semibold">Subscription</h1>
            {plan && (
                <Card title={plan.package} aside={<Badge tone={plan.status === 'active' ? 'success' : 'warning'}>{plan.status.replace('_', ' ')}</Badge>} className="mb-4">
                    <p className="text-sm text-muted">
                        {plan.periodEndsAt ? `Paid until ${plan.periodEndsAt}.` : plan.trialEndsAt ? `Free trial until ${plan.trialEndsAt}.` : ''} Invoices are issued 3 days before each period. Prices exclude
                        15% VAT.
                    </p>
                </Card>
            )}
            <Card title="Invoices">
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
                                <td className="py-2.5 text-muted">{i.period}</td>
                                <td className="py-2.5">{rand(i.total, 2)}</td>
                                <td className="py-2.5">
                                    <Badge tone={i.status === 'paid' ? 'success' : 'warning'}>{i.status}</Badge>
                                </td>
                                <td className="py-2.5 text-right">
                                    {i.payUrl && (
                                        <a href={i.payUrl}>
                                            <Button size="sm">Pay now</Button>
                                        </a>
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
