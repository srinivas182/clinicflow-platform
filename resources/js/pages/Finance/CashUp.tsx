import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Flash } from '@/components/Flash';
import { Field } from '@/components/form/Field';
import { Button, Card } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';
import { rand } from '@/lib/money';

export default function CashUp({ expected, closed }: { expected: Record<string, number>; closed: boolean }) {
    const form = useForm({ counted: '', reason: '' });
    const difference = form.data.counted === '' ? 0 : Number(form.data.counted) - (expected.cash ?? 0);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/cash-up');
    };

    return (
        <AppShell active="Front desk">
            <Head title="Cash-up" />
            <h1 className="mb-5 text-2xl font-semibold">Cash-up</h1>
            <Flash />
            <Card title="Your drawer today" className="max-w-xl">
                <ul className="mb-4 space-y-1 text-sm">
                    {Object.entries(expected).map(([m, v]) => (
                        <li key={m} className="flex">
                            <span className="flex-1 capitalize">{m.replace('_', ' ')} expected</span>
                            <span className="font-medium">{rand(v, 2)}</span>
                        </li>
                    ))}
                </ul>
                {closed ? (
                    <p className="text-sm text-muted">Your drawer is closed for today.</p>
                ) : (
                    <form onSubmit={submit} className="flex flex-col gap-3">
                        <Field label="Cash counted (R)" name="counted" value={form.data.counted} onChange={(e) => form.setData('counted', e.target.value)} error={form.errors.counted} />
                        {difference !== 0 && (
                            <Field label={`Reason for the difference of ${rand(difference, 2)}`} name="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} error={form.errors.reason} />
                        )}
                        <Button type="submit" disabled={form.processing || form.data.counted === ''}>
                            Close drawer
                        </Button>
                    </form>
                )}
            </Card>
        </AppShell>
    );
}
