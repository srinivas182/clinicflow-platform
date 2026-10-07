import { Head, Link, router } from "@inertiajs/react";
import { useLiveReload } from "@/lib/realtime";
import { QrCode, Search } from "lucide-react";
import { useState, type FormEvent } from "react";
import { Flash } from "@/components/Flash";
import {
    Badge,
    Button,
    Card,
    Kpi,
    Ticket,
    type BadgeTone,
} from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";

interface VisitRow {
    id: string;
    ticket: string;
    patient: string;
    stage: string;
    stageLabel: string;
    payer: string;
    minutes: number;
    invoiceId: string | null;
    balance: number;
    next: { value: string; label: string }[];
    canRemove: boolean;
}

interface Result {
    id: string;
    name: string;
    idNumber: string | null;
    medicalAid: string | null;
    appointmentId: string | null;
}

const stageTone: Record<string, BadgeTone> = {
    checked_in: "neutral",
    triage: "network",
    doctor: "teal",
    pharmacy: "warning",
    dispatch: "warning",
    done: "success",
    left: "neutral",
};

export default function FrontDesk({
    visits,
    counts,
    search,
    results,
    doctors,
    leftReasons,
}: {
    visits: VisitRow[];
    counts: { today: number; waiting: number; takings: number };
    search: string;
    results: Result[];
    doctors: { id: number; name: string }[];
    leftReasons: string[];
}) {
    useLiveReload("queue", "queue.changed", 15000, ["visits", "counts"]);
    const [term, setTerm] = useState(search);

    const find = (e: FormEvent) => {
        e.preventDefault();
        router.get("/front-desk", { search: term }, { preserveState: true });
    };

    const checkIn = (r: Result, payer: string, doctor?: string) =>
        router.post("/visits", {
            patient_id: r.id,
            payer_type: payer,
            appointment_id: r.appointmentId,
            preferred_staff_id: doctor || null,
        });

    const remove = (v: VisitRow) => {
        const reason = window.prompt(
            `Reason (${leftReasons.join(", ")})`,
            "left_before_seen",
        );
        if (reason)
            router.post(
                `/visits/${v.id}/remove`,
                { reason },
                { preserveScroll: true },
            );
    };

    return (
        <AppShell active="Front desk">
            <Head title="Front desk" />
            <div className="mb-5 flex items-end gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">Front desk</h1>
                    <p className="text-sm text-muted">
                        Search first, then check in. The queue updates every 15
                        seconds.
                    </p>
                </div>
                <Link href="/patients/register" className="ml-auto">
                    <Button variant="secondary">Register patient</Button>
                </Link>
            </div>
            <Flash />
            <div className="mb-4 flex gap-4">
                <Kpi label="Checked in today" value={String(counts.today)} />
                <Kpi label="Waiting now" value={String(counts.waiting)} />
                <Kpi label="Takings today" value={rand(counts.takings)} />
            </div>
            <Card title="Check in" className="mb-4">
                <form onSubmit={find} className="flex gap-2">
                    <label className="flex flex-1 items-center gap-2 rounded-lg border border-line px-3 py-2">
                        <Search
                            className="size-4 text-muted"
                            aria-hidden="true"
                        />
                        <span className="sr-only">Find patient</span>
                        <input
                            aria-label="Find patient"
                            value={term}
                            onChange={(e) => setTerm(e.target.value)}
                            placeholder="Name, SA ID or cell"
                            className="w-full text-sm outline-none"
                        />
                    </label>
                    <Button type="submit" variant="secondary">
                        Find
                    </Button>
                </form>
                {results.length > 0 && (
                    <ul className="mt-3 divide-y divide-line-soft text-sm">
                        {results.map((r) => (
                            <li
                                key={r.id}
                                className="flex items-center gap-3 py-2.5"
                            >
                                <span className="flex-1">
                                    <span className="font-medium">
                                        {r.name}
                                    </span>
                                    <span className="ml-2 text-xs text-muted">
                                        {r.idNumber}
                                    </span>
                                </span>
                                {r.appointmentId && (
                                    <Badge tone="teal">Booked today</Badge>
                                )}
                                <select
                                    aria-label="Doctor"
                                    id={`doc-${r.id}`}
                                    className="rounded-md border border-line px-2 py-1 text-sm"
                                >
                                    <option value="">Next available</option>
                                    {doctors.map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {d.name}
                                        </option>
                                    ))}
                                </select>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        checkIn(
                                            r,
                                            r.medicalAid
                                                ? "medical_aid"
                                                : "cash",
                                            (
                                                document.getElementById(
                                                    `doc-${r.id}`,
                                                ) as HTMLSelectElement | null
                                            )?.value,
                                        )
                                    }
                                >
                                    Check in ·{" "}
                                    {r.medicalAid ? "medical aid" : "cash"}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            <Card
                title="Today's queue"
                aside={
                    <span className="inline-flex items-center gap-1">
                        <QrCode className="size-3.5" /> kiosk check-ins appear
                        here too
                    </span>
                }
            >
                <table className="w-full text-sm">
                    <thead className="text-left text-xs text-muted">
                        <tr>
                            <th scope="col" className="py-2 font-medium">
                                Ticket
                            </th>
                            <th scope="col" className="py-2 font-medium">
                                Patient
                            </th>
                            <th scope="col" className="py-2 font-medium">
                                Stage
                            </th>
                            <th scope="col" className="py-2 font-medium">
                                Wait
                            </th>
                            <th scope="col" className="py-2 font-medium">
                                Balance
                            </th>
                            <th scope="col" />
                        </tr>
                    </thead>
                    <tbody>
                        {visits.map((v) => (
                            <tr
                                key={v.id}
                                className="border-t border-line-soft"
                            >
                                <td className="py-2.5">
                                    <Ticket number={v.ticket} />
                                </td>
                                <td className="py-2.5">
                                    <span className="font-medium">
                                        {v.patient}
                                    </span>
                                    <span className="ml-2 text-xs text-muted">
                                        {v.payer === "cash"
                                            ? "Cash"
                                            : "Medical aid"}
                                    </span>
                                </td>
                                <td className="py-2.5">
                                    <Badge
                                        tone={stageTone[v.stage] ?? "neutral"}
                                    >
                                        {v.stageLabel}
                                    </Badge>
                                </td>
                                <td
                                    className={`py-2.5 ${v.minutes >= 30 ? "font-semibold text-status-danger" : ""}`}
                                >
                                    {v.minutes} min
                                </td>
                                <td className="py-2.5">
                                    {v.invoiceId ? (
                                        <Link
                                            href={`/invoices/${v.invoiceId}`}
                                            className="text-teal-deep"
                                        >
                                            {v.balance > 0
                                                ? rand(v.balance, 2)
                                                : "Paid"}
                                        </Link>
                                    ) : (
                                        "—"
                                    )}
                                </td>
                                <td className="py-2.5 text-right">
                                    <span className="inline-flex gap-1.5">
                                        {v.next.map((n) => (
                                            <Button
                                                key={n.value}
                                                size="sm"
                                                variant="secondary"
                                                onClick={() =>
                                                    router.post(
                                                        `/visits/${v.id}/stage`,
                                                        { stage: n.value },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {n.label}
                                            </Button>
                                        ))}
                                        {v.next.some(
                                            (n) => n.value === "done",
                                        ) &&
                                            v.balance > 0 && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => {
                                                        const reason =
                                                            window.prompt(
                                                                "Discharge without full payment — reason (owner or manager only)?",
                                                            );
                                                        if (reason)
                                                            router.post(
                                                                `/visits/${v.id}/stage`,
                                                                {
                                                                    stage: "done",
                                                                    override_reason:
                                                                        reason,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                    }}
                                                >
                                                    Override
                                                </Button>
                                            )}
                                        {v.canRemove && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => remove(v)}
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </AppShell>
    );
}
