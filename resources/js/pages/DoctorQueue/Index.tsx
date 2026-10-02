import { Head, Link, router, usePoll } from '@inertiajs/react';
import { ArrowRight, Siren } from 'lucide-react';
import { Flash } from '@/components/Flash';
import { Badge, Button, Card, Ticket, TriageDot, type TriageColour } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';

interface Row {
    id: string;
    ticket: string;
    patient: string;
    colour: string | null;
    minutes: number;
    booked: boolean;
}

const List = ({ rows, empty }: { rows: Row[]; empty: string }) => (
    <ul className="divide-y divide-[#EBF0EE] text-sm">
        {rows.length === 0 && <li className="py-3 text-muted">{empty}</li>}
        {rows.map((r) => (
            <li key={r.id} className="flex items-center gap-3 py-2.5">
                {r.colour && <TriageDot colour={r.colour as TriageColour} />}
                <span className="w-12 font-semibold">{r.ticket}</span>
                <span className="flex-1">{r.patient}</span>
                {r.booked && <Badge tone="teal">Booked</Badge>}
                <span className="text-muted">{r.minutes} min</span>
            </li>
        ))}
    </ul>
);

export default function DoctorQueue({ doctor, current, mine, pool, redAlerts }: { doctor: string; current: { id: string; ticket: string; patient: string; colour: string | null } | null; mine: Row[]; pool: Row[]; redAlerts: Row[] }) {
    usePoll(10000);

    const move = (stage: string) => current && router.post(`/visits/${current.id}/stage`, { stage }, { preserveScroll: true });

    return (
        <AppShell active="My queue">
            <Head title="My queue" />
            <div className="mb-5 flex items-end gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">My queue</h1>
                    <p className="text-sm text-muted">{doctor} · Call next picks your bookings, then patients asking for you, then the pool by triage colour.</p>
                </div>
                <Button size="lg" className="ml-auto" icon={<ArrowRight className="size-4" />} disabled={current !== null} onClick={() => router.post('/doctor/call-next', {}, { preserveScroll: true })}>
                    Call next patient
                </Button>
            </div>
            <Flash />
            {redAlerts.map((r) => (
                <div key={r.id} role="alert" className="mb-3 flex items-center gap-3 rounded-lg border border-[#F3C7C7] bg-status-danger-wash px-4 py-3 text-status-danger">
                    <Siren className="size-5" aria-hidden="true" />
                    <span className="flex-1">
                        <b>Red triage — {r.patient} ({r.ticket}).</b> First doctor to accept takes the patient.
                    </span>
                    <Button variant="danger" size="sm" onClick={() => router.post(`/doctor/accept/${r.id}`, {}, { preserveScroll: true })}>
                        Accept
                    </Button>
                </div>
            ))}
            {current && (
                <Card title="With you now" className="mb-4">
                    <div className="flex items-center gap-4">
                        <Ticket number={current.ticket} />
                        <span className="flex-1 text-lg font-medium">{current.patient}</span>
                        <Link href={`/consults/${current.id}`}>
                            <Button>Open consult</Button>
                        </Link>
                        <Button variant="secondary" onClick={() => move('pharmacy')}>
                            Send to pharmacy
                        </Button>
                        <Button onClick={() => move('done')}>Done — no script</Button>
                    </div>
                    <p className="mt-2 text-xs text-muted">Consult notes and prescribing arrive in Sprint 5.</p>
                </Card>
            )}
            <div className="grid grid-cols-2 gap-4">
                <Card title="Waiting for you" aside={`${mine.length}`}>
                    <List rows={mine} empty="Nobody has asked for you." />
                </Card>
                <Card title="Shared pool" aside={`${pool.length}`}>
                    <List rows={pool} empty="The pool is empty." />
                </Card>
            </div>
        </AppShell>
    );
}
