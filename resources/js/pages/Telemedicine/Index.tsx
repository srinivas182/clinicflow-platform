import { Head, Link } from '@inertiajs/react';
import { Flash } from '@/components/Flash';
import { Badge, Button, Card } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';

interface Row {
    appointmentId: string;
    patient: string;
    type: string;
    at: string;
    patientWaiting: boolean;
    status: string;
    paid: boolean;
    opensAt: string;
}

export default function TeleIndex({ consults, videoReady }: { consults: Row[]; videoReady: boolean }) {
    return (
        <AppShell active="Online consults">
            <Head title="Online consults" />
            <h1 className="mb-1 text-2xl font-semibold">Online consults</h1>
            <p className="mb-5 text-sm text-muted">You can join at the booked time (a waiting screen lets you test your devices before then). Minutes are counted only while you and the patient are both connected.</p>
            <Flash />
            {!videoReady && <p className="mb-4 rounded-lg bg-status-warning-wash px-3 py-2 text-sm text-status-warning">Video is not configured yet by Clinic Flow. Consults cannot start until it is.</p>}
            <Card>
                {consults.length === 0 && <p className="text-sm text-muted">No upcoming online consults.</p>}
                <ul className="divide-y divide-[#EBF0EE] text-sm">
                    {consults.map((c) => (
                        <li key={c.appointmentId} className="flex items-center gap-3 py-3">
                            <span className="w-36 text-muted">{c.at}</span>
                            <span className="flex-1 font-medium">{c.patient}</span>
                            <Badge>{c.type}</Badge>
                            {c.patientWaiting && <Badge tone="success">Patient waiting</Badge>}
                            {!c.paid && <Badge tone="warning">Awaiting payment</Badge>}
                            <Link href={`/telemedicine/${c.appointmentId}/call`}>
                                <Button size="sm" disabled={!videoReady || !c.paid}>
                                    Open
                                </Button>
                            </Link>
                        </li>
                    ))}
                </ul>
            </Card>
        </AppShell>
    );
}
