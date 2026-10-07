import { Head, router } from "@inertiajs/react";
import { useLiveReload } from "@/lib/realtime";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card, Ticket } from "@/components/ui";
import { PortalLayout } from "@/layouts/PortalLayout";
import { rand } from "@/lib/money";

interface Props {
    provider: string;
    profiles: { id: string; name: string; age: number; current: boolean }[];
    patientChannel?: string | null;
    visit: {
        ticket: string;
        stage: string;
        ahead: number | null;
        collectionCode: string | null;
    } | null;
    appointments: { id: string; when: string; doctor: string }[];
    bookingDate: string;
    slots: { doctorId: number; doctor: string; times: string[] }[];
    results: {
        id: string;
        date: string | null;
        comment: string | null;
        results: {
            name: string;
            value: string | null;
            unit: string;
            reference: string | null;
            flag: string | null;
        }[];
    }[];
    invoices: {
        id: string;
        number: string;
        date: string | null;
        total: number;
        balance: number;
    }[];
    scripts: { id: string; date: string | null; version: number }[];
}

export default function PortalHome(p: Props) {
    useLiveReload(p.patientChannel ?? "", "queue.changed", 20000, [
        "visit",
        "patientChannel",
    ]);
    const [date, setDate] = useState(p.bookingDate);
    const current = p.profiles.find((x) => x.current);

    return (
        <PortalLayout provider={p.provider}>
            <Head title="My health" />
            <Flash />
            {p.profiles.length > 1 && (
                <div className="mb-4 flex flex-wrap gap-2">
                    {p.profiles.map((x) => (
                        <Button
                            key={x.id}
                            size="sm"
                            variant={x.current ? "primary" : "secondary"}
                            onClick={() => router.post(`/my/profiles/${x.id}`)}
                        >
                            {x.name} ({x.age})
                        </Button>
                    ))}
                </div>
            )}
            <h1 className="mb-4 text-2xl font-semibold">{current?.name}</h1>
            {p.visit && (
                <Card title="Today's visit" className="mb-4">
                    <div className="flex items-center gap-4">
                        <Ticket number={p.visit.ticket} size="lg" />
                        <div className="text-sm">
                            <div className="text-lg font-semibold">
                                {p.visit.stage}
                            </div>
                            {p.visit.ahead !== null && (
                                <div className="text-muted">
                                    {p.visit.ahead === 0
                                        ? "You're next"
                                        : `${p.visit.ahead} ahead of you`}
                                </div>
                            )}
                            {p.visit.collectionCode && (
                                <div className="mt-1">
                                    Medicine ready — collection code{" "}
                                    <b className="text-lg">
                                        {p.visit.collectionCode}
                                    </b>
                                </div>
                            )}
                        </div>
                    </div>
                </Card>
            )}
            <Card title="Appointments" className="mb-4">
                {p.appointments.map((a) => (
                    <div
                        key={a.id}
                        className="flex items-center gap-2 border-b border-line-soft py-2 text-sm last:border-0"
                    >
                        <span className="flex-1">
                            {a.when} · {a.doctor}
                        </span>
                        <button
                            type="button"
                            className="text-xs text-status-danger"
                            onClick={() =>
                                window.confirm("Cancel this appointment?") &&
                                router.post(`/my/appointments/${a.id}/cancel`)
                            }
                        >
                            Cancel
                        </button>
                    </div>
                ))}
                <div className="mt-3 flex items-center gap-2 text-sm">
                    <span>Book for</span>
                    <input
                        aria-label="Booking date"
                        type="date"
                        value={date}
                        onChange={(e) => {
                            setDate(e.target.value);
                            router.get(
                                "/my",
                                { date: e.target.value },
                                {
                                    preserveState: true,
                                    only: ["slots", "bookingDate"],
                                },
                            );
                        }}
                        className="rounded border border-line px-2 py-1"
                    />
                </div>
                {p.slots.length === 0 && (
                    <p className="mt-2 text-sm text-muted">
                        No open times on this day.
                    </p>
                )}
                {p.slots.map((d) => (
                    <div key={d.doctorId} className="mt-3">
                        <div className="text-sm font-medium">{d.doctor}</div>
                        <div className="mt-1 flex flex-wrap gap-1.5">
                            {d.times.map((t) => (
                                <Button
                                    key={t}
                                    size="sm"
                                    variant="secondary"
                                    onClick={() =>
                                        router.post(
                                            "/my/appointments",
                                            {
                                                staff_id: d.doctorId,
                                                starts_at: `${p.bookingDate} ${t}`,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {t}
                                </Button>
                            ))}
                        </div>
                    </div>
                ))}
            </Card>
            <Card title="Results" className="mb-4">
                {p.results.length === 0 && (
                    <p className="text-sm text-muted">No results yet.</p>
                )}
                {p.results.map((o) => (
                    <div key={o.id} className="mb-3 text-sm">
                        <div className="font-medium">{o.date}</div>
                        {o.results.map((r) => (
                            <div key={r.name} className="flex gap-2">
                                <span className="flex-1">{r.name}</span>
                                <Badge
                                    tone={
                                        r.flag === "normal"
                                            ? "success"
                                            : "warning"
                                    }
                                >
                                    {r.value} {r.unit}
                                </Badge>
                            </div>
                        ))}
                        {o.comment && (
                            <p className="mt-1 text-muted">
                                Doctor: {o.comment}
                            </p>
                        )}
                    </div>
                ))}
            </Card>
            <Card title="Invoices and scripts">
                {p.invoices.map((i) => (
                    <div
                        key={i.id}
                        className="flex items-center gap-2 border-b border-line-soft py-2 text-sm last:border-0"
                    >
                        <span className="flex-1">
                            {i.number} · {i.date}
                        </span>
                        <span>{rand(i.total, 2)}</span>
                        {i.balance > 0 ? (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(`/my/invoices/${i.id}/pay`)
                                }
                            >
                                Pay {rand(i.balance, 2)}
                            </Button>
                        ) : (
                            <Badge tone="success">Paid</Badge>
                        )}
                    </div>
                ))}
                {p.scripts.length > 0 && (
                    <p className="mt-3 text-xs text-muted">
                        Signed scripts:{" "}
                        {p.scripts
                            .map((s) => `${s.date} (v${s.version})`)
                            .join(", ")}
                    </p>
                )}
            </Card>
            <button
                type="button"
                className="mt-6 text-sm text-teal-deep"
                onClick={() => router.post("/my/logout")}
            >
                Sign out
            </button>
        </PortalLayout>
    );
}
