import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/form/Field';
import { Button } from '@/components/ui';
import { AuthLayout } from '@/layouts/AuthLayout';

export default function Login() {
    const form = useForm({ login: '', password: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login');
    };

    return (
        <AuthLayout>
            <Head title="Sign in" />
            <h1 className="text-2xl font-semibold">Sign in</h1>
            <p className="mt-1 text-sm text-muted">We'll send a one-time code to your phone after your password.</p>
            <form onSubmit={submit} className="mt-8 flex flex-col gap-4" noValidate>
                <Field
                    label="Email or cell number"
                    name="login"
                    autoComplete="username"
                    value={form.data.login}
                    onChange={(e) => form.setData('login', e.target.value)}
                    error={form.errors.login}
                />
                <Field
                    label="Password"
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                />
                <Button type="submit" size="lg" disabled={form.processing}>
                    Continue
                </Button>
            </form>
        </AuthLayout>
    );
}
