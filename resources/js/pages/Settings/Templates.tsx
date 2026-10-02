import { Head, router, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import type { FormEvent } from 'react';
import { Flash } from '@/components/Flash';
import { Field } from '@/components/form/Field';
import { Badge, Button, Card } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';

interface Props {
    type: string;
    types: { value: string; label: string }[];
    template: { version: number; body: string; paper: string };
    legalBlock: string;
    versions: { version: number; is_active: boolean; created_at: string }[];
    practice: Record<string, string>;
    fields: string[];
}

export default function Templates({ type, types, template, legalBlock, versions, practice, fields }: Props) {
    const form = useForm({ body: template.body, paper: template.paper });
    const brand = useForm({ address: practice.address ?? '', phone: practice.phone ?? '', bhf: practice.bhf ?? '', vat_number: practice.vat_number ?? '', colour: practice.colour ?? '#0F7C74' });

    const publish = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/settings/templates/${type}`, { preserveScroll: true });
    };

    return (
        <AppShell active="Templates">
            <Head title="Template studio" />
            <h1 className="text-2xl font-semibold">Template studio</h1>
            <p className="mb-4 text-sm text-muted">Your name, logo colour and details on every document. Publishing makes a new version; issued documents keep theirs.</p>
            <Flash />
            <div className="mb-4 flex gap-2">
                {types.map((t) => (
                    <Button key={t.value} size="sm" variant={t.value === type ? 'primary' : 'secondary'} onClick={() => router.get('/settings/templates', { type: t.value })}>
                        {t.label}
                    </Button>
                ))}
            </div>
            <div className="grid grid-cols-3 gap-4">
                <Card title={`Body · version ${template.version}`} className="col-span-2">
                    <form onSubmit={publish} className="flex flex-col gap-3">
                        <label htmlFor="body" className="sr-only">
                            Template body
                        </label>
                        <textarea id="body" rows={14} className="rounded-lg border border-line p-3 font-mono text-xs" value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                        {form.errors.body && <p className="text-xs text-status-danger">{form.errors.body}</p>}
                        <div className="rounded-lg border border-[#F3C7C7] bg-[#FFF8F8] p-3 text-xs">
                            <div className="mb-1 flex items-center gap-1 font-medium">
                                <Lock className="size-3.5" aria-hidden="true" /> Locked legal block (always added)
                            </div>
                            <code className="break-all text-muted">{legalBlock}</code>
                        </div>
                        <div className="flex items-center gap-2">
                            <select aria-label="Paper" className="rounded-lg border border-line px-3 py-2 text-sm" value={form.data.paper} onChange={(e) => form.setData('paper', e.target.value)}>
                                <option value="A4">A4</option>
                                <option value="A5">A5</option>
                            </select>
                            <a href={`/settings/templates/${type}/preview`} target="_blank" rel="noreferrer" className="ml-auto">
                                <Button variant="secondary">Preview PDF</Button>
                            </a>
                            <Button type="submit" disabled={form.processing}>
                                Publish version {template.version + 1}
                            </Button>
                        </div>
                    </form>
                </Card>
                <div className="flex flex-col gap-4">
                    <Card title="Merge fields">
                        <div className="flex flex-wrap gap-1">
                            {fields.map((f) => (
                                <Badge key={f} tone="teal">{`{{ ${f} }}`}</Badge>
                            ))}
                            <Badge tone="teal">{'{{#lines}} … {{/lines}}'}</Badge>
                        </div>
                    </Card>
                    <Card title="Practice details">
                        <form
                            className="flex flex-col gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                brand.put('/settings/branding', { preserveScroll: true });
                            }}
                        >
                            <Field label="Address" name="address" value={brand.data.address} onChange={(e) => brand.setData('address', e.target.value)} />
                            <Field label="Phone" name="phone" value={brand.data.phone} onChange={(e) => brand.setData('phone', e.target.value)} />
                            <Field label="BHF practice number" name="bhf" value={brand.data.bhf} onChange={(e) => brand.setData('bhf', e.target.value)} />
                            <Field label="VAT number" name="vat_number" value={brand.data.vat_number} onChange={(e) => brand.setData('vat_number', e.target.value)} />
                            <Field label="Brand colour" name="colour" type="color" value={brand.data.colour} onChange={(e) => brand.setData('colour', e.target.value)} error={brand.errors.colour} />
                            <Button type="submit" variant="secondary">
                                Save details
                            </Button>
                        </form>
                    </Card>
                    <Card title="Versions">
                        <ul className="text-sm">
                            {versions.map((v) => (
                                <li key={v.version} className="flex justify-between py-1">
                                    <span>Version {v.version}</span>
                                    {v.is_active && <Badge tone="success">Active</Badge>}
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>
        </AppShell>
    );
}
