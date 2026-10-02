import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Ticket } from '@/components/ui';
import type { SharedProps } from '@/types';

export default function Kiosk({ token, provider }: { token: string; provider: string }) {
    const { flash } = usePage<SharedProps>().props;
    const form = useForm({ cell: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/kiosk/${token}`, { onSuccess: () => form.reset() });
    };

    return (
        <main className="flex min-h-screen flex-col items-center justify-center gap-6 bg-white p-10 text-center">
            <Head title="Check in" />
            <h1 className="text-3xl font-semibold">Welcome to {provider}</h1>
            {flash.success ? (
                <div className="flex flex-col items-center gap-4">
                    <Ticket number={flash.success} label="Your ticket" size="lg" />
                    <p className="text-lg">You're checked in. Please take a seat — we'll call your number.</p>
                </div>
            ) : (
                <form onSubmit={submit} className="flex w-full max-w-sm flex-col gap-4">
                    <label htmlFor="cell" className="text-lg">
                        Enter the cell number you booked with
                    </label>
                    <input
                        id="cell"
                        inputMode="numeric"
                        maxLength={10}
                        value={form.data.cell}
                        onChange={(e) => form.setData('cell', e.target.value.replace(/\D/g, ''))}
                        className="rounded-xl border border-line px-4 py-4 text-center text-2xl tracking-widest"
                    />
                    {form.errors.cell && (
                        <p role="alert" className="text-status-danger">
                            {form.errors.cell}
                        </p>
                    )}
                    <Button type="submit" size="lg" className="justify-center" disabled={form.data.cell.length !== 10 || form.processing}>
                        Check in
                    </Button>
                    <p className="text-sm text-muted">No booking? Please go to reception.</p>
                </form>
            )}
        </main>
    );
}
