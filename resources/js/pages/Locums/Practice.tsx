import { Head, router, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";
import { Pager, type Paginated } from "@/components/Pager";

interface Shift {
    id: number;
    title: string;
    starts: string;
    ends: string;
    rate: number;
    basis: string;
    status: string;
    hours: string | null;
    worked: string | null;
    invoice: string | null;
    total: number | null;
    paid: boolean;
    rebook: boolean | null;
    late: boolean;
    cancelledBy: string | null;
    applications: {
        id: number;
        status: string;
        message: string | null;
        name: string;
        qualifications: string;
        languages: string[];
        hpcsa: string;
    }[];
}
interface Props {
    shifts: Paginated<Shift>;
    locums: {
        id: number;
        name: string;
        qualifications: string;
        areas: string[];
        languages: string[];
    }[];
    branches: { id: number; name: string }[];
}

function BookedActions({ s }: { s: Shift }) {
    const url = `/locums/shifts/${s.id}`;
    const post = (action: string, data: Record<string, unknown> = {}) =>
        router.post(`${url}/${action}`, data as never, {
            preserveScroll: true,
        });

    return (
        <div className="mt-2 flex flex-wrap items-center gap-2 border-t border-line-soft pt-2 text-sm">
            {s.worked && (
                <span>
                    Worked {s.worked} {s.hours && <Badge>{s.hours}</Badge>}
                </span>
            )}
            {s.hours === "submitted" && (
                <>
                    <Button size="sm" onClick={() => post("hours")}>
                        Confirm hours
                    </Button>
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() =>
                            post("hours", {
                                start: window.prompt(
                                    "Actual start (YYYY-MM-DD HH:MM)",
                                    s.starts.slice(0, 16),
                                ),
                                end: window.prompt(
                                    "Actual end (YYYY-MM-DD HH:MM)",
                                    s.ends.slice(0, 16),
                                ),
                                break_minutes: Number(
                                    window.prompt("Break (minutes)", "30") ?? 0,
                                ),
                                note: window.prompt("Reason for the change"),
                            })
                        }
                    >
                        Adjust
                    </Button>
                </>
            )}
            {s.invoice && (
                <>
                    <a
                        className="text-teal-deep"
                        href={`${url}/invoice`}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Invoice {s.invoice} · {rand(s.total ?? 0, 2)}
                    </a>
                    {s.paid ? (
                        <Badge tone="success">paid</Badge>
                    ) : (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => post("paid")}
                        >
                            Mark paid
                        </Button>
                    )}
                </>
            )}
            {new Date(s.starts) > new Date() && (
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        const reason = window.prompt(
                            "Why are you cancelling the booked locum?",
                        );
                        if (reason) post("cancel-booked", { reason });
                    }}
                >
                    Cancel booking
                </Button>
            )}
            {new Date(s.ends) < new Date() && (
                <span className="flex items-center gap-1 text-xs">
                    Would book again (private):
                    <button
                        className={
                            s.rebook === true
                                ? "font-semibold text-teal-deep"
                                : "text-muted"
                        }
                        onClick={() => post("rebook", { again: true })}
                    >
                        yes
                    </button>
                    /
                    <button
                        className={
                            s.rebook === false
                                ? "font-semibold text-status-danger"
                                : "text-muted"
                        }
                        onClick={() =>
                            post("rebook", {
                                again: false,
                                note: window.prompt("Private note (optional)"),
                            })
                        }
                    >
                        no
                    </button>
                </span>
            )}
        </div>
    );
}

