import { Head, router, useForm } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Flash } from '@/components/Flash';
import { Field } from '@/components/form/Field';
import { SectionsEditor } from '@/components/site/SectionsEditor';
import type { SiteSection } from '@/components/site/types';
import { Badge, Button, Card } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';

interface Page {
    id: number;
    slug: string;
    title: string;
    meta_description: string | null;
    sections: SiteSection[];
    published: boolean;
    menu_label: string | null;
}

export default function WebsiteSettings({ pages, details, tokens }: { pages: Page[]; details: Record<string, string>; tokens: string[] }) {
    const [current, setCurrent] = useState<Page | undefined>(pages[0]);
    const [sections, setSections] = useState<SiteSection[]>(pages[0]?.sections ?? []);
    const [meta, setMeta] = useState({ title: pages[0]?.title ?? '', menu_label: pages[0]?.menu_label ?? '', published: pages[0]?.published ?? true });
    const contact = useForm({ phone: details.phone === 'us' ? '' : details.phone ?? '', email: details.email ?? '', address: details.address ?? '', hours: details.hours ?? '' });

    const open = (p: Page) => {
        setCurrent(p);
        setSections(p.sections);
        setMeta({ title: p.title, menu_label: p.menu_label ?? '', published: p.published });
    };
    const save = () => current && router.put(`/settings/website/pages/${current.id}`, { ...meta, sections: sections as unknown as Record<string, string>[] }, { preserveScroll: true });
    const saveContact = (e: FormEvent) => {
        e.preventDefault();
        contact.put('/settings/website/details', { preserveScroll: true });
    };

    return (
        <AppShell active="Website">
            <Head title="Website" />
            <div className="mb-5 flex items-end gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">Your website</h1>
                    <p className="text-sm text-muted">
                        Patients see this on your address. Text can use {tokens.join(', ')} — they fill in automatically from your details.
                    </p>
                </div>
                <a href="/" target="_blank" rel="noreferrer" className="ml-auto">
                    <Button variant="secondary" icon={<ExternalLink className="size-4" />}>
                        View site
                    </Button>
                </a>
            </div>
            <Flash />
            <div className="grid grid-cols-4 gap-4">
                <div className="flex flex-col gap-4">
                    <Card title="Pages">
                        <ul className="space-y-1 text-sm">
                            {pages.map((p) => (
                                <li key={p.id}>
                                    <button type="button" onClick={() => open(p)} className={`flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left ${current?.id === p.id ? 'bg-mint font-medium' : ''}`}>
                                        <span className="flex-1">{p.menu_label ?? p.slug}</span>
                                        {!p.published && <Badge>Hidden</Badge>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card title="Contact details">
                        <form onSubmit={saveContact} className="flex flex-col gap-2">
                            <Field label="Phone" name="phone" value={contact.data.phone} onChange={(e) => contact.setData('phone', e.target.value)} />
                            <Field label="Email" name="email" value={contact.data.email} onChange={(e) => contact.setData('email', e.target.value)} error={contact.errors.email} />
                            <Field label="Address" name="address" value={contact.data.address} onChange={(e) => contact.setData('address', e.target.value)} />
                            <Field label="Opening hours" name="hours" value={contact.data.hours} onChange={(e) => contact.setData('hours', e.target.value)} />
                            <Button type="submit" size="sm">
                                Save details
                            </Button>
                        </form>
                    </Card>
                </div>
                {current && (
                    <Card title={`Edit: ${current.menu_label ?? current.slug}`} className="col-span-3">
                        <div className="mb-4 grid grid-cols-3 gap-3">
                            <Field label="Page title" name="title" value={meta.title} onChange={(e) => setMeta({ ...meta, title: e.target.value })} />
                            <Field label="Menu label" name="menu_label" value={meta.menu_label} onChange={(e) => setMeta({ ...meta, menu_label: e.target.value })} />
                            <label className="flex items-end gap-2 pb-2 text-sm">
                                <input type="checkbox" className="accent-teal" disabled={current.slug === 'home'} checked={meta.published} onChange={(e) => setMeta({ ...meta, published: e.target.checked })} />
                                Published
                            </label>
                        </div>
                        <SectionsEditor sections={sections} onChange={setSections} />
                        <Button className="mt-4" onClick={save}>
                            Save page
                        </Button>
                    </Card>
                )}
            </div>
        </AppShell>
    );
}
