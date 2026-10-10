import { Head } from "@inertiajs/react";

export interface StatusProps {
    overall: string;
    components: {
        key: string;
        name: string;
        status: string;
        checked_at: string | null;
    }[];
    incidents: {
        id: number;
        title: string;
        kind: string;
        status: string;
        impact: string;
        components: string[];
        scheduledFor: string | null;
        resolvedAt: string | null;
        updates: { status: string; body: string; created_at: string }[];
    }[];
    updatedAt: string;
}
const colour: Record<string, string> = {
    operational: "#15803d",
    degraded: "#b45309",
    outage: "#b91c1c",
};
const label: Record<string, string> = {
    operational: "Operational",
    degraded: "Degraded",
    outage: "Outage",
};

export default function StatusShow({
    overall,
    components,
    incidents,
    updatedAt,
}: StatusProps) {
    return (
        <div className="mx-auto max-w-3xl px-6 py-10">
            <Head title="Dr Business Flow status" />
            <h1 className="mb-2 text-2xl font-semibold">Dr Business Flow status</h1>
            <p
                className="mb-6 rounded-lg px-4 py-3 font-medium text-white"
                style={{ background: colour[overall] }}
            >
                {overall === "operational"
                    ? "All systems operational"
                    : overall === "degraded"
                      ? "Some systems are degraded"
                      : "We are experiencing an outage"}
            </p>
            <ul className="mb-8 divide-y divide-line-soft rounded-lg border border-line">
                {components.map((c) => (
                    <li
                        key={c.key}
                        className="flex items-center px-4 py-3 text-sm"
                    >
                        <span className="flex-1">{c.name}</span>
                        <span style={{ color: colour[c.status] }}>
                            {label[c.status] ?? c.status}
                        </span>
                    </li>
                ))}
            </ul>
            <h2 className="mb-3 text-lg font-semibold">
                Incidents and maintenance (last 14 days)
            </h2>
            {incidents.length === 0 && (
                <p className="text-sm text-muted">No incidents.</p>
            )}
            {incidents.map((i) => (
                <div
                    key={i.id}
                    className="mb-4 rounded-lg border border-line p-4"
                >
                    <p className="font-medium">
                        {i.title}{" "}
                        <span className="text-xs text-muted">
                            · {i.kind} · {i.status}
                        </span>
                    </p>
                    {i.scheduledFor && (
                        <p className="text-xs text-muted">
                            Scheduled for {i.scheduledFor}
                        </p>
                    )}
                    {i.updates.map((u, n) => (
                        <p key={n} className="mt-2 text-sm">
                            <span className="text-xs text-muted">
                                {u.created_at.slice(0, 16)} · {u.status}
                            </span>
                            <br />
                            {u.body}
                        </p>
                    ))}
                </div>
            ))}
            <p className="text-xs text-muted">
                Updated {updatedAt.slice(0, 16).replace("T", " ")}
            </p>
        </div>
    );
}
