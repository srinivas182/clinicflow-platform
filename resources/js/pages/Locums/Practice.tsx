import { Head, router, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";

interface Props {
    shifts: {
        id: number;
        title: string;
        starts: string;
        ends: string;
        rate: number;
        basis: string;
        status: string;
        applications: {
            id: number;
            status: string;
            message: string | null;
            name: string;
            qualifications: string;
            languages: string[];
            hpcsa: string;
        }[];
    }[];
    locums: {
        id: number;
        name: string;
        qualifications: string;
        areas: string[];
        languages: string[];
    }[];
    branches: { id: number; name: string }[];
}

export default function LocumsPractice({ shifts, locums, branches }: Props) {
    const form = useForm({
        title: "GP locum",
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
            {shifts.map((s) => (
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
                </Card>
            ))}
        </AppShell>
    );
}
