import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface Entry {
    id: number;
    log: string | null;
    description: string;
    at: string;
    by: string;
    properties: Record<string, unknown> | null;
}

/** Old → new for a setting change; otherwise the submitted fields (secrets already masked). */
function Details({ p }: { p: Record<string, unknown> }) {
    if ("old" in p && "new" in p) {
        return (
            <p className="break-words font-mono text-xs text-muted">
                {JSON.stringify(p.old)} → {JSON.stringify(p.new)}
            </p>
        );
    }
    const shown = (p.input ?? p) as Record<string, unknown>;
    const keys = Object.keys(shown);
    if (keys.length === 0) return null;
    return (
        <p className="break-words font-mono text-xs text-muted">
            {keys
                .slice(0, 12)
                .map((k) => `${k}: ${JSON.stringify(shown[k])}`)
                .join(" · ")}
        </p>
    );
}

/** Super admin: who changed what on the platform, newest first. */
export default function Activity({
    entries,
    logs,
    filters,
    hasMore,
}: {
    entries: Entry[];
    logs: string[];
    filters: { log: string | null; q: string | null; page: number };
    hasMore: boolean;
}) {
    const [q, setQ] = useState(filters.q ?? "");
    const go = (f: Partial<typeof filters>) =>
        router.get(
            "/admin/activity",
            { log: filters.log, q: filters.q, page: 1, ...f },
            { preserveState: true },
        );
    return (
        <AdminShell active="Activity log">
            <Head title="Activity log" />
            <h1 className="mb-1 text-2xl font-semibold">Activity log</h1>
            <p className="mb-4 text-sm text-muted">
                Every change made in Admin, setting changes with old and new
                values, approvals, billing and security events. Secrets are
                never shown.
            </p>
            <form
                className="mb-4 flex flex-wrap gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    go({ q });
                }}
            >
                <select
                    aria-label="Log"
                    className="rounded-md border border-line px-2 py-1.5 text-sm"
                    value={filters.log ?? ""}
                    onChange={(e) => go({ log: e.target.value || null })}
                >
                    <option value="">All logs</option>
                    {logs.map((l) => (
                        <option key={l} value={l}>
                            {l}
                        </option>
                    ))}
                </select>
                <input
                    aria-label="Search"
                    className="min-w-0 flex-1 basis-48 rounded-md border border-line px-2 py-1.5 text-sm"
                    placeholder="Search, e.g. wallet or a practice name"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                />
                <Button size="sm" type="submit">
                    Search
                </Button>
            </form>
            <Card>
                <ul className="divide-y divide-line-soft text-sm">
                    {entries.length === 0 && (
                        <li className="py-3 text-muted">Nothing found.</li>
                    )}
                    {entries.map((e) => (
                        <li key={e.id} className="py-2.5">
                            <div className="flex flex-wrap items-center gap-2">
                                {e.log && <Badge>{e.log}</Badge>}
                                <span className="min-w-0 flex-1 font-medium break-words">
                                    {e.description}
                                </span>
                                <span className="text-xs text-muted">
                                    {e.by} · {e.at}
                                </span>
                            </div>
                            {e.properties && <Details p={e.properties} />}
                        </li>
                    ))}
                </ul>
            </Card>
            <div className="mt-3 flex gap-2">
                {filters.page > 1 && (
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => go({ page: filters.page - 1 })}
                    >
                        Newer
                    </Button>
                )}
                {hasMore && (
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => go({ page: filters.page + 1 })}
                    >
                        Older
                    </Button>
                )}
            </div>
        </AdminShell>
    );
}
