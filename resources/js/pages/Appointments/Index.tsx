import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card, type BadgeTone } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Appt {
    id: string;
    patient: string;
    startsAt: string;
    status: string;
    reason: string | null;
}

interface Doctor {
    id: number;
    name: string;
    appointments: Appt[];
    freeSlots: string[];
}

const tone: Record<string, BadgeTone> = {
    booked: "teal",
    checked_in: "network",
    completed: "success",
    cancelled: "neutral",
    no_show: "warning",
};

export default function AppointmentsIndex({
    date,
    doctors,
    canBook,
}: {
    date: string;
    doctors: Doctor[];
    canBook: boolean;
}) {
    const [patientId, setPatientId] = useState("");

    const book = (doctorId: number, time: string) =>
        router.post(
            "/appointments",
            {
                patient_id: patientId,
                staff_id: doctorId,
                starts_at: `${date} ${time}`,
                consult_type: "in_person",
            },
            { preserveScroll: true },
        );

    const cancel = (id: string) => {
        const reason = window.prompt("Reason for cancelling?");
        if (reason) {
            router.post(
                `/appointments/${id}/cancel`,
                { reason },
                { preserveScroll: true },
            );
        }
    };

    return (
        <AppShell active="Appointments">
            <Head title="Appointments" />
            <div className="mb-5 flex items-end gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">Appointments</h1>
                    <p className="text-sm text-muted">
                        Each doctor's bookings and free slots for the day.
                    </p>
                </div>
                <label className="ml-auto text-sm">
                    <span className="sr-only">Date</span>
                    <input
                        type="date"
                        value={date}
                        onChange={(e) =>
                            router.get("/appointments", {
                                date: e.target.value,
                            })
                        }
                        className="rounded-lg border border-line px-3 py-2"
                    />
                </label>
            </div>
            <Flash />
            {canBook && (
                <Card className="mb-4">
                    <label className="flex items-center gap-3 text-sm">
                        <span className="font-medium">Patient to book</span>
                        <input
                            value={patientId}
                            onChange={(e) => setPatientId(e.target.value)}
                            placeholder="Patient ID from the Patients page"
                            className="flex-1 rounded-lg border border-line px-3 py-2"
                        />
                    </label>
                    <p className="mt-1 text-xs text-muted">
                        Pick the patient, then choose a free slot. Patient
                        search inside booking arrives with the front desk in
                        Sprint 3.
                    </p>
                </Card>
            )}
            <div className="grid grid-cols-3 gap-4">
                {doctors.length === 0 && (
                    <p className="text-sm text-muted">
                        No doctors are rostered yet. Add sessions on the Rosters
                        page.
                    </p>
                )}
                {doctors.map((d) => (
                    <Card
                        key={d.id}
                        title={d.name}
                        aside={`${d.appointments.length} booked`}
                    >
                        <ul className="space-y-2 text-sm">
                            {d.appointments.map((a) => (
                                <li
                                    key={a.id}
                                    className="flex items-center gap-2"
                                >
                                    <span className="w-12 font-semibold">
                                        {a.startsAt}
                                    </span>
                                    <span className="flex-1">{a.patient}</span>
                                    <Badge tone={tone[a.status] ?? "neutral"}>
                                        {a.status.replace("_", " ")}
                                    </Badge>
                                    {canBook && a.status === "booked" && (
                                        <button
                                            type="button"
                                            className="text-xs text-status-danger"
                                            onClick={() => cancel(a.id)}
                                        >
                                            Cancel
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <div className="mt-4 text-xs font-medium text-muted">
                            Free slots
                        </div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {d.freeSlots.length === 0 && (
                                <span className="text-xs text-muted">
                                    None left today
                                </span>
                            )}
                            {d.freeSlots.map((t) => (
                                <Button
                                    key={t}
                                    size="sm"
                                    variant="secondary"
                                    disabled={!canBook || !patientId}
                                    onClick={() => book(d.id, t)}
                                >
                                    {t}
                                </Button>
                            ))}
                        </div>
                    </Card>
                ))}
            </div>
        </AppShell>
    );
}
