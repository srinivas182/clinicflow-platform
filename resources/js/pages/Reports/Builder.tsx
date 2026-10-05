import { Head, router } from "@inertiajs/react";
import { useMemo, useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

type Opt = { key: string; label: string };
interface Dataset {
    key: string;
    label: string;
    branch: boolean;
    groups: Opt[];
    measures: Opt[];
    filters: (Opt & { values: string[] | null })[];
}
interface Definition {
    dataset: string;
    from: string;
    to: string;
    groups: string[];
    measures: string[];
    filters: Record<string, string>;
    branch_id: string;
}
interface Result {
    columns: { key: string; label: string; money: boolean }[];
    rows: Record<string, string | number | null>[];
    truncated: boolean;
}
interface Props {
    datasets: Dataset[];
    saved: {
        id: number;
        name: string;
        definition: Definition;
        schedule: string;
        recipients: number[];
    }[];
    advanced: boolean;
    branches: { id: number; name: string }[];
    staff: { id: number; name: string }[];
}

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m?.[1] ? decodeURIComponent(m[1]) : "";
}
const monthStart = () =>
    new Date(new Date().getFullYear(), new Date().getMonth(), 1)
        .toISOString()
        .slice(0, 10);
const today = () => new Date().toISOString().slice(0, 10);
const fmt = (v: string | number | null, money: boolean) =>
    v === null
        ? "—"
        : money && typeof v === "number"
          ? `R ${v.toLocaleString("en-ZA", { minimumFractionDigits: 2 })}`
          : String(v);

