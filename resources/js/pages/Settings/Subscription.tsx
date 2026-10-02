import { Head, router } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import { useState } from 'react';
import { Flash } from '@/components/Flash';
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
    lastAttempt: { outcome: string; message: string | null; attempted_at: string } | null;
}

interface AutoDebit {
    card: string;
    expiry: string | null;
    gateway: string;
    since: string;
    chargedBy: string;
}

export default function Subscription({
    plan,
    invoices,
    autoDebit,
    autoDebitAvailable,
    platformGateway,
}: {
    plan: { package: string; status: string; trialEndsAt: string | null; periodEndsAt: string | null } | null;
    invoices: Inv[];
    autoDebit: AutoDebit | null;
    autoDebitAvailable: boolean;
    platformGateway: string | null;
}) {
    const [consent, setConsent] = useState(false);
    const open = invoices.find((i) => i.status === 'open');

    return (
        <AppShell active="Settings">
            <Head title="Subscription" />
            <h1 className="mb-5 text-2xl font-semibold">Subscription</h1>
            <Flash />
            {plan && (
                <Card title={plan.package} aside={<Badge tone={plan.status === 'active' ? 'success' : 'warning'}>{plan.status.replace('_', ' ')}</Badge>} className="mb-4">
                    <p className="text-sm text-muted">
                        {plan.periodEndsAt ? `Paid until ${plan.periodEndsAt}.` : plan.trialEndsAt ? `Free trial until ${plan.trialEndsAt}.` : ''} Invoices are issued 3 days before each period. Prices exclude
                        15% VAT.
                    </p>
                </Card>
            )}
            <Card title="Automatic payment" className="mb-4">
                {autoDebit ? (
                    <div className="flex items-center gap-4 text-sm">
                        <CreditCard className="size-6 text-teal" aria-hidden="true" />
                        <div className="flex-1">
                            <div className="font-medium">
                                {autoDebit.card}
                                {autoDebit.expiry && <span className="text-muted"> · expires {autoDebit.expiry}</span>}
                            </div>
                            <div className="text-xs text-muted">
                                Via {autoDebit.gateway} since {autoDebit.since}. Each invoice is charged on its due date by {autoDebit.chargedBy}; failed payments are retried twice, two days apart, and you're emailed every time.
                            </div>
                        </div>
                        <Button
                            variant="secondary"
                            onClick={() => {
                                if (window.confirm('Switch off automatic payment and remove this card?')) router.post('/settings/subscription/auto-debit/stop');
                            }}
                        >
                            Switch off
                        </Button>
                    </div>
                ) : !autoDebitAvailable ? (
                    <p className="text-sm text-muted">
                        Automatic payment isn't available with {platformGateway ?? 'the current payment provider'}. Please pay each invoice when it's issued.
                    </p>
                ) : open ? (
                    <div className="text-sm">
                        <label className="flex items-start gap-2">
                            <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} className="mt-0.5 accent-teal" />
                            <span>
                                I authorise Clinic Flow to save the card I use for invoice {open.number} with {platformGateway} and charge it for future subscription invoices on their due date. I can
                                switch this off at any time.
                            </span>
                        </label>
                        <Button className="mt-3" disabled={!consent} onClick={() => router.post(`/settings/subscription/invoices/${open.number}/auto-pay`, { consent: true })}>
                            Pay {rand(open.total, 2)} and turn on automatic payment
                        </Button>
                    </div>
                ) : (
                    <p className="text-sm text-muted">You can turn on automatic payment when you pay your next invoice (issued 3 days before your period ends).</p>
                )}
            </Card>
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
                                    {i.status === 'open' && i.lastAttempt?.outcome === 'failed' && (
                                        <span className="ml-2 text-xs text-status-danger">Automatic payment failed{i.lastAttempt.message ? `: ${i.lastAttempt.message}` : ''}</span>
                                    )}
                                </td>
                                <td className="py-2.5 text-right">
                                    {i.payUrl && (
                                        <a href={i.payUrl}>
                                            <Button size="sm" variant={autoDebit ? 'secondary' : 'primary'}>
                                                Pay now
                                            </Button>
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
