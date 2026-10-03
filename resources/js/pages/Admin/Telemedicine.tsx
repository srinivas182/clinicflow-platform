import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Flash } from '@/components/Flash';
import { Field } from '@/components/form/Field';
import { Badge, Button, Card } from '@/components/ui';
import { AdminShell } from '@/layouts/AdminShell';

interface Config {
    driver: string;
    label: string;
    mode: string;
    url: string | null;
    apiKey: string | null;
    hasSecret: boolean;
    enabled: boolean;
    lastTest: { at: string; ok: boolean } | null;
}

function ConfigCard({ c }: { c: Config }) {
    const form = useForm({ mode: c.mode, url: c.url ?? '', api_key: c.apiKey ?? '', api_secret: '', enabled: c.enabled });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/admin/telemedicine/${c.driver}`, { preserveScroll: true, onSuccess: () => form.setData('api_secret', '') });
    };

    return (
        <Card title={c.label} aside={<Badge tone={c.enabled ? (c.mode === 'live' ? 'success' : 'warning') : 'neutral'}>{c.enabled ? `active · ${c.mode}` : 'off'}</Badge>}>
            <form onSubmit={submit} className="flex flex-col gap-3">
                <div className="flex gap-4 text-sm">
                    {['test', 'live'].map((m) => (
                        <label key={m} className="flex items-center gap-1.5">
                            <input type="radio" className="accent-teal" checked={form.data.mode === m} onChange={() => form.setData('mode', m)} /> {m === 'test' ? 'Test' : 'Live'}
                        </label>
                    ))}
                </div>
                <Field label="Server address (wss://…)" name={`url-${c.driver}`} value={form.data.url} onChange={(e) => form.setData('url', e.target.value)} error={form.errors.url} />
                <Field label="API key" name={`key-${c.driver}`} value={form.data.api_key} onChange={(e) => form.setData('api_key', e.target.value)} />
                <Field label={c.hasSecret ? 'API secret (saved — leave blank to keep)' : 'API secret'} name={`secret-${c.driver}`} type="password" value={form.data.api_secret} onChange={(e) => form.setData('api_secret', e.target.value)} />
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" className="accent-teal" checked={form.data.enabled} onChange={(e) => form.setData('enabled', e.target.checked)} /> Active — switches off the other option
                </label>
                <div className="flex items-center gap-2">
                    <Button type="submit" disabled={form.processing}>
                        Save
                    </Button>
                    <Button type="button" variant="secondary" disabled={!c.url} onClick={() => router.post(`/admin/telemedicine/${c.driver}/test`, {}, { preserveScroll: true })}>
                        Test connection
                    </Button>
                    {c.lastTest && <span className={`text-xs ${c.lastTest.ok ? 'text-status-success' : 'text-status-danger'}`}>{c.lastTest.ok ? 'Worked' : 'Failed'} · {c.lastTest.at}</span>}
                </div>
            </form>
        </Card>
    );
}

export default function AdminTelemedicine({ configs, webhookUrl }: { configs: Config[]; webhookUrl: string }) {
    return (
        <AdminShell active="Telemedicine">
            <Head title="Telemedicine" />
            <h1 className="mb-1 text-2xl font-semibold">Telemedicine video</h1>
            <p className="mb-5 text-sm text-muted">
                One option is active at a time; calls already running finish on their server. Set the webhook address in the LiveKit dashboard or server configuration: <code className="text-xs">{webhookUrl}</code>
            </p>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                {configs.map((c) => (
                    <ConfigCard key={c.driver} c={c} />
                ))}
            </div>
        </AdminShell>
    );
}
