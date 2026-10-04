import { Head, router, useForm } from '@inertiajs/react';
import { Flash } from '@/components/Flash';
import { Badge, Button, Card } from '@/components/ui';
import { rand } from '@/lib/money';

interface Props {
    profile: {
        hpcsa_number: string;
        qualifications: string;
        languages: string[];
        areas: string[];
        hourly_rate: number | null;
        bio: string | null;
        status: string;
        note: string | null;
        vat_number: string | null;
        alerts_email: boolean;
        alerts_sms: boolean;
    } | null;
    documents: { kind: string; filename: string; expires_on: string | null }[];
    shifts: { id: number; practice: string; title: string; starts: string; ends: string; rate: number; basis: string; requirements: string | null; invited: boolean; applied: boolean }[];
    applications: { shift: number; practice: string; title: string; starts: string; ends: string; status: string; hours: string | null; invoice: string | null; paid: boolean }[];
}

export default function LocumPortal({ profile, documents, shifts, applications }: Props) {
    const form = useForm({
        hpcsa_number: profile?.hpcsa_number ?? '',
        qualifications: profile?.qualifications ?? '',
        languages: (profile?.languages ?? ['English']).join(', '),
        areas: (profile?.areas ?? []).join(', '),
        hourly_rate: profile?.hourly_rate ? String(profile.hourly_rate) : '',
        bio: profile?.bio ?? '',
        vat_number: profile?.vat_number ?? '',
        alerts_email: profile?.alerts_email ?? true,
        alerts_sms: profile?.alerts_sms ?? false,
    });
    const doc = useForm<{ kind: string; file: File | null; expires_on: string }>({ kind: 'hpcsa', file: null, expires_on: '' });
    const input = 'mb-2 w-full rounded-md border border-line px-2 py-1 text-sm';

    return (
        <div className="mx-auto max-w-4xl px-6 py-8">
            <Head title="Locum work" />
            <h1 className="mb-1 text-2xl font-semibold">Locum work</h1>
            <p className="mb-4 text-sm text-muted">Get verified once, then apply for shifts at practices on Clinic Flow. Practices pay you directly for each shift.</p>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                <Card title="Your profile" aside={profile && <Badge tone={profile.status === 'verified' ? 'success' : profile.status === 'rejected' ? 'danger' : 'warning'}>{profile.status}</Badge>}>
                    {profile?.note && <p className="mb-2 text-xs text-muted">{profile.note}</p>}
                    <input aria-label="HPCSA number" placeholder="HPCSA number (MP…)" className={input} value={form.data.hpcsa_number} onChange={(e) => form.setData('hpcsa_number', e.target.value)} />
                    <input aria-label="Qualifications" placeholder="Qualifications, e.g. MBChB (UCT)" className={input} value={form.data.qualifications} onChange={(e) => form.setData('qualifications', e.target.value)} />
                    <input aria-label="Languages" placeholder="Languages, comma separated" className={input} value={form.data.languages} onChange={(e) => form.setData('languages', e.target.value)} />
                    <input aria-label="Areas" placeholder="Areas you work, e.g. Soweto, Sandton" className={input} value={form.data.areas} onChange={(e) => form.setData('areas', e.target.value)} />
                    <input aria-label="Hourly rate" placeholder="Preferred hourly rate (R)" className={input} value={form.data.hourly_rate} onChange={(e) => form.setData('hourly_rate', e.target.value)} />
                    <input aria-label="VAT number" placeholder="VAT number (only if VAT registered)" className={input} value={form.data.vat_number} onChange={(e) => form.setData('vat_number', e.target.value)} />
                    <label className="mb-1 flex items-center gap-2 text-sm">
                        <input type="checkbox" className="accent-teal" checked={form.data.alerts_email} onChange={(e) => form.setData('alerts_email', e.target.checked)} /> Email me new shifts in my areas
                    </label>
                    <label className="mb-2 flex items-center gap-2 text-sm">
                        <input type="checkbox" className="accent-teal" checked={form.data.alerts_sms} onChange={(e) => form.setData('alerts_sms', e.target.checked)} /> Also SMS me
                    </label>
                    {Object.values(form.errors)[0] && <p className="mb-2 text-xs text-status-danger">{Object.values(form.errors)[0]}</p>}
                    <Button
                        size="sm"
                        onClick={() => {
                            form.transform((d) => ({
                                ...d,
                                languages: d.languages.split(',').map((x) => x.trim()).filter(Boolean),
                                areas: d.areas.split(',').map((x) => x.trim()).filter(Boolean),
                                hourly_rate: d.hourly_rate || null,
                                vat_number: d.vat_number || null,
                            }));
                            form.post('/locum/profile', { preserveScroll: true });
                        }}
                    >
                        Save profile
                    </Button>
                </Card>
                <Card title="Documents">
                    <ul className="mb-3 text-sm">
                        {documents.map((d, i) => (
                            <li key={i}>
                                {d.kind} · {d.filename} {d.expires_on && <span className="text-xs text-muted">valid until {d.expires_on}</span>}
                            </li>
                        ))}
                    </ul>
                    {profile && (
                        <div className="flex flex-col gap-2 text-sm">
                            <select aria-label="Document type" className="rounded-md border border-line px-2 py-1" value={doc.data.kind} onChange={(e) => doc.setData('kind', e.target.value)}>
                                <option value="hpcsa">HPCSA registration</option>
                                <option value="indemnity">Indemnity cover</option>
                                <option value="cv">CV</option>
                            </select>
                            <input aria-label="File" type="file" accept="application/pdf,image/jpeg,image/png" onChange={(e) => doc.setData('file', e.target.files?.[0] ?? null)} />
                            {doc.data.kind !== 'cv' && <input aria-label="Valid until" type="date" className="rounded-md border border-line px-2 py-1" value={doc.data.expires_on} onChange={(e) => doc.setData('expires_on', e.target.value)} />}
                            {Object.values(doc.errors)[0] && <p className="text-xs text-status-danger">{Object.values(doc.errors)[0]}</p>}
                            <Button size="sm" disabled={!doc.data.file} onClick={() => doc.post('/locum/documents', { forceFormData: true, preserveScroll: true, onSuccess: () => doc.reset() })}>
                                Upload
                            </Button>
                        </div>
                    )}
                </Card>
            </div>
            <Card title="Open shifts" className="mt-4">
                {shifts.length === 0 && <p className="text-sm text-muted">{profile?.status === 'verified' ? 'No open shifts right now.' : 'You can apply for shifts once your profile is verified.'}</p>}
                {shifts.map((s) => (
                    <div key={s.id} className="flex items-center gap-2 border-t border-[#EBF0EE] py-2 text-sm first:border-0">
                        <span className="flex-1">
                            <b>{s.practice}</b> · {s.title} · {s.starts.slice(0, 16)} – {s.ends.slice(11, 16)} · {rand(s.rate, 2)}/{s.basis} {s.invited && <Badge tone="teal">offered to you</Badge>}
                            {s.requirements && <span className="block text-xs text-muted">{s.requirements}</span>}
                        </span>
                        {s.applied ? (
                            <Badge>applied</Badge>
                        ) : (
                            <Button size="sm" disabled={profile?.status !== 'verified'} onClick={() => router.post(`/locum/shifts/${s.id}/apply`, {}, { preserveScroll: true })}>
                                Apply
                            </Button>
                        )}
                    </div>
                ))}
            </Card>
            <Card title="Your applications and shifts" className="mt-4">
                {applications.map((a, i) => {
                    const started = new Date(a.starts) <= new Date();
                    return (
                        <div key={i} className="flex flex-wrap items-center gap-2 border-t border-[#EBF0EE] py-2 text-sm first:border-0">
                            <span className="flex-1">
                                {a.practice} · {a.title} · {a.starts.slice(0, 16)}{' '}
                                <Badge tone={a.status === 'accepted' ? 'success' : a.status === 'applied' ? 'warning' : 'neutral'}>{a.status}</Badge> {a.hours && <Badge>hours {a.hours}</Badge>}{' '}
                                {a.paid && <Badge tone="success">paid</Badge>}
                            </span>
                            {a.status === 'accepted' && started && (!a.hours || a.hours === 'submitted') && (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() =>
                                        router.post(
                                            `/locum/shifts/${a.shift}/hours`,
                                            {
                                                start: window.prompt('Started (YYYY-MM-DD HH:MM)', a.starts.slice(0, 16)) ?? '',
                                                end: window.prompt('Finished (YYYY-MM-DD HH:MM)', a.ends.slice(0, 16)) ?? '',
                                                break_minutes: Number(window.prompt('Unpaid break (minutes)', '30') ?? 0),
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {a.hours ? 'Change hours' : 'Submit hours'}
                                </Button>
                            )}
                            {a.invoice && (
                                <a className="text-xs text-teal-deep" href={`/locum/shifts/${a.shift}/invoice`} target="_blank" rel="noreferrer">
                                    Invoice {a.invoice}
                                </a>
                            )}
                            {a.status === 'accepted' && !started && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        const reason = window.prompt('Why are you cancelling?');
                                        if (reason) router.post(`/locum/shifts/${a.shift}/cancel`, { reason }, { preserveScroll: true });
                                    }}
                                >
                                    Cancel
                                </Button>
                            )}
                        </div>
                    );
                })}
            </Card>
        </div>
    );
}
