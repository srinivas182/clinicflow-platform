import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Flash } from '@/components/Flash';
import { Field } from '@/components/form/Field';
import { Badge, Button, Card } from '@/components/ui';
import { AdminShell } from '@/layouts/AdminShell';

interface CmsPage {
    id: number;
    slug: string;
    title: string;
    meta_description: string | null;
    body: string;
    published: boolean;
}

export default function Pages({ pages }: { pages: CmsPage[] }) {
    const form = useForm({ id: null as number | null, slug: '', title: '', meta_description: '', body: '', published: false });
    const edit = (p: CmsPage) => form.setData({ id: p.id, slug: p.slug, title: p.title, meta_description: p.meta_description ?? '', body: p.body, published: p.published });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/admin/pages', { preserveScroll: true });
    };

    return (
        <AdminShell active="Website">
            <Head title="Website pages" />
            <h1 className="mb-1 text-2xl font-semibold">Website pages</h1>
            <p className="mb-5 text-sm text-muted">The page with address "home" replaces the default home page once published. Scripts and remote content are removed on save.</p>
            <Flash />
            <div className="grid grid-cols-3 gap-4">
                <Card title="Pages">
                    <ul className="space-y-2 text-sm">
                        {pages.map((p) => (
                            <li key={p.id} className="flex items-center gap-2">
                                <button type="button" className="flex-1 text-left text-teal-deep" onClick={() => edit(p)}>
                                    /{p.slug === 'home' ? '' : `pages/${p.slug}`} — {p.title}
                                </button>
                                <Badge tone={p.published ? 'success' : 'neutral'}>{p.published ? 'Live' : 'Draft'}</Badge>
                            </li>
                        ))}
                    </ul>
                    <Button size="sm" variant="secondary" className="mt-3" onClick={() => form.reset()}>
                        New page
                    </Button>
                </Card>
                <Card title={form.data.id ? 'Edit page' : 'New page'} className="col-span-2">
                    <form onSubmit={submit} className="flex flex-col gap-3">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Address (slug)" name="slug" value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value.toLowerCase())} error={form.errors.slug} />
                            <Field label="Title" name="title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} error={form.errors.title} />
                        </div>
                        <Field label="Search description" name="meta_description" value={form.data.meta_description} onChange={(e) => form.setData('meta_description', e.target.value)} />
                        <label className="text-xs font-medium">
                            Content (HTML)
                            <textarea className="mt-1 min-h-72 w-full rounded-lg border border-[#CBD5D2] px-3 py-2 font-mono text-xs" value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" className="accent-teal" checked={form.data.published} onChange={(e) => form.setData('published', e.target.checked)} /> Published
                        </label>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </form>
                </Card>
            </div>
        </AdminShell>
    );
}
