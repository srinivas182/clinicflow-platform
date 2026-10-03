import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Flash } from '@/components/Flash';
import { Field } from '@/components/form/Field';
import { Badge, Button, Card } from '@/components/ui';
import { AdminShell } from '@/layouts/AdminShell';
import { rand } from '@/lib/money';

interface Props {
    prices: { video: number; audio: number; chat: number };
    threshold: number;
    packs: { amount: number; bonus: number }[];
    wallets: { provider: string; balance: number; reserved: number; below: boolean }[];
}

export default function AdminWallet({ prices, threshold, packs, wallets }: Props) {
    const form = useForm({ video: String(prices.video), audio: String(prices.audio), chat: String(prices.chat), threshold: String(threshold), packs: packs.map((p) => ({ amount: String(p.amount), bonus: String(p.bonus) })) });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/admin/wallet', { preserveScroll: true });
    };

    return (
        <AdminShell active="Wallet">
            <Head title="Telemedicine wallet" />
            <h1 className="mb-5 text-2xl font-semibold">Telemedicine wallet</h1>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                <Card title="Usage pricing and rules">
                    <form onSubmit={submit} className="flex flex-col gap-3">
                        <div className="grid grid-cols-3 gap-2">
                            <Field label="Video R/min" name="video" value={form.data.video} onChange={(e) => form.setData('video', e.target.value)} />
                            <Field label="Audio R/min" name="audio" value={form.data.audio} onChange={(e) => form.setData('audio', e.target.value)} />
                            <Field label="Chat R/session" name="chat" value={form.data.chat} onChange={(e) => form.setData('chat', e.target.value)} />
                        </div>
                        <Field label="Minimum balance for online bookings (R)" name="threshold" value={form.data.threshold} onChange={(e) => form.setData('threshold', e.target.value)} />
                        <div className="text-xs font-medium">Top-up packs (R) and bonus credit</div>
                        {form.data.packs.map((p, i) => (
                            <div key={i} className="grid grid-cols-2 gap-2">
                                <Field label="Pack" name={`pack-${i}`} value={p.amount} onChange={(e) => form.setData('packs', form.data.packs.map((x, j) => (j === i ? { ...x, amount: e.target.value } : x)))} />
                                <Field label="Bonus" name={`bonus-${i}`} value={p.bonus} onChange={(e) => form.setData('packs', form.data.packs.map((x, j) => (j === i ? { ...x, bonus: e.target.value } : x)))} />
                            </div>
                        ))}
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </form>
                </Card>
                <Card title="Provider wallets (lowest first)">
                    <ul className="space-y-1 text-sm">
                        {wallets.map((w) => (
                            <li key={w.provider} className="flex gap-2">
                                <span className="flex-1">{w.provider}</span>
                                <span>{rand(w.balance, 2)}</span>
                                {w.below && <Badge tone="danger">below minimum</Badge>}
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>
        </AdminShell>
    );
}
