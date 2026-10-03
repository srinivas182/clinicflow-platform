import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/form/Field';
import { Button, Card } from '@/components/ui';
import { PortalLayout } from '@/layouts/PortalLayout';

export default function PortalLogin({ provider }: { provider: string }) {
    const form = useForm({ cell: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/my/login');
    };

    return (
        <PortalLayout provider={provider}>
            <Head title="Sign in" />
            <Card title="Your health records">
                <form onSubmit={submit} className="flex flex-col gap-3">
                    <p className="text-sm text-muted">Enter the cell number the practice has for you. We'll SMS you a one-time code.</p>
                    <Field label="Cell number" name="cell" inputMode="tel" maxLength={10} value={form.data.cell} onChange={(e) => form.setData('cell', e.target.value.replace(/\D/g, ''))} error={form.errors.cell} />
                    <Button type="submit" size="lg" disabled={form.processing || form.data.cell.length !== 10}>
                        Send code
                    </Button>
                </form>
            </Card>
        </PortalLayout>
    );
}
