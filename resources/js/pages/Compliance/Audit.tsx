import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { Pager, type Paginated } from "@/components/Pager";

interface Entry {
    at: string | null;
    log: string;
    description: string;
    by: string;
    subject: string | null;
}

export default function Audit({
    filters,
    logs,
    entries,
}: {
    filters: Record<string, string>;
    logs: string[];
    entries: Paginated<Entry>;
}) {
    const [f, setF] = useState<Record<string, string>>({
        log: "",
        search: "",
        from: "",
        to: "",
        ...filters,
    });
    const apply = () =>
        router.get("/compliance/audit", f, { preserveState: true });
    const query = new URLSearchParams(
        Object.entries(f).filter(([, v]) => v !== ""),
    ).toString();

    return (
        <AppShell active="Audit">
            <Head title="Audit log" />
            <h1 className="mb-1 text-2xl font-semibold">
                Audit and compliance
            </h1>
            <p className="mb-5 text-sm text-muted">
                Every sign-in, record change, signature, payment and support
                session. Entries can't be edited or deleted. Patient data
                exports for POPIA requests are on each patient record.
            </p>
            <Card className="mb-4">
                <div className="flex flex-wrap items-end gap-2 text-sm">
                    <select
                        aria-label="Log"
                        value={f.log}
                        onChange={(e) => setF({ ...f, log: e.target.value })}
                        className="rounded border border-line px-2 py-1.5"
                    >
                        <option value="">All areas</option>
                        {logs.map((l) => (
                            <option key={l} value={l}>
                                {l}
                            </option>
                        ))}
                    </select>
                    <input
                        aria-label="Search"
                        placeholder="Search"
                        value={f.search}
                        onChange={(e) => setF({ ...f, search: e.target.value })}
                        className="rounded border border-line px-2 py-1.5"
                    />
                    <input
                        aria-label="From"
                        type="date"
                        value={f.from}
                        onChange={(e) => setF({ ...f, from: e.target.value })}
                        className="rounded border border-line px-2 py-1.5"
                    />
                    <input
                        aria-label="To"
                        type="date"
                        value={f.to}
                        onChange={(e) => setF({ ...f, to: e.target.value })}
                        className="rounded border border-line px-2 py-1.5"
                    />
                    <Button size="sm" onClick={apply}>
                        Filter
                    </Button>
                    <a
                        href={`/compliance/audit/export?${query}`}
                        className="ml-auto"
                    >
                        <Button size="sm" variant="secondary">
                            Export CSV
                        </Button>
                    </a>
                </div>
            </Card>
            <Card>
                <table className="w-full text-sm">
                    <tbody>
                        {entries.data.map((e, i) => (
                            <tr
                                key={i}
                                className="border-t border-[#EBF0EE] first:border-0"
                            >
                                <td className="w-36 py-2 text-xs text-muted">
                                    {e.at}
                                </td>
                                <td className="w-24 py-2 text-xs">{e.log}</td>
                                <td className="py-2">{e.description}</td>
                                <td className="py-2 text-xs text-muted">
                                    {e.by}
                                </td>
                                <td className="py-2 text-xs text-muted">
                                    {e.subject}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
            <Pager page={entries} />
        </AppShell>
    );
}
