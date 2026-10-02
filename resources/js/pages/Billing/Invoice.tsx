import { Head, router, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import type { FormEvent } from 'react';
import { Flash } from '@/components/Flash';
import { Field } from '@/components/form/Field';
import { Badge, Button, Card } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Line {
    id: number;
    code: string | null;
    description: string;
    quantity: number;
    total: number;
    locked: boolean;
}

interface Pay {
    id: number;
    method: string;
    amount: number;
    refunded: number;
    status: string;
    reference: string | null;
    refundable: number;
}

interface Inv {
    id: string;
    number: string;
    patient: string;
    payer: string;
    status: string;
    total: number;
    paid: number;
    balance: number;
    needsReview: boolean;
    reviewNote: string | null;
    lines: Line[];
    payments: Pay[];
}

export default function InvoicePage({ invoice, refundRule, canRefund }: { invoice: Inv; refundRule: string; canRefund: boolean }) {
    const pay = useForm({ method: 'card_machine', amount: String(invoice.balance), reference: '' });
    const line = useForm({ kind: 'procedure', description: '', code: '', amount: '', quantity: 1 });
    const select = 'min-h-10 rounded-lg border border-[#CBD5D2] bg-white px-3 py-2 text-sm';

    const submitPay = (e: FormEvent) => {
        e.preventDefault();
        pay.post(`/invoices/${invoice.id}/payments`, { preserveScroll: true });
    };

    const refund = (p: Pay) => {
        const reason = window.prompt('Reason for the refund?');
        if (reason) router.post(`/payments/${p.id}/refunds`, { amount: p.refundable, reason }, { preserveScroll: true });
    };

    return (
        <AppShell active="Front desk">
            <Head title={invoice.number} />
            <div className="mb-5 flex items-end gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">Invoice {invoice.number}</h1>
                    <p className="text-sm text-muted">
                        {invoice.patient} · {invoice.payer === 'cash' ? 'Cash' : 'Medical aid'}
                    </p>
                </div>
                <Badge tone={invoice.status === 'paid' ? 'success' : invoice.status === 'part_paid' ? 'warning' : 'neutral'}>{invoice.status.replace('_', ' ')}</Badge>
            </div>
            <Flash />
            {invoice.needsReview && (
                <div role="alert" className="mb-4 rounded-lg border border-[#F0DE9C] bg-status-warning-wash px-4 py-3 text-sm text-status-warning">
                    {invoice.reviewNote}
                </div>
            )}
            <div className="grid grid-cols-3 gap-4">
                <Card title="Lines" className="col-span-2">
                    <table className="w-full text-sm">
                        <tbody>
                            {invoice.lines.map((l) => (
                                <tr key={l.id} className="border-t border-[#EBF0EE] first:border-0">
                                    <td className="py-2 text-muted">{l.code}</td>
                                    <td className="py-2">{l.description}</td>
                                    <td className="py-2 text-right">{l.quantity}</td>
                                    <td className="py-2 text-right">{rand(l.total, 2)}</td>
                                    <td className="py-2 text-right">
                                        {l.locked ? (
                                            <Lock className="ml-auto size-4 text-muted" aria-label="Paid — cannot be changed" />
                                        ) : (
                                            <button type="button" className="text-xs text-status-danger" onClick={() => router.delete(`/invoice-lines/${l.id}`, { preserveScroll: true })}>
                                                Remove
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="mt-3 flex justify-end gap-6 border-t border-line pt-3 text-sm">
                        <span>Total {rand(invoice.total, 2)}</span>
                        <span>Paid {rand(invoice.paid, 2)}</span>
                        <span className="font-semibold">Due {rand(invoice.balance, 2)}</span>
                    </div>
                    <form
                        className="mt-4 grid grid-cols-5 items-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            line.post(`/invoices/${invoice.id}/lines`, { preserveScroll: true, onSuccess: () => line.reset() });
                        }}
                    >
                        <select aria-label="Kind" className={select} value={line.data.kind} onChange={(e) => line.setData('kind', e.target.value)}>
                            <option value="procedure">Procedure</option>
                            <option value="medicine">Medicine</option>
                            <option value="certificate">Certificate</option>
                            <option value="other">Other</option>
                        </select>
                        <Field label="Description" name="description" className="col-span-2" value={line.data.description} onChange={(e) => line.setData('description', e.target.value)} error={line.errors.description} />
                        <Field label="Amount (R)" name="amount" value={line.data.amount} onChange={(e) => line.setData('amount', e.target.value)} error={line.errors.amount} />
                        <Button type="submit" variant="secondary">
                            Add line
                        </Button>
                    </form>
                </Card>
                <div className="flex flex-col gap-4">
                    <Card title="Take payment">
                        <form onSubmit={submitPay} className="flex flex-col gap-3">
                            <select aria-label="Method" className={select} value={pay.data.method} onChange={(e) => pay.setData('method', e.target.value)}>
                                <option value="card_machine">Card machine</option>
                                <option value="cash">Cash</option>
                                <option value="eft">EFT</option>
                                <option value="pay_link">Pay link (SMS)</option>
                            </select>
                            <Field label="Amount (R)" name="amount" value={pay.data.amount} onChange={(e) => pay.setData('amount', e.target.value)} error={pay.errors.amount} />
                            {(pay.data.method === 'card_machine' || pay.data.method === 'eft') && (
                                <Field label="Slip / EFT reference" name="reference" value={pay.data.reference} onChange={(e) => pay.setData('reference', e.target.value)} error={pay.errors.reference} />
                            )}
                            <Button type="submit" disabled={pay.processing || invoice.balance <= 0}>
                                Record payment
                            </Button>
                            <p className="text-xs text-muted">Paid into the practice's own account. If the patient leaves before being seen: {refundRule === 'refund' ? 'refund' : refundRule === 'credit' ? 'credit for next visit' : 'no refund'}.</p>
                        </form>
                    </Card>
                    <Card title="Payments">
                        <ul className="space-y-2 text-sm">
                            {invoice.payments.map((p) => (
                                <li key={p.id} className="flex items-center gap-2">
                                    <span className="flex-1">
                                        {p.method} · {rand(p.amount, 2)}
                                        {p.refunded > 0 && <span className="text-xs text-muted"> (refunded {rand(p.refunded, 2)})</span>}
                                    </span>
                                    <Badge tone={p.status === 'succeeded' ? 'success' : 'warning'}>{p.status}</Badge>
                                    {canRefund && p.refundable > 0 && (
                                        <button type="button" className="text-xs text-teal-deep" onClick={() => refund(p)}>
                                            Refund
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>
        </AppShell>
    );
}
