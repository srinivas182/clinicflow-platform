import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import type { SiteItem, SiteSection } from './types';

const input = 'w-full rounded-md border border-line px-2.5 py-1.5 text-sm';
const LABELS: Record<SiteSection['type'], string> = {
    hero: 'Banner', cards: 'Cards', features: 'Feature grid', steps: 'Steps', split: 'Image and list', faq: 'Questions', cta: 'Call to action', contact: 'Contact details', richtext: 'Text',
};

function Text({ label, value, onChange, area }: { label: string; value: string | undefined; onChange: (v: string) => void; area?: boolean }) {
    return (
        <label className="block text-xs font-medium">
            {label}
            {area ? <textarea className={`${input} min-h-16`} value={value ?? ''} onChange={(e) => onChange(e.target.value)} /> : <input className={input} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />}
        </label>
    );
}

/**
 * Edits page sections as plain fields — no HTML except the "Text" section.
 */
export function SectionsEditor({ sections, onChange }: { sections: SiteSection[]; onChange: (s: SiteSection[]) => void }) {
    const set = (i: number, patch: Partial<SiteSection>) => onChange(sections.map((s, k) => (k === i ? { ...s, ...patch } : s)));
    const setItem = (i: number, j: number, patch: Partial<SiteItem>) => set(i, { items: (sections[i]?.items ?? []).map((it, k) => (k === j ? { ...it, ...patch } : it)) });
    const move = (i: number, d: number) => {
        const next = [...sections];
        const [s] = next.splice(i, 1);
        if (s) next.splice(i + d, 0, s);
        onChange(next);
    };

    return (
        <div className="flex flex-col gap-4">
            {sections.map((s, i) => (
                <fieldset key={i} className="rounded-xl border border-line p-4">
                    <legend className="flex items-center gap-2 px-1 text-sm font-semibold">
                        {LABELS[s.type]}
                        <button type="button" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)}>
                            <ArrowUp className="size-3.5" />
                        </button>
                        <button type="button" aria-label="Move down" disabled={i === sections.length - 1} onClick={() => move(i, 1)}>
                            <ArrowDown className="size-3.5" />
                        </button>
                        <button type="button" aria-label="Remove section" onClick={() => onChange(sections.filter((_, k) => k !== i))}>
                            <Trash2 className="size-3.5 text-status-danger" />
                        </button>
                    </legend>
                    <div className="grid gap-2 md:grid-cols-2">
                        {s.type !== 'richtext' && <Text label="Heading" value={s.heading} onChange={(v) => set(i, { heading: v })} />}
                        {['hero'].includes(s.type) && <Text label="Small label above heading" value={s.eyebrow} onChange={(v) => set(i, { eyebrow: v })} />}
                        {['hero', 'split', 'cta'].includes(s.type) && <Text label="Text" area value={s.text} onChange={(v) => set(i, { text: v })} />}
                        {['hero', 'split'].includes(s.type) && <Text label="Image (/images/site/… or https://…)" value={s.image} onChange={(v) => set(i, { image: v })} />}
                        {s.type === 'contact' && <Text label="Note" value={s.note} onChange={(v) => set(i, { note: v })} />}
                        {['hero', 'cta'].includes(s.type) &&
                            (['primary', 'secondary'] as const).map((b) => (
                                <div key={b} className="grid grid-cols-2 gap-2">
                                    <Text label={`${b === 'primary' ? 'Main' : 'Second'} button label`} value={s[b]?.label} onChange={(v) => set(i, { [b]: { label: v, href: s[b]?.href ?? '/' } })} />
                                    <Text label="Link" value={s[b]?.href} onChange={(v) => set(i, { [b]: { label: s[b]?.label ?? '', href: v } })} />
                                </div>
                            ))}
                        {s.type === 'richtext' && (
                            <div className="md:col-span-2">
                                <Text label="Text (simple HTML)" area value={s.html} onChange={(v) => set(i, { html: v })} />
                            </div>
                        )}
                    </div>
                    {s.type === 'split' && <Text label="List (one per line)" area value={(s.bullets ?? []).join('\n')} onChange={(v) => set(i, { bullets: v.split('\n').filter((x) => x.trim() !== '') })} />}
                    {['cards', 'features', 'steps', 'faq'].includes(s.type) && (
                        <div className="mt-3 flex flex-col gap-2">
                            {(s.items ?? []).map((it, j) => (
                                <div key={j} className="grid items-end gap-2 rounded-lg bg-paper p-2 md:grid-cols-[1fr_2fr_auto]">
                                    {s.type === 'faq' ? (
                                        <>
                                            <Text label="Question" value={it.question} onChange={(v) => setItem(i, j, { question: v })} />
                                            <Text label="Answer" value={it.answer} onChange={(v) => setItem(i, j, { answer: v })} />
                                        </>
                                    ) : (
                                        <>
                                            <Text label="Title" value={it.title} onChange={(v) => setItem(i, j, { title: v })} />
                                            <Text label="Text" value={it.text} onChange={(v) => setItem(i, j, { text: v })} />
                                        </>
                                    )}
                                    <button type="button" aria-label="Remove item" className="pb-2" onClick={() => set(i, { items: (s.items ?? []).filter((_, k) => k !== j) })}>
                                        <Trash2 className="size-4 text-muted" />
                                    </button>
                                </div>
                            ))}
                            <button type="button" className="flex items-center gap-1 self-start text-sm text-teal-deep" onClick={() => set(i, { items: [...(s.items ?? []), {}] })}>
                                <Plus className="size-4" /> Add item
                            </button>
                        </div>
                    )}
                </fieldset>
            ))}
            <label className="flex items-center gap-2 text-sm">
                Add section
                <select
                    className="rounded-md border border-line px-2 py-1"
                    value=""
                    onChange={(e) => {
                        const type = e.target.value as SiteSection['type'];
                        if (type) onChange([...sections, { type, heading: '', items: [] }]);
                    }}
                >
                    <option value="">Choose…</option>
                    {(Object.keys(LABELS) as SiteSection['type'][]).map((t) => (
                        <option key={t} value={t}>
                            {LABELS[t]}
                        </option>
                    ))}
                </select>
            </label>
        </div>
    );
}
