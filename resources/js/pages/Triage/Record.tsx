import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Flash } from '@/components/Flash';
import { Field } from '@/components/form/Field';
import { Badge, Button, Card, TriageDot, type TriageColour } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';

const colours: TriageColour[] = ['red', 'orange', 'yellow', 'green'];

export default function TriageRecord({
    visit,
    allergies,
}: {
    visit: { id: string; ticket: string; patient: string; patientId: string; age: number; stage: string };
    allergies: { id: number; substance: string; reaction: string | null }[];
}) {
    const form = useForm({ bp_systolic: '', bp_diastolic: '', pulse: '', temperature: '', spo2: '', resp_rate: '', glucose: '', weight_kg: '', pain_score: '', colour: '', notes: '' });
    const [suggestion, setSuggestion] = useState<{ colour: string; label: string; reasons: string[] } | null>(null);
    const allergy = useForm({ substance: '', reaction: '' });
    const e = form.errors as Record<string, string | undefined>;

    const suggest = async () => {
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const res = await fetch('/triage/suggest', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(form.data),
        });
        if (res.ok) {
            const s = (await res.json()) as { colour: string; label: string; reasons: string[] };
            setSuggestion(s);
            if (!form.data.colour) form.setData('colour', s.colour);
        }
    };

    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        form.post(`/triage/${visit.id}`);
    };

    const vital = (name: keyof typeof form.data, label: string, hint?: string) => (
        <Field label={label} name={name} inputMode="decimal" value={form.data[name]} onChange={(ev) => form.setData(name, ev.target.value)} onBlur={suggest} error={e[name]} hint={hint} />
    );

    return (
        <AppShell active="Triage">
            <Head title={`Triage ${visit.ticket}`} />
            <h1 className="text-2xl font-semibold">
                Triage — {visit.patient} <span className="text-base font-normal text-muted">({visit.ticket}, {visit.age} yrs)</span>
            </h1>
            <Flash />
            <form onSubmit={submit} className="mt-5 grid grid-cols-3 gap-4" noValidate>
                <Card title="Vitals" className="col-span-2">
                    <div className="grid grid-cols-4 gap-3">
                        {vital('bp_systolic', 'BP systolic *')}
                        {vital('bp_diastolic', 'BP diastolic *')}
                        {vital('pulse', 'Pulse *')}
                        {vital('temperature', 'Temperature °C *')}
                        {vital('spo2', 'SpO₂ %')}
                        {vital('resp_rate', 'Resp. rate')}
                        {vital('glucose', 'Glucose mmol/L')}
                        {vital('pain_score', 'Pain 0–10')}
                    </div>
                </Card>
                <Card title="Triage colour">
                    {suggestion && (
                        <div className="mb-3 rounded-lg bg-paper p-3 text-sm">
                            Suggested: <b>{suggestion.label}</b>
                            <ul className="mt-1 list-disc pl-4 text-xs text-muted">
                                {suggestion.reasons.map((r) => (
                                    <li key={r}>{r}</li>
                                ))}
                            </ul>
                            <p className="mt-1 text-xs text-muted">The suggestion is never applied on its own — you decide.</p>
                        </div>
                    )}
                    <div className="grid grid-cols-2 gap-2">
                        {colours.map((c) => (
                            <button
                                key={c}
                                type="button"
                                onClick={() => form.setData('colour', c)}
                                aria-pressed={form.data.colour === c}
                                className={`rounded-lg border px-3 py-2.5 text-left ${form.data.colour === c ? 'border-2 border-ink' : 'border-line'}`}
                            >
                                <TriageDot colour={c} showLabel />
                            </button>
                        ))}
                    </div>
                    {e.colour && <p className="mt-2 text-xs text-status-danger">{e.colour}</p>}
                    <Button type="submit" size="lg" className="mt-4 w-full justify-center" disabled={form.processing}>
                        Save and send to doctor queue
                    </Button>
                </Card>
                <Card title="Allergies" className="col-span-3">
                    <div className="flex flex-wrap gap-2">
                        {allergies.length === 0 && <span className="text-sm text-muted">No known allergies recorded.</span>}
                        {allergies.map((a) => (
                            <span key={a.id} className="inline-flex items-center gap-1">
                                <Badge tone="danger">{a.substance}</Badge>
                                <button
                                    type="button"
                                    className="text-xs text-muted"
                                    onClick={() => {
                                        const reason = window.prompt('Why remove this allergy? (written to the audit log)');
                                        if (reason) router.post(`/allergies/${a.id}/remove`, { reason }, { preserveScroll: true });
                                    }}
                                >
                                    remove
                                </button>
                            </span>
                        ))}
                    </div>
                    <div className="mt-3 flex items-end gap-2">
                        <Field label="Add allergy" name="substance" value={allergy.data.substance} onChange={(ev) => allergy.setData('substance', ev.target.value)} />
                        <Field label="Reaction" name="reaction" value={allergy.data.reaction} onChange={(ev) => allergy.setData('reaction', ev.target.value)} />
                        <Button variant="secondary" onClick={() => allergy.post(`/patients/${visit.patientId}/allergies`, { preserveScroll: true, onSuccess: () => allergy.reset() })}>
                            Add
                        </Button>
                    </div>
                </Card>
            </form>
        </AppShell>
    );
}
