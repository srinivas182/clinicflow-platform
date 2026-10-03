import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, FileText, Lock, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Flash } from '@/components/Flash';
import { Badge, Button, Card, Ticket } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';

interface Diagnosis {
    code: string;
    description: string;
    primary: boolean;
}

type Item = {
    id?: number;
    medicine_id: number;
    description: string;
    schedule?: string;
    dose: string;
    quantity: number;
    repeats: number;
    override_reason: string | null;
};

interface Script {
    id: string;
    version: number;
    status: string;
    signedAt: string | null;
    dispensable: boolean;
    changeReason: string | null;
    items: Item[];
}

interface Issue {
    itemId: number;
    type: string;
    level: 'block' | 'override';
    message: string;
}

interface Props {
    visit: { id: string; ticket: string; stage: string };
    patient: { name: string; age: number; medicalAid: string | null; allergies: string[] };
    triage: { bp_systolic: number; bp_diastolic: number; pulse: number; temperature: string; colour: string; complaint: string } | null;
    consultation: { id: string; subjective: string | null; objective: string | null; assessment: string | null; plan: string | null; lockVersion: number; completed: boolean; diagnoses: Diagnosis[] };
    prescriptions: Script[];
    safety: Issue[];
    labTests: { code: string; name: string; price_cents: number }[];
}