export default function LocumsPractice({ shifts, locums, branches }: Props) {
    const form = useForm({
        title: "GP locum",
        area: "",
        starts_at: "",
        ends_at: "",
        rate: "",
        rate_basis: "hour",
        requirements: "",
        branch_id: "",
        invited_profile_id: "",
    });
    const input = "rounded-md border border-line px-2 py-1 text-sm";

    return (
        <AppShell active="Staff">
            <Head title="Locums" />
            <h1 className="mb-1 text-2xl font-semibold">Locums</h1>
            <p className="mb-5 text-sm text-muted">
                Post a shift or offer it to a verified locum. A booked locum can
                sign in only from 1 hour before the shift until 12 hours after.
                You pay the locum directly.
            </p>
            <Flash />
            <Card title="Post a shift" className="mb-4">
                <div className="grid grid-cols-3 gap-2">
                    <input
                        aria-label="Title"
                        className={input}
                        value={form.data.title}
                        onChange={(e) => form.setData("title", e.target.value)}
                    />
                    <input
                        aria-label="Area"
                        placeholder="Area (locums there are alerted)"
                        className={input}
                        value={form.data.area}
                        onChange={(e) => form.setData("area", e.target.value)}
                    />
                    <input
                        aria-label="Starts"
                        type="datetime-local"
                        className={input}
                        value={form.data.starts_at}
                        onChange={(e) =>
                            form.setData("starts_at", e.target.value)
                        }
                    />
                    <input
                        aria-label="Ends"
                        type="datetime-local"
                        className={input}
                        value={form.data.ends_at}
                        onChange={(e) =>
                            form.setData("ends_at", e.target.value)
                        }
                    />
                    <input
                        aria-label="Rate"
                        placeholder="Rate (R)"
                        className={input}
                        value={form.data.rate}
                        onChange={(e) => form.setData("rate", e.target.value)}
                    />
                    <select
                        aria-label="Rate basis"
                        className={input}
                        value={form.data.rate_basis}
                        onChange={(e) =>
                            form.setData("rate_basis", e.target.value)
                        }
                    >
                        <option value="hour">per hour</option>
                        <option value="shift">per shift</option>
                    </select>
                    {branches.length > 1 && (
                        <select
                            aria-label="Branch"
                            className={input}
                            value={form.data.branch_id}
                            onChange={(e) =>
                                form.setData("branch_id", e.target.value)
                            }
                        >
                            {branches.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                        </select>
                    )}
                    <select
                        aria-label="Offer to"
                        className={input}
                        value={form.data.invited_profile_id}
                        onChange={(e) =>
                            form.setData("invited_profile_id", e.target.value)
                        }
                    >
                        <option value="">Open to all verified locums</option>
                        {locums.map((l) => (
                            <option key={l.id} value={l.id}>
                                Offer to {l.name}
                            </option>
                        ))}
                    </select>
                    <input
                        aria-label="Requirements"
                        placeholder="Requirements (optional)"
                        className={`${input} col-span-2`}
                        value={form.data.requirements}
                        onChange={(e) =>
                            form.setData("requirements", e.target.value)
                        }
                    />
                </div>
                {Object.values(form.errors)[0] && (
                    <p className="mt-2 text-xs text-status-danger">
                        {Object.values(form.errors)[0]}
                    </p>
                )}
                <Button
                    className="mt-2"
                    size="sm"
                    onClick={() =>
                        form.post("/locums/shifts", {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        })
                    }
                >
                    Post shift
                </Button>
            </Card>
            {shifts.data.map((s) => (
                <Card
                    key={s.id}
                    title={`${s.title} · ${s.starts.slice(0, 16)} – ${s.ends.slice(11, 16)}`}
                    aside={
                        <Badge
                            tone={
                                s.status === "filled"
                                    ? "success"
                                    : s.status === "open"
                                      ? "warning"
                                      : "neutral"
                            }
                        >
                            {s.status} · {rand(s.rate, 2)}/{s.basis}
                        </Badge>
                    }
                    className="mb-3"
                >
                    {s.applications.length === 0 && (
                        <p className="text-sm text-muted">
                            No applications yet.
                        </p>
                    )}
                    {s.applications.map((a) => (
                        <div
                            key={a.id}
                            className="flex items-center gap-2 py-1 text-sm"
                        >
                            <span className="flex-1">
                                <b>{a.name}</b> · {a.qualifications} ·{" "}
                                {a.languages.join(", ")} · HPCSA {a.hpcsa}
                                {a.message && (
                                    <span className="block text-xs text-muted">
                                        {a.message}
                                    </span>
                                )}
                            </span>
                            {s.status === "open" && a.status === "applied" ? (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            `/locums/applications/${a.id}/accept`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Accept
                                </Button>
                            ) : (
                                <Badge>{a.status}</Badge>
                            )}
                        </div>
                    ))}
                    {s.status === "open" && (
                        <Button
                            className="mt-2"
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.post(
                                    `/locums/shifts/${s.id}/cancel`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Cancel shift
                        </Button>
                    )}
                    {s.status === "filled" && <BookedActions s={s} />}
                    {s.status === "cancelled" && s.cancelledBy && (
                        <p className="mt-2 text-xs text-muted">
                            Cancelled by the {s.cancelledBy}
                            {s.late ? " (late)" : ""}.
                        </p>
                    )}
                </Card>
            ))}
            <Pager page={shifts} />
        </AppShell>
    );
}
