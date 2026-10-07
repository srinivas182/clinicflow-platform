import { Head, Link, router } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { PortalLayout } from "@/layouts/PortalLayout";
import { rand } from "@/lib/money";

interface Props {
    providerName: string;
    enabled: boolean;
    patients: { id: string; name: string }[];
    doctors: { id: number; name: string; modes: string[] }[];
    durations: Record<string, number[]>;
    consults: {
        id: string;
        mode: string;
        at: string;
        duration: number;
        status: string;
        payment: string;
        doctor: string;
        payUrl: string | null;
    }[];
    chats: { id: number; kind: string; opens_at: string; closes_at: string }[];
    rules: { cutoffMinutes: number; followupDays: number };
}

export default function Online({
    providerName,
    enabled,
    patients,
    doctors,
    durations,
    consults,
    chats,
    rules,
}: Props) {
    const [patient, setPatient] = useState(patients[0]?.id ?? "");
    const [doctor, setDoctor] = useState<number | null>(doctors[0]?.id ?? null);
    const [mode, setMode] = useState("video");
    const [duration, setDuration] = useState(15);
    const [date, setDate] = useState(
        new Date(Date.now() + 86400000).toISOString().slice(0, 10),
    );
    const [slots, setSlots] = useState<string[]>([]);
    const [price, setPrice] = useState(0);
    const modes = doctors.find((d) => d.id === doctor)?.modes ?? [];

    useEffect(() => {
        if (!doctor) return;
        fetch(
            `/my/online/slots?staff_id=${doctor}&mode=${mode}&duration=${duration}&date=${date}`,
            { headers: { Accept: "application/json" } },
        )
            .then((r) => r.json())
            .then((d: { slots: string[]; price: number }) => {
                setSlots(d.slots);
                setPrice(d.price);
            })
            .catch(() => setSlots([]));
    }, [doctor, mode, duration, date]);

    return (
        <PortalLayout provider={providerName}>
            <Head title="Online consults" />
            <h1 className="mb-1 text-2xl font-semibold">Online consults</h1>
            <p className="mb-5 text-sm text-muted">
                Pay when you book. Free cancellation up to{" "}
                {Math.round(rules.cutoffMinutes / 60)} hours before. You can ask
                follow-up questions for {rules.followupDays} days afterwards.
            </p>
            <Flash />
            {!enabled ? (
                <p className="text-sm text-muted">
                    {providerName} does not offer online consults yet.
                </p>
            ) : (
                <Card title="Book" className="mb-4">
                    <div className="flex flex-wrap gap-2 text-sm">
                        {patients.length > 1 && (
                            <select
                                aria-label="Patient"
                                className="rounded-md border border-line px-2 py-1"
                                value={patient}
                                onChange={(e) => setPatient(e.target.value)}
                            >
                                {patients.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        <select
                            aria-label="Doctor"
                            className="rounded-md border border-line px-2 py-1"
                            value={doctor ?? ""}
                            onChange={(e) => setDoctor(Number(e.target.value))}
                        >
                            {doctors.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                </option>
                            ))}
                        </select>
                        {["video", "audio", "chat"]
                            .filter((m) => modes.includes(m))
                            .map((m) => (
                                <Button
                                    key={m}
                                    size="sm"
                                    variant={
                                        mode === m ? "primary" : "secondary"
                                    }
                                    onClick={() => setMode(m)}
                                >
                                    {m}
                                </Button>
                            ))}
                        {(durations[mode] ?? [15]).map((d) => (
                            <Button
                                key={d}
                                size="sm"
                                variant={
                                    duration === d ? "primary" : "secondary"
                                }
                                onClick={() => setDuration(d)}
                            >
                                {d} min
                            </Button>
                        ))}
                        <input
                            aria-label="Date"
                            type="date"
                            className="rounded-md border border-line px-2 py-1"
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                        />
                    </div>
                    <p className="mt-3 text-sm">
                        {mode === "video"
                            ? "Video uses about 15 MB of data per minute; audio under 1 MB."
                            : ""}{" "}
                        Price: <b>{rand(price, 2)}</b>
                    </p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {slots.length === 0 && (
                            <span className="text-sm text-muted">
                                No free times on this day.
                            </span>
                        )}
                        {slots.map((t) => (
                            <Button
                                key={t}
                                size="sm"
                                variant="secondary"
                                onClick={() =>
                                    router.post("/my/online", {
                                        patient_id: patient,
                                        staff_id: doctor,
                                        mode,
                                        duration,
                                        date,
                                        time: t,
                                    })
                                }
                            >
                                {t}
                            </Button>
                        ))}
                    </div>
                </Card>
            )}
            <Card title="Your online consults" className="mb-4">
                <ul className="divide-y divide-line-soft text-sm">
                    {consults.length === 0 && (
                        <li className="py-2 text-muted">None yet.</li>
                    )}
                    {consults.map((c) => (
                        <li
                            key={c.id}
                            className="flex flex-wrap items-center gap-2 py-2.5"
                        >
                            <span className="w-40">
                                {new Date(c.at).toLocaleString("en-ZA", {
                                    dateStyle: "medium",
                                    timeStyle: "short",
                                })}
                            </span>
                            <span className="flex-1">
                                {c.mode} · {c.duration} min · {c.doctor}
                            </span>
                            <Badge
                                tone={
                                    c.payment === "paid"
                                        ? "success"
                                        : c.payment === "pending"
                                          ? "warning"
                                          : "neutral"
                                }
                            >
                                {c.status === "cancelled"
                                    ? "cancelled"
                                    : c.payment}
                            </Badge>
                            {c.payUrl && (
                                <a href={c.payUrl}>
                                    <Button size="sm">Pay now</Button>
                                </a>
                            )}
                            {c.status === "booked" && c.payment === "paid" && (
                                <>
                                    <Link href={`/my/consults/${c.id}`}>
                                        <Button size="sm">
                                            {c.mode === "chat"
                                                ? "Open chat"
                                                : "Join"}
                                        </Button>
                                    </Link>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    "Cancel this consult?",
                                                )
                                            )
                                                router.post(
                                                    `/my/online/${c.id}/cancel`,
                                                    {
                                                        reason: "Cancelled by the patient",
                                                    },
                                                    { preserveScroll: true },
                                                );
                                        }}
                                    >
                                        Cancel
                                    </Button>
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            </Card>
            {chats.length > 0 && (
                <Card title="Chats">
                    <ul className="text-sm">
                        {chats.map((c) => (
                            <li key={c.id} className="py-1">
                                <Link
                                    href={`/my/chats/${c.id}`}
                                    className="text-teal-deep"
                                >
                                    {c.kind === "consult"
                                        ? "Chat consult"
                                        : "Follow-up questions"}{" "}
                                    — open until{" "}
                                    {c.closes_at.slice(0, 16).replace("T", " ")}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </PortalLayout>
    );
}
