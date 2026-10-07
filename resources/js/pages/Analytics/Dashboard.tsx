import { Head, router } from "@inertiajs/react";
import { Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

type Summary = Record<string, number | null>;
interface Props {
    available: boolean;
    period?: { key: string; from: string; to: string; branchId: number | null };
    current?: Summary;
    previous?: Summary;
    trend?: { month: string; billed: number; takings: number }[];
    doctors?: {
        doctor: string;
        visits: number;
        appointments: number;
        no_show_rate: number | null;
        billed: number;
        utilisation: number | null;
    }[];
    branches?: { branch: string; visits: number; billed: number }[];
    branchOptions?: { id: number; name: string }[];
}

const money = (v: number | null | undefined) =>
    v === null || v === undefined
        ? "—"
        : `R ${v.toLocaleString("en-ZA", { maximumFractionDigits: 0 })}`;
const pct = (v: number | null | undefined) =>
    v === null || v === undefined ? "—" : `${v}%`;
const num = (v: number | null | undefined, unit = "") =>
    v === null || v === undefined ? "—" : `${v}${unit}`;

/** Higher is better for most figures; for these, lower is better. */
const LOWER_IS_BETTER = [
    "owed",
    "debtor_days",
    "no_show_rate",
    "cancel_rate",
    "avg_wait_minutes",
    "lab_turnaround_hours",
];

const CARDS: {
    key: string;
    label: string;
    show: (v: number | null) => string;
}[] = [
    { key: "billed", label: "Billed", show: money },
    { key: "takings", label: "Collected", show: money },
    { key: "collection_rate", label: "Collection rate", show: pct },
    { key: "owed", label: "Owed to the practice", show: money },
    { key: "debtor_days", label: "Debtor days", show: (v) => num(v) },
    { key: "appointments", label: "Appointments", show: (v) => num(v) },
    { key: "no_show_rate", label: "No-show rate", show: pct },
    { key: "utilisation", label: "Doctor utilisation", show: pct },
    {
        key: "avg_wait_minutes",
        label: "Average wait",
        show: (v) => num(v, " min"),
    },
    { key: "visits", label: "Visits", show: (v) => num(v) },
    { key: "new_patients", label: "New patients", show: (v) => num(v) },
    {
        key: "returning_patients",
        label: "Returning patients",
        show: (v) => num(v),
    },
    { key: "online_consults", label: "Online consults", show: (v) => num(v) },
    {
        key: "lab_turnaround_hours",
        label: "Lab turnaround",
        show: (v) => num(v, " h"),
    },
];

export default function AnalyticsDashboard({
    available,
    period,
    current,
    previous,
    trend = [],
    doctors = [],
    branches = [],
    branchOptions = [],
}: Props) {
    if (!available || !period || !current || !previous) {
        return (
            <AppShell active="Analytics">
                <Head title="Analytics" />
                <h1 className="mb-2 text-2xl font-semibold">Analytics</h1>
                <p className="text-sm text-muted">
                    Analytics dashboards are part of the Standard and Pro
                    packages.
                </p>
            </AppShell>
        );
    }
    const go = (p: Record<string, string | number | null>) =>
        router.get(
            "/analytics",
            { period: period.key, branch_id: period.branchId, ...p },
            { preserveScroll: true },
        );
    const max = Math.max(1, ...trend.flatMap((t) => [t.billed, t.takings]));

    return (
        <AppShell active="Analytics">
            <Head title="Analytics" />
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <h1 className="flex-1 text-2xl font-semibold">Analytics</h1>
                <select
                    aria-label="Period"
                    className="rounded-md border border-line px-2 py-1 text-sm"
                    value={period.key}
                    onChange={(e) => go({ period: e.target.value })}
                >
                    <option value="this_month">This month</option>
                    <option value="last_month">Last month</option>
                    <option value="last_90">Last 90 days</option>
                </select>
                {branchOptions.length > 1 && (
                    <select
                        aria-label="Branch"
                        className="rounded-md border border-line px-2 py-1 text-sm"
                        value={period.branchId ?? ""}
                        onChange={(e) =>
                            go({ branch_id: e.target.value || null })
                        }
                    >
                        <option value="">All branches</option>
                        {branchOptions.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.name}
                            </option>
                        ))}
                    </select>
                )}
            </div>
            <p className="mb-4 text-xs text-muted">
                {period.from} to {period.to}, compared with the previous period
                of the same length. Totals only — no patient records.
            </p>
            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
                {CARDS.map((c) => {
                    const v = current[c.key] ?? null;
                    const p = previous[c.key] ?? null;
                    const diff =
                        v !== null && p !== null
                            ? Math.round((v - p) * 10) / 10
                            : null;
                    const good =
                        diff === null || diff === 0
                            ? null
                            : LOWER_IS_BETTER.includes(c.key)
                              ? diff < 0
                              : diff > 0;
                    return (
                        <div
                            key={c.key}
                            className="rounded-lg border border-line bg-surface p-3"
                        >
                            <p className="text-xs text-muted">{c.label}</p>
                            <p className="text-lg font-semibold">{c.show(v)}</p>
                            {diff !== null && diff !== 0 && (
                                <p
                                    className={`text-xs ${good ? "text-status-success" : "text-status-danger"}`}
                                >
                                    {diff > 0 ? "▲" : "▼"} {Math.abs(diff)} vs
                                    previous
                                </p>
                            )}
                        </div>
                    );
                })}
            </div>
            <Card title="Billed vs collected (6 months)" className="mb-4">
                <div className="flex h-40 items-end gap-4">
                    {trend.map((t) => (
                        <div
                            key={t.month}
                            className="flex flex-1 flex-col items-center gap-1"
                        >
                            <div className="flex h-32 w-full items-end gap-1">
                                <div
                                    className="flex-1 rounded-t bg-teal/40"
                                    style={{
                                        height: `${Math.max(2, (t.billed / max) * 100)}%`,
                                    }}
                                    title={`Billed ${money(t.billed)}`}
                                />
                                <div
                                    className="flex-1 rounded-t bg-teal"
                                    style={{
                                        height: `${Math.max(2, (t.takings / max) * 100)}%`,
                                    }}
                                    title={`Collected ${money(t.takings)}`}
                                />
                            </div>
                            <span className="text-[10px] text-muted">
                                {t.month}
                            </span>
                        </div>
                    ))}
                </div>
                <p className="mt-2 text-xs text-muted">
                    Light: billed · Dark: collected
                </p>
            </Card>
            <div className="grid gap-4 md:grid-cols-2">
                <Card title="By doctor">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs text-muted">
                                <th>Doctor</th>
                                <th>Visits</th>
                                <th>Appts</th>
                                <th>No-shows</th>
                                <th>Billed</th>
                                <th>Utilisation</th>
                            </tr>
                        </thead>
                        <tbody>
                            {doctors.map((d) => (
                                <tr
                                    key={d.doctor}
                                    className="border-t border-line-soft"
                                >
                                    <td className="py-1">{d.doctor}</td>
                                    <td>{d.visits}</td>
                                    <td>{d.appointments}</td>
                                    <td>{pct(d.no_show_rate)}</td>
                                    <td>{money(d.billed)}</td>
                                    <td>{pct(d.utilisation)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {doctors.length === 0 && (
                        <p className="text-sm text-muted">
                            No activity in this period.
                        </p>
                    )}
                </Card>
                {branches.length > 1 && (
                    <Card title="By branch">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs text-muted">
                                    <th>Branch</th>
                                    <th>Visits</th>
                                    <th>Billed</th>
                                </tr>
                            </thead>
                            <tbody>
                                {branches.map((b) => (
                                    <tr
                                        key={b.branch}
                                        className="border-t border-line-soft"
                                    >
                                        <td className="py-1">{b.branch}</td>
                                        <td>{b.visits}</td>
                                        <td>{money(b.billed)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>
                )}
            </div>
        </AppShell>
    );
}
