import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

type Range = {
    ref_low: string | null;
    ref_high: string | null;
    critical_low: string | null;
    critical_high: string | null;
} | null;
interface Result {
    test_code: string;
    name: string;
    unit: string;
    reference: string;
    value: string | null;
    flag: string | null;
    result_text: string | null;
    type?: string;
    choices?: string[];
    templateUnit?: string | null;
    range?: Range;
    rangeLabel?: string;
}
export interface LabRow {
    id: string;
    patient: string;
    status: string;
    barcode: string | null;
    source: string;
    classification: string | null;
    critical: boolean;
    acknowledged: boolean;
    reviewed: boolean;
    requested: boolean;
    escalated: boolean;
    waitingHours: number | null;
    home: { address: string; date: string; window: string } | null;
    hasReport: boolean;
    note: string | null;
    covering?: boolean;
    results: Result[];
}
interface Request {
    id: string;
    patient: string;
    practice: string;
    doctor: string;
    tests: string[];
    icd10: string[];
    home: { address: string; date: string; window: string } | null;
}

export const flagTone = (flag: string | null) =>
    flag?.startsWith("critical")
        ? "danger"
        : flag && flag !== "normal"
          ? "warning"
          : "success";

/** Live flag while typing, from the range for this patient's sex and age. */
function liveFlag(value: string, range: Range): string | null {
    if (value === "" || isNaN(Number(value)) || !range) return null;
    const v = Number(value);
    if (range.critical_low !== null && v <= Number(range.critical_low))
        return "critical low";
    if (range.critical_high !== null && v >= Number(range.critical_high))
        return "critical high";
    if (range.ref_low !== null && v < Number(range.ref_low)) return "low";
    if (range.ref_high !== null && v > Number(range.ref_high)) return "high";
    return "normal";
}