function useSearch<T>(url: string, q: string): T[] {
    const [rows, setRows] = useState<T[]>([]);
    useEffect(() => {
        if (q.trim().length < 2) {
            setRows([]);
            return;
        }
        const t = setTimeout(() => {
            fetch(`${url}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((d: T[]) => setRows(d))
                .catch(() => setRows([]));
        }, 250);
        return () => clearTimeout(t);
    }, [url, q]);
    return rows;
}

export default function ConsultShow({ visit, patient, triage, consultation, prescriptions, safety, labTests }: Props) {
    const [tests, setTests] = useState<string[]>([]);
    const notes = useForm({
        subjective: consultation.subjective ?? '',
        objective: consultation.objective ?? '',
        assessment: consultation.assessment ?? '',
        plan: consultation.plan ?? '',
        diagnoses: consultation.diagnoses,
        lock_version: consultation.lockVersion,
    });
    const draft = prescriptions.find((p) => p.status === 'draft');
    const current = [...prescriptions].reverse().find((p) => p.status === 'signed');
    const [items, setItems] = useState<Item[]>(draft?.items ?? []);
    const [icdQ, setIcdQ] = useState('');
    const [medQ, setMedQ] = useState('');
    const [pin, setPin] = useState('');
    const icd = useSearch<{ code: string; description: string; valid_primary: boolean }>('/reference/icd10', icdQ);
    const meds = useSearch<{ id: number; name: string; strength: string; form: string; schedule: string; default_dose: string | null }>('/reference/medicines', medQ);
    const locked = consultation.completed;
    const area = 'min-h-20 w-full rounded-lg border border-[#CBD5D2] px-3 py-2 text-sm disabled:bg-paper';
    const issuesFor = (id?: number) => safety.filter((i) => i.itemId === id);
    const blocked = safety.some((i) => i.level === 'block');

    const saveScript = () => router.post(`/consultations/${consultation.id}/prescription`, { items }, { preserveScroll: true });

    return (
        <AppShell active="Overview">
            <Head title={`Consult · ${patient.name}`} />
            <div className="mb-4 flex items-center gap-4">
                <Ticket number={visit.ticket} />
                <div>
                    <h1 className="text-2xl font-semibold">{patient.name}</h1>
                    <p className="text-sm text-muted">
                        {patient.age} years · {patient.medicalAid ?? 'Cash'}
                        {triage && ` · BP ${triage.bp_systolic}/${triage.bp_diastolic} · pulse ${triage.pulse} · ${triage.temperature} °C · ${triage.complaint}`}
                    </p>
                </div>
                <div className="ml-auto flex flex-wrap gap-1.5">
                    {patient.allergies.length === 0 ? <Badge>No known allergies</Badge> : patient.allergies.map((a) => <Badge key={a} tone="danger" icon={<AlertTriangle className="size-3" />}>{a}</Badge>)}
                </div>
            </div>
            <Flash />
            <div className="grid grid-cols-5 gap-4">
                <Card title="Notes" className="col-span-3" aside={locked ? 'Completed' : 'Saved with conflict protection'}>
                    <div className="grid grid-cols-2 gap-3">
                        {(['subjective', 'objective', 'assessment', 'plan'] as const).map((f) => (
                            <label key={f} className="text-xs font-medium capitalize">
                                {f}
                                <textarea className={area} disabled={locked} value={notes.data[f]} onChange={(e) => notes.setData(f, e.target.value)} />
                            </label>
                        ))}
                    </div>
                    <div className="mt-4 text-xs font-medium">Diagnoses (ICD-10)</div>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {notes.data.diagnoses.map((d, i) => (
                            <span key={d.code} className="inline-flex items-center gap-2 rounded-lg border border-line px-2 py-1 text-sm">
                                <b>{d.code}</b> {d.description}
                                {!locked && (
                                    <>
                                        <label className="text-xs">
                                            <input type="radio" name="primary" checked={d.primary} onChange={() => notes.setData('diagnoses', notes.data.diagnoses.map((x, j) => ({ ...x, primary: i === j })))} /> primary
                                        </label>
                                        <button type="button" aria-label="Remove" onClick={() => notes.setData('diagnoses', notes.data.diagnoses.filter((_, j) => j !== i))}>
                                            <Trash2 className="size-3.5 text-muted" />
                                        </button>
                                    </>
                                )}
                            </span>
                        ))}
                    </div>
                    {!locked && (
                        <div className="relative mt-2">
                            <input value={icdQ} onChange={(e) => setIcdQ(e.target.value)} placeholder="Search ICD-10 code or description" className="w-full rounded-lg border border-line px-3 py-2 text-sm" />
                            {icd.length > 0 && (
                                <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-line bg-white text-sm shadow">
                                    {icd.map((c) => (
                                        <li key={c.code}>
                                            <button
                                                type="button"
                                                className="w-full px-3 py-2 text-left hover:bg-mint"
                                                onClick={() => {
                                                    notes.setData('diagnoses', [...notes.data.diagnoses, { code: c.code, description: c.description, primary: notes.data.diagnoses.length === 0 && c.valid_primary }]);
                                                    setIcdQ('');
                                                }}
                                            >
                                                <b>{c.code}</b> {c.description}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                    {notes.errors.diagnoses && <p role="alert" className="mt-2 text-xs text-status-danger">{notes.errors.diagnoses}</p>}
                    {!locked && (
                        <div className="mt-4 flex gap-2">
                            <Button variant="secondary" onClick={() => notes.put(`/consultations/${consultation.id}`, { preserveScroll: true })}>
                                Save notes
                            </Button>
                            <Button onClick={() => router.post(`/consultations/${consultation.id}/complete`)}>Complete consult</Button>
                        </div>
                    )}
                </Card>
                <Card title="Prescription" className="col-span-2" aside={current ? `v${current.version} signed ${current.signedAt}` : draft ? `v${draft.version} draft` : 'None yet'}>
                    {current && !draft && (
                        <div className="mb-3 flex gap-2">
                            <a href={`/prescriptions/${current.id}/pdf`} target="_blank" rel="noreferrer">
                                <Button size="sm" variant="secondary" icon={<FileText className="size-3.5" />}>
                                    View PDF
                                </Button>
                            </a>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    const reason = window.prompt('Why does the script need to change?');
                                    if (reason) router.post(`/prescriptions/${current.id}/amend`, { reason }, { preserveScroll: true });
                                }}
                            >
                                Change (new version)
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={async () => {
                                    const q = window.prompt('Patient\'s chosen pharmacy (name)?');
                                    if (!q) return;
                                    const list: { id: string; name: string }[] = await fetch(`/reference/pharmacies?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } }).then((r) => r.json());
                                    if (list.length === 0) {
                                        window.alert('No network pharmacy matches that name.');
                                        return;
                                    }
                                    const pick = list.length === 1 ? list[0] : list[Number(window.prompt(list.map((p, i) => `${i + 1}. ${p.name}`).join('\n') + '\nNumber?')) - 1];
                                    if (pick && window.confirm(`Send this script to ${pick.name}?`)) router.post(`/prescriptions/${current.id}/escript`, { pharmacy_id: pick.id }, { preserveScroll: true });
                                }}
                            >
                                Send e-script
                            </Button>
                        </div>
                    )}
                    {(draft || !current) && !locked && (
                        <>
                            <ul className="space-y-3">
                                {items.map((it, i) => (
                                    <li key={i} className="rounded-lg border border-line p-2.5 text-sm">
                                        <div className="flex items-center gap-2">
                                            <b className="flex-1">{it.description}</b>
                                            {it.schedule && <Badge>{it.schedule}</Badge>}
                                            <button type="button" aria-label="Remove medicine" onClick={() => setItems(items.filter((_, j) => j !== i))}>
                                                <Trash2 className="size-3.5 text-muted" />
                                            </button>
                                        </div>
                                        <div className="mt-2 grid grid-cols-4 gap-2">
                                            <input aria-label="Dose" className="col-span-2 rounded border border-line px-2 py-1" value={it.dose} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, dose: e.target.value } : x)))} />
                                            <input aria-label="Quantity" type="number" className="rounded border border-line px-2 py-1" value={it.quantity} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, quantity: Number(e.target.value) } : x)))} />
                                            <input aria-label="Repeats" type="number" className="rounded border border-line px-2 py-1" value={it.repeats} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, repeats: Number(e.target.value) } : x)))} />
                                        </div>
                                        {issuesFor(it.id).map((issue, k) => (
                                            <div key={k} className={`mt-2 rounded-md px-2 py-1.5 text-xs ${issue.level === 'block' ? 'bg-status-danger-wash text-status-danger' : 'bg-status-warning-wash text-status-warning'}`}>
                                                {issue.message}
                                                {issue.level === 'override' && (
                                                    <input
                                                        className="mt-1 w-full rounded border border-line bg-white px-2 py-1 text-ink"
                                                        placeholder="Reason to prescribe anyway"
                                                        value={it.override_reason ?? ''}
                                                        onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, override_reason: e.target.value } : x)))}
                                                    />
                                                )}
                                            </div>
                                        ))}
                                    </li>
                                ))}
                            </ul>
                            <div className="relative mt-3">
                                <input value={medQ} onChange={(e) => setMedQ(e.target.value)} placeholder="Add medicine" className="w-full rounded-lg border border-line px-3 py-2 text-sm" />
                                {meds.length > 0 && (
                                    <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-line bg-white text-sm shadow">
                                        {meds.map((m) => (
                                            <li key={m.id}>
                                                <button
                                                    type="button"
                                                    className="flex w-full gap-2 px-3 py-2 text-left hover:bg-mint"
                                                    onClick={() => {
                                                        setItems([...items, { medicine_id: m.id, description: `${m.name} ${m.strength} ${m.form}`, schedule: m.schedule, dose: m.default_dose ?? '', quantity: 1, repeats: 0, override_reason: null }]);
                                                        setMedQ('');
                                                    }}
                                                >
                                                    <Plus className="size-4 text-teal" /> {m.name} {m.strength} <span className="ml-auto text-xs text-muted">{m.schedule}</span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                            <div className="mt-3 flex gap-2">
                                <Button variant="secondary" disabled={items.length === 0} onClick={saveScript}>
                                    Save &amp; check safety
                                </Button>
                            </div>
                            {draft && (
                                <div className="mt-4 rounded-lg border border-line bg-paper p-3 text-sm">
                                    <div className="flex items-center gap-2 font-medium">
                                        <Lock className="size-4" /> Sign version {draft.version}
                                    </div>
                                    {blocked ? (
                                        <p className="mt-1 text-xs text-status-danger">Fix the red warnings before signing.</p>
                                    ) : (
                                        <div className="mt-2 flex gap-2">
                                            <Button size="sm" variant="secondary" onClick={() => router.post(`/prescriptions/${draft.id}/pin`, {}, { preserveScroll: true })}>
                                                Send PIN
                                            </Button>
                                            <input aria-label="Signing PIN" inputMode="numeric" maxLength={6} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))} className="w-24 rounded border border-line px-2 py-1" />
                                            <Button size="sm" disabled={pin.length !== 6} onClick={() => router.post(`/prescriptions/${draft.id}/sign`, { pin }, { preserveScroll: true, onSuccess: () => setPin('') })}>
                                                Sign
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            )}
                        </>
                    )}
                    {prescriptions.length > 1 && (
                        <ul className="mt-4 space-y-1 text-xs text-muted">
                            {prescriptions.map((p) => (
                                <li key={p.id}>
                                    v{p.version} · {p.status}
                                    {p.dispensable && ' · dispensable'}
                                    {p.changeReason && ` · ${p.changeReason}`}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
                        </div>
            {!locked && (
                <Card title="Order lab tests" className="mt-4">
                    <div className="flex flex-wrap gap-3 text-sm">
                        {labTests.map((t) => (
                            <label key={t.code} className="flex items-center gap-1.5">
                                <input type="checkbox" className="accent-teal" checked={tests.includes(t.code)} onChange={(e) => setTests(e.target.checked ? [...tests, t.code] : tests.filter((c) => c !== t.code))} />
                                {t.name}
                            </label>
                        ))}
                    </div>
                    <div className="mt-3 flex gap-2">
                        <Button size="sm" variant="secondary" disabled={tests.length === 0} onClick={() => router.post(`/visits/${visit.id}/lab-orders`, { tests }, { preserveScroll: true, onSuccess: () => setTests([]) })}>
                            In-house lab: order {tests.length || ''}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={async () => {
                                const q = window.prompt('Network lab (name)?');
                                if (!q) return;
                                const labs: { id: string; name: string }[] = await fetch(`/reference/labs?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } }).then((r) => r.json());
                                const lab = labs[0];
                                if (!lab) return window.alert('No network lab matches that name.');
                                const menu: { code: string; name: string; price: number }[] = await fetch(`/reference/labs/${lab.id}/menu`, { headers: { Accept: 'application/json' } }).then((r) => r.json());
                                const codes = window.prompt(`${lab.name} tests:\n${menu.map((m) => `${m.code} — ${m.name} (R${m.price})`).join('\n')}\n\nCodes, comma separated:`);
                                if (!codes) return;
                                const address = window.prompt('Home collection address (leave empty for walk-in)') ?? '';
                                const home = address ? { address, date: window.prompt('Collection date (YYYY-MM-DD)') ?? '', window: window.prompt('Time window (e.g. 08:00–10:00)') ?? '' } : null;
                                router.post(`/visits/${visit.id}/network-lab-orders`, { lab_id: lab.id, tests: codes.split(',').map((c) => c.trim().toUpperCase()), home }, { preserveScroll: true });
                            }}
                        >
                            Send to a network lab
                        </Button>
                    </div>
                </Card>
            )}
        </AppShell>
    );
}