export default function ReportBuilder({
    datasets,
    saved,
    advanced,
    branches,
    staff,
}: Props) {
    const first = datasets[0];
    const [def, setDef] = useState<Definition>({
        dataset: first?.key ?? "",
        from: monthStart(),
        to: today(),
        groups: ["day"],
        measures: [first?.measures[0]?.key ?? "count"],
        filters: {},
        branch_id: "",
    });
    const [result, setResult] = useState<Result | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const set = datasets.find((d) => d.key === def.dataset) ?? first;
    const input = "rounded-md border border-line px-2 py-1 text-sm";

    const post = async (url: string, body: unknown) =>
        fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": xsrf(),
            },
            body: JSON.stringify(body),
            credentials: "same-origin",
        });

    const run = async () => {
        setBusy(true);
        setError(null);
        const res = await post("/reports/run", { definition: def });
        const data = await res.json().catch(() => ({}));
        setBusy(false);
        if (!res.ok)
            return setError(
                data?.errors
                    ? ((Object.values(data.errors)[0] as string[])[0] ??
                          "Could not run the report.")
                    : (data?.message ?? "Could not run the report."),
            );
        setResult(data as Result);
    };

    const download = async (format: "csv" | "pdf") => {
        const res = await post(`/reports/export/${format}`, {
            definition: JSON.stringify(def),
            title: set?.label ?? "Report",
        });
        if (!res.ok) return setError("Export failed.");
        const url = URL.createObjectURL(await res.blob());
        const a = document.createElement("a");
        a.href = url;
        a.download = `report.${format}`;
        a.click();
        URL.revokeObjectURL(url);
    };

    const chart = useMemo(() => {
        if (!result || result.rows.length === 0) return null;
        const label = result.columns[0];
        const measure = result.columns.find((c) =>
            def.measures.includes(c.key),
        );
        if (!label || !measure || def.groups.length === 0) return null;
        const values = result.rows.map((r) => Number(r[measure.key] ?? 0));
        const max = Math.max(1, ...values);
        return { label, measure, max, values };
    }, [result, def.measures, def.groups.length]);

    if (!set) return null;

    return (
        <AppShell active="Reports">
            <Head title="Reports" />
            <h1 className="mb-1 text-2xl font-semibold">Reports</h1>
            <p className="mb-5 text-sm text-muted">
                Build reports from your practice data. Reports show totals only
                — no patient names.
            </p>
            <Flash />
            <Card title="Build a report" className="mb-4">
                <div className="flex flex-wrap items-end gap-3 text-sm">
                    <label>
                        Data
                        <br />
                        <select
                            aria-label="Data set"
                            className={input}
                            value={def.dataset}
                            onChange={(e) => {
                                const d = datasets.find(
                                    (x) => x.key === e.target.value,
                                );
                                setDef({
                                    ...def,
                                    dataset: e.target.value,
                                    groups: ["day"],
                                    measures: [d?.measures[0]?.key ?? "count"],
                                    filters: {},
                                });
                                setResult(null);
                            }}
                        >
                            {datasets.map((d) => (
                                <option key={d.key} value={d.key}>
                                    {d.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        From
                        <br />
                        <input
                            aria-label="From"
                            type="date"
                            className={input}
                            value={def.from}
                            onChange={(e) =>
                                setDef({ ...def, from: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        To
                        <br />
                        <input
                            aria-label="To"
                            type="date"
                            className={input}
                            value={def.to}
                            onChange={(e) =>
                                setDef({ ...def, to: e.target.value })
                            }
                        />
                    </label>
                    {[0, 1].map((i) => (
                        <label key={i}>
                            {i === 0 ? "Group by" : "Then by"}
                            <br />
                            <select
                                aria-label={i === 0 ? "Group by" : "Then by"}
                                className={input}
                                value={def.groups[i] ?? ""}
                                onChange={(e) => {
                                    const g = [...def.groups];
                                    if (e.target.value) g[i] = e.target.value;
                                    else g.splice(i, 1);
                                    setDef({
                                        ...def,
                                        groups: g.filter(Boolean),
                                    });
                                }}
                            >
                                <option value="">
                                    {i === 0 ? "No grouping" : "—"}
                                </option>
                                {set.groups.map((g) => (
                                    <option key={g.key} value={g.key}>
                                        {g.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ))}
                    {set.branch && branches.length > 1 && (
                        <label>
                            Branch
                            <br />
                            <select
                                aria-label="Branch"
                                className={input}
                                value={def.branch_id}
                                onChange={(e) =>
                                    setDef({
                                        ...def,
                                        branch_id: e.target.value,
                                    })
                                }
                            >
                                <option value="">All branches</option>
                                {branches.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    {set.filters
                        .filter((f) => f.values)
                        .map((f) => (
                            <label key={f.key}>
                                {f.label}
                                <br />
                                <select
                                    aria-label={f.label}
                                    className={input}
                                    value={def.filters[f.key] ?? ""}
                                    onChange={(e) =>
                                        setDef({
                                            ...def,
                                            filters: {
                                                ...def.filters,
                                                [f.key]: e.target.value,
                                            },
                                        })
                                    }
                                >
                                    <option value="">All</option>
                                    {(f.values ?? []).map((v) => (
                                        <option key={v} value={v}>
                                            {v.replace("_", " ")}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ))}
                </div>
                <div className="mt-3 flex flex-wrap gap-3 text-sm">
                    Totals:
                    {set.measures.map((m) => (
                        <label key={m.key} className="flex items-center gap-1">
                            <input
                                type="checkbox"
                                className="accent-teal"
                                checked={def.measures.includes(m.key)}
                                onChange={(e) =>
                                    setDef({
                                        ...def,
                                        measures: e.target.checked
                                            ? [...def.measures, m.key]
                                            : def.measures.filter(
                                                  (x) => x !== m.key,
                                              ),
                                    })
                                }
                            />
                            {m.label}
                        </label>
                    ))}
                </div>
                {error && (
                    <p className="mt-2 text-xs text-status-danger">{error}</p>
                )}
                <div className="mt-3 flex gap-2">
                    <Button size="sm" disabled={busy} onClick={run}>
                        {busy ? "Running…" : "Run report"}
                    </Button>
                    {result && (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => download("csv")}
                        >
                            Export CSV (Excel)
                        </Button>
                    )}
                    {result && (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => download("pdf")}
                        >
                            Export PDF
                        </Button>
                    )}
                    {result && advanced && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                const name = window.prompt("Report name");
                                if (!name) return;
                                const schedule =
                                    window.prompt(
                                        "Email it: none, weekly or monthly",
                                        "none",
                                    ) ?? "none";
                                const who =
                                    schedule === "none"
                                        ? []
                                        : (
                                              window.prompt(
                                                  `Send to (numbers, comma separated):\n${staff.map((s, i) => `${i + 1}. ${s.name}`).join("\n")}`,
                                                  "",
                                              ) ?? ""
                                          )
                                              .split(",")
                                              .map(
                                                  (x) =>
                                                      staff[
                                                          Number(x.trim()) - 1
                                                      ]?.id,
                                              )
                                              .filter(Boolean);
                                router.post(
                                    "/reports",
                                    {
                                        name,
                                        definition: JSON.parse(
                                            JSON.stringify(def),
                                        ),
                                        schedule,
                                        recipients: who,
                                    },
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            Save report
                        </Button>
                    )}
                </div>
                {!advanced && (
                    <p className="mt-2 text-xs text-muted">
                        Saved and scheduled reports are part of the Standard and
                        Pro packages.
                    </p>
                )}
            </Card>
            {saved.length > 0 && (
                <Card title="Saved reports" className="mb-4">
                    {saved.map((r) => (
                        <div
                            key={r.id}
                            className="flex items-center gap-2 py-1 text-sm"
                        >
                            <button
                                className="flex-1 text-left text-teal-deep"
                                onClick={() => {
                                    setDef({
                                        ...r.definition,
                                        filters: r.definition.filters ?? {},
                                        branch_id: r.definition.branch_id ?? "",
                                    });
                                    setResult(null);
                                }}
                            >
                                {r.name}
                            </button>
                            {r.schedule !== "none" && (
                                <Badge>{r.schedule}</Badge>
                            )}
                            {advanced && (
                                <button
                                    className="text-xs text-status-danger"
                                    onClick={() =>
                                        window.confirm(
                                            "Delete this saved report?",
                                        ) &&
                                        router.delete(`/reports/${r.id}`, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    delete
                                </button>
                            )}
                        </div>
                    ))}
                </Card>
            )}
            {result && (
                <Card
                    title={`${set.label}${result.truncated ? " (first 5,000 rows)" : ""}`}
                >
                    {chart && (
                        <div
                            className="mb-4 flex h-40 items-end gap-1 overflow-x-auto"
                            aria-label={`Chart of ${chart.measure.label}`}
                        >
                            {chart.values.map((v, i) => (
                                <div
                                    key={i}
                                    className="flex min-w-6 flex-1 flex-col items-center justify-end"
                                    title={`${String(result.rows[i]?.[chart.label.key] ?? "")}: ${fmt(v, chart.measure.money)}`}
                                >
                                    <div
                                        className="w-full rounded-t bg-teal"
                                        style={{
                                            height: `${Math.max(2, (v / chart.max) * 100)}%`,
                                        }}
                                    />
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr>
                                    {result.columns.map((c) => (
                                        <th
                                            key={c.key}
                                            className="border-b border-line py-1 text-left"
                                        >
                                            {c.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {result.rows.map((r, i) => (
                                    <tr
                                        key={i}
                                        className="border-t border-[#EBF0EE]"
                                    >
                                        {result.columns.map((c) => (
                                            <td key={c.key} className="py-1">
                                                {fmt(r[c.key] ?? null, c.money)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {result.rows.length === 0 && (
                            <p className="py-3 text-sm text-muted">
                                No data for this period.
                            </p>
                        )}
                    </div>
                </Card>
            )}
        </AppShell>
    );
}