function Entry({ o }: { o: LabRow }) {
    const [values, setValues] = useState<Record<string, string>>({});
    const [flags, setFlags] = useState<Record<string, string>>({});
    const [file, setFile] = useState<File | null>(null);
    const save = () =>
        router.post(
            `/lab-orders/${o.id}/results`,
            { values, flags, ...(file ? { report: file } : {}) },
            { preserveScroll: true, forceFormData: true },
        );

    return (
        <>
            <table className="w-full text-sm">
                <tbody>
                    {o.results.map((r) => {
                        const live =
                            r.type === "numeric"
                                ? liveFlag(
                                      values[r.test_code] ?? "",
                                      r.range ?? null,
                                  )
                                : null;
                        return (
                            <tr
                                key={r.test_code}
                                className="border-t border-line-soft first:border-0"
                            >
                                <td className="py-1.5">{r.name}</td>
                                <td className="py-1.5">
                                    {r.type === "choice" ? (
                                        <select
                                            aria-label={`${r.name} result`}
                                            className="rounded border border-line px-2 py-1"
                                            value={values[r.test_code] ?? ""}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    [r.test_code]:
                                                        e.target.value,
                                                })
                                            }
                                        >
                                            <option value="">Choose…</option>
                                            {(r.choices ?? []).map((c) => (
                                                <option key={c}>{c}</option>
                                            ))}
                                        </select>
                                    ) : (
                                        <input
                                            aria-label={`${r.name} value`}
                                            className="w-28 rounded border border-line px-2 py-1"
                                            value={values[r.test_code] ?? ""}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    [r.test_code]:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                    )}{" "}
                                    <span className="text-xs text-muted">
                                        {r.templateUnit}
                                    </span>
                                </td>
                                <td className="py-1.5 text-xs text-muted">
                                    {r.rangeLabel}
                                </td>
                                <td className="py-1.5">
                                    {live && (
                                        <Badge
                                            tone={flagTone(
                                                live.replace(" ", "_"),
                                            )}
                                        >
                                            {live}
                                        </Badge>
                                    )}
                                </td>
                                <td className="py-1.5">
                                    <select
                                        aria-label={`${r.name} classification`}
                                        className="rounded border border-line px-1 py-1 text-xs"
                                        value={flags[r.test_code] ?? ""}
                                        onChange={(e) =>
                                            setFlags({
                                                ...flags,
                                                [r.test_code]: e.target.value,
                                            })
                                        }
                                    >
                                        <option value="">
                                            {r.type === "numeric"
                                                ? "System flag"
                                                : "Classify…"}
                                        </option>
                                        {r.type !== "numeric" && (
                                            <option value="normal">
                                                Normal
                                            </option>
                                        )}
                                        <option value="abnormal">
                                            Abnormal
                                        </option>
                                        <option value="critical">
                                            Critical
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
            <div className="mt-3 flex items-center gap-2 text-sm">
                <input
                    aria-label="Lab report PDF"
                    type="file"
                    accept="application/pdf"
                    onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                />
                <Button size="sm" onClick={save}>
                    Save results
                </Button>
            </div>
            <p className="mt-1 text-xs text-muted">
                Flags come from the range for this patient's sex and age. You
                can raise a flag, never lower it. A PDF without values is
                treated as unclassified.
            </p>
        </>
    );
}

export default function Worklist({
    orders,
    requests,
    collectors,
}: {
    orders: LabRow[];
    requests: Request[];
    collectors: { id: number; name: string }[];
}) {
    const step = (id: string, s: string, data: Record<string, string> = {}) =>
        router.post(`/lab-orders/${id}/${s}`, data, { preserveScroll: true });

    return (
        <AppShell active="Lab">
            <Head title="Lab worklist" />
            <h1 className="mb-1 text-2xl font-semibold">Lab worklist</h1>
            <p className="mb-5 text-sm text-muted">
                Collect → enter values from the template → a second person
                verifies.
            </p>
            <Flash />
            {requests.length > 0 && (
                <Card title="Requests from network practices" className="mb-4">
                    <ul className="space-y-3 text-sm">
                        {requests.map((r) => (
                            <li
                                key={r.id}
                                className="flex flex-wrap items-center gap-2"
                            >
                                <span className="flex-1">
                                    <b>{r.patient}</b> · {r.practice} ·{" "}
                                    {r.doctor} · {r.tests.join(", ")}{" "}
                                    {r.icd10.length > 0 && (
                                        <span className="text-xs text-muted">
                                            ({r.icd10.join(", ")})
                                        </span>
                                    )}
                                    {r.home && (
                                        <span className="block text-xs text-muted">
                                            Home collection: {r.home.address},{" "}
                                            {r.home.date} {r.home.window}
                                        </span>
                                    )}
                                </span>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            `/lab/requests/${r.id}/accept`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Accept
                                </Button>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => {
                                        const reason = window.prompt(
                                            "Reason for the doctor?",
                                        );
                                        if (reason)
                                            router.post(
                                                `/lab/requests/${r.id}/reject`,
                                                { reason },
                                                { preserveScroll: true },
                                            );
                                    }}
                                >
                                    Reject
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
            <div className="flex flex-col gap-3">
                {orders.length === 0 && (
                    <p className="text-sm text-muted">No open orders.</p>
                )}
                {orders.map((o) => (
                    <Card
                        key={o.id}
                        title={o.patient}
                        aside={
                            <Badge
                                tone={
                                    o.status === "resulted" ? "warning" : "teal"
                                }
                            >
                                {o.status}
                                {o.source === "network_in"
                                    ? " · network"
                                    : o.source === "self"
                                      ? " · patient-requested"
                                      : ""}
                            </Badge>
                        }
                    >
                        {o.barcode && (
                            <p className="mb-2 font-mono text-xs">
                                {o.barcode}
                            </p>
                        )}
                        {o.home && (
                            <p className="mb-2 flex items-center gap-2 text-xs">
                                Home collection: {o.home.address}, {o.home.date}{" "}
                                {o.home.window}
                                <select
                                    aria-label="Collector"
                                    className="rounded border border-line px-1 py-0.5"
                                    onChange={(e) =>
                                        step(o.id, "assign", {
                                            collector_staff_id: e.target.value,
                                        })
                                    }
                                >
                                    <option value="">Assign collector…</option>
                                    {collectors.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </p>
                        )}
                        {o.status === "collected" ? (
                            <Entry o={o} />
                        ) : (
                            <ul className="text-sm">
                                {o.results.map((r) => (
                                    <li
                                        key={r.test_code}
                                        className="flex gap-2"
                                    >
                                        <span className="flex-1">{r.name}</span>
                                        {(r.value ?? r.result_text) !==
                                            null && (
                                            <Badge tone={flagTone(r.flag)}>
                                                {r.result_text ?? r.value}{" "}
                                                {r.unit}
                                            </Badge>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div className="mt-3">
                            {o.status === "ordered" && (
                                <Button
                                    size="sm"
                                    onClick={() => step(o.id, "collect")}
                                >
                                    Sample collected
                                </Button>
                            )}
                            {o.status === "resulted" && (
                                <Button
                                    size="sm"
                                    onClick={() => step(o.id, "verify")}
                                >
                                    Verify ({o.classification})
                                </Button>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
        </AppShell>
    );
}
