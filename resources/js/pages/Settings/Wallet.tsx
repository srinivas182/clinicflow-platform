import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Flash } from '@/components/Flash';
import { Badge, Button, Card, Kpi } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Props {
    wallet: { balance: number; reserved: number; available: number; threshold: number; acceptsOnline: boolean; autoTopup: boolean; autoTopupPack: number | null };
    packs: { amount: number; bonus: number }[];
    prices: { video: number; audio: number; chat: number };
    savedCard: string | null;
    statement: { at: string; type: string; amount: number; balance: number; description: string }[];
}

export default function WalletPage({ wallet, packs, prices, savedCard, statement }: Props) {
    const [autoPack, setAutoPack] = useState<number>(wallet.autoTopupPack ?? packs[0]?.amount ?? 0);

    return (
        <AppShell active="Settings">
            <Head title="Telemedicine wallet" />
            <h1 className="mb-1 text-2xl font-semibold">Telemedicine wallet</h1>
            <p className="mb-5 text-sm text-muted">
                Video {rand(prices.video, 2)}/min · audio {rand(prices.audio, 2)}/min · chat {rand(prices.chat, 2)}/session (excl. VAT). Booking reserves the expected cost; calls are never cut off.
            </p>
            <Flash />
            <div className="mb-4 flex gap-4">
                <Kpi label="Available" value={rand(wallet.available, 2)} hint={wallet.acceptsOnline ? 'Online slots are open' : `Below ${rand(wallet.threshold)} — online slots hidden`} trend={wallet.acceptsOnline ? 'up' : 'down'} />
                <Kpi label="Balance" value={rand(wallet.balance, 2)} />
                <Kpi label="Reserved for booked consults" value={rand(wallet.reserved, 2)} />
            </div>
            <div className="grid grid-cols-3 gap-4">
                <Card title="Top up" className="col-span-1">
                    <ul className="space-y-2">
                        {packs.map((p) => (
                            <li key={p.amount} className="flex items-center gap-2 text-sm">
                                <span className="flex-1">
                                    {rand(p.amount)} {p.bonus > 0 && <Badge tone="success">+{rand(p.bonus)} bonus</Badge>}
                                </span>
                                <Button size="sm" onClick={() => router.post('/settings/wallet/topups', { amount: p.amount, method: 'pay_link' })}>
                                    Pay
                                </Button>
                                {savedCard && (
                                    <Button size="sm" variant="secondary" onClick={() => router.post('/settings/wallet/topups', { amount: p.amount, method: 'saved_card' }, { preserveScroll: true })}>
                                        Card
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-2 text-xs text-muted">VAT is added at checkout. {savedCard ? `Saved card: ${savedCard}.` : ''}</p>
                    <div className="mt-4 border-t border-line pt-3 text-sm">
                        <label className="flex items-center gap-2">
                            <input type="checkbox" className="accent-teal" checked={wallet.autoTopup} onChange={(e) => router.put('/settings/wallet/auto-topup', { enabled: e.target.checked, amount: autoPack }, { preserveScroll: true })} />
                            Auto top-up below the minimum
                        </label>
                        <select aria-label="Auto top-up pack" className="mt-2 rounded-md border border-line px-2 py-1" value={autoPack} onChange={(e) => setAutoPack(Number(e.target.value))}>
                            {packs.map((p) => (
                                <option key={p.amount} value={p.amount}>
                                    {rand(p.amount)}
                                </option>
                            ))}
                        </select>
                    </div>
                </Card>
                <Card title="Statement" className="col-span-2">
                    <table className="w-full text-sm">
                        <tbody>
                            {statement.map((t, i) => (
                                <tr key={i} className="border-t border-[#EBF0EE] first:border-0">
                                    <td className="py-1.5 text-muted">{t.at}</td>
                                    <td className="py-1.5">{t.description}</td>
                                    <td className={`py-1.5 text-right ${t.amount < 0 ? 'text-status-danger' : ''}`}>{t.amount === 0 ? '' : rand(t.amount, 2)}</td>
                                    <td className="py-1.5 text-right text-muted">{rand(t.balance, 2)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            </div>
        </AppShell>
    );
}
