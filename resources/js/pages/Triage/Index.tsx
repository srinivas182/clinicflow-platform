import { Head, Link, usePoll } from '@inertiajs/react';
import { Flash } from '@/components/Flash';
import { Button, Card, Ticket } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';

export default function TriageIndex({ visits }: { visits: { id: string; ticket: string; patient: string; age: number; minutes: number }[] }) {
    usePoll(15000);

    return (
        <AppShell active="Triage">
            <Head title="Triage" />
            <h1 className="text-2xl font-semibold">Triage queue</h1>
            <p className="mb-5 text-sm text-muted">Oldest first. BP, pulse, temperature and colour are required before a patient reaches a doctor.</p>
            <Flash />
            <Card>
                {visits.length === 0 && <p className="text-sm text-muted">Nobody is waiting for triage.</p>}
                <ul className="divide-y divide-[#EBF0EE]">
                    {visits.map((v) => (
                        <li key={v.id} className="flex items-center gap-4 py-3">
                            <Ticket number={v.ticket} />
                            <span className="flex-1">
                                <span className="font-medium">{v.patient}</span>
                                <span className="ml-2 text-sm text-muted">{v.age} yrs</span>
                            </span>
                            <span className={`text-sm ${v.minutes >= 30 ? 'font-semibold text-status-danger' : 'text-muted'}`}>{v.minutes} min</span>
                            <Link href={`/triage/${v.id}`}>
                                <Button size="sm">Triage</Button>
                            </Link>
                        </li>
                    ))}
                </ul>
            </Card>
        </AppShell>
    );
}
