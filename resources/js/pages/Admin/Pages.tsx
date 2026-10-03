import { Head, router } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { Flash } from '@/components/Flash';
import { Field } from '@/components/form/Field';
import { SectionsEditor } from '@/components/site/SectionsEditor';
import type { SiteSection } from '@/components/site/types';
import { Badge, Button, Card } from '@/components/ui';
import { AdminShell } from '@/layouts/AdminShell';

interface CmsPage {
    id: number;
    slug: string;
    title: string;
    meta_description: string | null;
    body: string;
    sections: SiteSection[] | null;
    menu_label: string | null;
    menu_order: number | null;
    published: boolean;
}

const blank = { id: null as number | null, slug: '', title: '', meta_description: '', body: '', sections: [] as SiteSection[], menu_label: '', menu_order: '' as string | number, published: false };

/**
 * clinicflow.co.za pages. Pages built from sections are edited field by
 * field; older HTML pages keep the HTML box.
 */
export default function Pages({ pages }: { pages: CmsPage[] }) {
    const [form, setForm] = useState(blank);
    const usesSections = form.sections.length > 0 || form.body === '';

    const edit = (p: CmsPage) =>
        setForm({ id: p.id, slug: p.slug, title: p.title, meta_description: p.meta_description ?? '', body: p.body, sections: p.sections ?? [], menu_label: p.menu_label ?? '', menu_order: p.menu_order ?? '', published: p.published });

    const save = () =>
        router.post(
            '/admin/pages',
            {
                ...form,
                menu_order: form.menu_order === '' ? null : Number(form.menu_order),
                sections: usesSections ? (form.sections as unknown as Record<string, string>[]) : null,
            },
            { preserveScroll: true },
        );

    return (
        <AdminShell active="Website">
            <Head title="Website" />
            <div className="mb-5 flex items-end gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">clinicflow.co.za</h1>
                    <p className="text-sm text-muted">Pages with a menu position appear in the top menu. Privacy and terms are drafts until legal approves them.</p>
                </div>
                <a href="/" target="_blank" rel="noreferrer" className="ml-auto">
                    <Button variant="secondary" icon={<ExternalLink className="size-4" />}>
                        View site
                    </Button>
                </a>
            </div>
            <Flash />
            <div className="grid grid-cols-4 gap-4">
                <Card title="Pages">
                    <ul className="space-y-1 text-sm">
                        {pages.map((p) => (
                            <li key={p.id}>
                                <button type="button" onClick={() => edit(p)} className={`flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left ${form.id === p.id ? 'bg-mint font-medium' : ''}`}>
                                    <span className="flex-1">{p.menu_label ?? p.title}</span>
                                    {!p.published && <Badge>Draft</Badge>}
                                </button>
                            </li>
                        ))}
                    </ul>
                    <Button size="sm" variant="ghost" className="mt-2" onClick={() => setForm({ ...blank, sections: [{ type: 'hero', heading: 'New page' }] })}>
                        New page
                    </Button>
                </Card>
                <Card title={form.id ? `Edit: ${form.title}` : 'New page'} className="col-span-3">
                    <div className="mb-4 grid grid-cols-3 gap-3">
                        <Field label="Address (/pages/…)" name="slug" value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} />
                        <Field label="Title" name="title" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
                        <Field label="Search description" name="meta_description" value={form.meta_description} onChange={(e) => setForm({ ...form, meta_description: e.target.value })} />
                        <Field label="Menu label" name="menu_label" value={form.menu_label} onChange={(e) => setForm({ ...form, menu_label: e.target.value })} />
                        <Field label="Menu position (empty = not in menu)" name="menu_order" value={String(form.menu_order)} onChange={(e) => setForm({ ...form, menu_order: e.target.value })} />
                        <label className="flex items-end gap-2 pb-2 text-sm">
                            <input type="checkbox" className="accent-teal" checked={form.published} onChange={(e) => setForm({ ...form, published: e.target.checked })} />
                            Published
                        </label>
                    </div>
                    {usesSections ? (
                        <SectionsEditor sections={form.sections} onChange={(sections) => setForm({ ...form, sections })} />
                    ) : (
                        <label className="block text-xs font-medium">
                            Page HTML
                            <textarea className="min-h-64 w-full rounded-md border border-line px-2.5 py-1.5 font-mono text-xs" value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} />
                        </label>
                    )}
                    <Button className="mt-4" onClick={save}>
                        Save page
                    </Button>
                </Card>
            </div>
        </AdminShell>
    );
}
