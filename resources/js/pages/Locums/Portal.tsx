import { Head, router, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { rand } from "@/lib/money";

interface Props {
    profile: {
        hpcsa_number: string;
        qualifications: string;
        languages: string[];
        areas: string[];
        hourly_rate: number | null;
        bio: string | null;
        status: string;
        note: string | null;
    } | null;
    documents: { kind: string; filename: string; expires_on: string | null }[];
    shifts: {
        id: number;
        practice: string;
        title: string;
        starts: string;
        ends: string;
        rate: number;
        basis: string;
        requirements: string | null;
        invited: boolean;
        applied: boolean;
    }[];
    applications: {
        practice: string;
        title: string;
        starts: string;
        status: string;
    }[];
}

export default function LocumPortal({
    profile,
    documents,
    shifts,
    applications,
}: Props) {
    const form = useForm({
        hpcsa_number: profile?.hpcsa_number ?? "",
        qualifications: profile?.qualifications ?? "",
        languages: (profile?.languages ?? ["English"]).join(", "),
        areas: (profile?.areas ?? []).join(", "),
        hourly_rate: profile?.hourly_rate ? String(profile.hourly_rate) : "",
        bio: profile?.bio ?? "",
    });
    const doc = useForm<{
        kind: string;
        file: File | null;
        expires_on: string;
    }>({ kind: "hpcsa", file: null, expires_on: "" });
    const input = "mb-2 w-full rounded-md border border-line px-2 py-1 text-sm";

    return (
        <div className="mx-auto max-w-4xl px-6 py-8">
            <Head title="Locum work" />
            <h1 className="mb-1 text-2xl font-semibold">Locum work</h1>
            <p className="mb-4 text-sm text-muted">
                Get verified once, then apply for shifts at practices on Clinic
                Flow. Practices pay you directly for each shift.
            </p>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                <Card
                    title="Your profile"
                    aside={
                        profile && (
                            <Badge
                                tone={
                                    profile.status === "verified"
                                        ? "success"
                                        : profile.status === "rejected"
                                          ? "danger"
                                          : "warning"
                                }
                            >
                                {profile.status}
                            </Badge>
                        )
                    }
                >
                    {profile?.note && (
                        <p className="mb-2 text-xs text-muted">
                            {profile.note}
                        </p>
                    )}
                    <input
                        aria-label="HPCSA number"
                        placeholder="HPCSA number (MP…)"
                        className={input}
                        value={form.data.hpcsa_number}
                        onChange={(e) =>
                            form.setData("hpcsa_number", e.target.value)
                        }
                    />
                    <input
                        aria-label="Qualifications"
                        placeholder="Qualifications, e.g. MBChB (UCT)"
                        className={input}
                        value={form.data.qualifications}
                        onChange={(e) =>
                            form.setData("qualifications", e.target.value)
                        }
                    />
                    <input
                        aria-label="Languages"
                        placeholder="Languages, comma separated"
                        className={input}
                        value={form.data.languages}
                        onChange={(e) =>
                            form.setData("languages", e.target.value)
                        }
                    />
                    <input
                        aria-label="Areas"
                        placeholder="Areas you work, e.g. Soweto, Sandton"
                        className={input}
                        value={form.data.areas}
                        onChange={(e) => form.setData("areas", e.target.value)}
                    />
                    <input
                        aria-label="Hourly rate"
                        placeholder="Preferred hourly rate (R)"
                        className={input}
                        value={form.data.hourly_rate}
                        onChange={(e) =>
                            form.setData("hourly_rate", e.target.value)
                        }
                    />
                    {Object.values(form.errors)[0] && (
                        <p className="mb-2 text-xs text-status-danger">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                    <Button
                        size="sm"
                        onClick={() => {
                            form.transform((d) => ({
                                ...d,
                                languages: d.languages
                                    .split(",")
                                    .map((x) => x.trim())
                                    .filter(Boolean),
                                areas: d.areas
                                    .split(",")
                                    .map((x) => x.trim())
                                    .filter(Boolean),
                                hourly_rate: d.hourly_rate || null,
                            }));
                            form.post("/locum/profile", {
                                preserveScroll: true,
                            });
                        }}
                    >
                        Save profile
                    </Button>
                </Card>
                <Card title="Documents">
                    <ul className="mb-3 text-sm">
                        {documents.map((d, i) => (
                            <li key={i}>
                                {d.kind} · {d.filename}{" "}
                                {d.expires_on && (
                                    <span className="text-xs text-muted">
                                        valid until {d.expires_on}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                    {profile && (
                        <div className="flex flex-col gap-2 text-sm">
                            <select
                                aria-label="Document type"
                                className="rounded-md border border-line px-2 py-1"
                                value={doc.data.kind}
                                onChange={(e) =>
                                    doc.setData("kind", e.target.value)
                                }
                            >
                                <option value="hpcsa">
                                    HPCSA registration
                                </option>
                                <option value="indemnity">
                                    Indemnity cover
                                </option>
                                <option value="cv">CV</option>
                            </select>
                            <input
                                aria-label="File"
                                type="file"
                                accept="application/pdf,image/jpeg,image/png"
                                onChange={(e) =>
                                    doc.setData(
                                        "file",
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            {doc.data.kind !== "cv" && (
                                <input
                                    aria-label="Valid until"
                                    type="date"
                                    className="rounded-md border border-line px-2 py-1"
                                    value={doc.data.expires_on}
                                    onChange={(e) =>
                                        doc.setData(
                                            "expires_on",
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                            {Object.values(doc.errors)[0] && (
                                <p className="text-xs text-status-danger">
                                    {Object.values(doc.errors)[0]}
                                </p>
                            )}
                            <Button
                                size="sm"
                                disabled={!doc.data.file}
                                onClick={() =>
                                    doc.post("/locum/documents", {
                                        forceFormData: true,
                                        preserveScroll: true,
                                        onSuccess: () => doc.reset(),
                                    })
                                }
                            >
                                Upload
                            </Button>
                        </div>
                    )}
                </Card>
            </div>
            <Card title="Open shifts" className="mt-4">
                {shifts.length === 0 && (
                    <p className="text-sm text-muted">
                        {profile?.status === "verified"
                            ? "No open shifts right now."
                            : "Shifts appear once your profile exists; you can apply once verified."}
                    </p>
                )}
                {shifts.map((s) => (
                    <div
                        key={s.id}
                        className="flex items-center gap-2 border-t border-[#EBF0EE] py-2 text-sm first:border-0"
                    >
                        <span className="flex-1">
                            <b>{s.practice}</b> · {s.title} ·{" "}
                            {s.starts.slice(0, 16)} – {s.ends.slice(11, 16)} ·{" "}
                            {rand(s.rate, 2)}/{s.basis}{" "}
                            {s.invited && (
                                <Badge tone="teal">offered to you</Badge>
                            )}
                            {s.requirements && (
                                <span className="block text-xs text-muted">
                                    {s.requirements}
                                </span>
                            )}
                        </span>
                        {s.applied ? (
                            <Badge>applied</Badge>
                        ) : (
                            <Button
                                size="sm"
                                disabled={profile?.status !== "verified"}
                                onClick={() =>
                                    router.post(
                                        `/locum/shifts/${s.id}/apply`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Apply
                            </Button>
                        )}
                    </div>
                ))}
            </Card>
            <Card title="Your applications" className="mt-4">
                {applications.map((a, i) => (
                    <p key={i} className="text-sm">
                        {a.practice} · {a.title} · {a.starts.slice(0, 16)}{" "}
                        <Badge
                            tone={
                                a.status === "accepted"
                                    ? "success"
                                    : a.status === "declined"
                                      ? "neutral"
                                      : "warning"
                            }
                        >
                            {a.status}
                        </Badge>
                    </p>
                ))}
            </Card>
        </div>
    );
}
