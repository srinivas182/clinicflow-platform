import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Connection {
    driver: string;
    label: string;
    enabled: boolean;
    autoExport: string;
    exportedUntil: string | null;
    error: string | null;
    map: Record<string, string>;
}

function ConnectionCard({
    c,
    accounts,
}: {
    c: Connection;
    accounts: string[];
}) {
    const [map, setMap] = useState<Record<string, string>>(c.map);
    const [auto, setAuto] = useState(c.autoExport);

    return (
        <Card
            title={c.label}
            aside={
                <Badge tone={c.enabled ? "success" : "neutral"}>
                    {c.enabled ? "active" : "connected"}
                </Badge>
            }
            className="mb-4"
        >
            {c.error && (
                <p role="alert" className="mb-2 text-sm text-status-danger">
                    {c.error}
                </p>
            )}
            <p className="mb-2 text-xs text-muted">
                Exported up to {c.exportedUntil ?? "not yet"}.
            </p>
            <div className="mb-2 text-xs font-medium">
                Map each Clinic Flow account to your account code
            </div>
            {accounts.map((a) => (
                <label key={a} className="mb-1 flex items-center gap-2 text-sm">
                    <span className="w-56">{a}</span>
                    <input
                        aria-label={`Account code for ${a}`}
                        className="rounded-md border border-line px-2 py-1"
                        value={map[a] ?? ""}
                        onChange={(e) =>
                            setMap({ ...map, [a]: e.target.value })
                        }
                    />
                </label>
            ))}
            <div className="mt-2 flex items-center gap-2 text-sm">
                Automatic export
                <select
                    aria-label="Automatic export"
                    className="rounded-md border border-line px-2 py-1"
                    value={auto}
                    onChange={(e) => setAuto(e.target.value)}
                >
                    <option value="off">Off</option>
                    <option value="daily">Daily</option>
                    <option value="hourly">Hourly</option>
                </select>
                <Button
                    size="sm"
                    onClick={() =>
                        router.put(
                            `/settings/accounting/${c.driver}`,
                            {
                                enabled: true,
                                auto_export: auto,
                                account_map: map,
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save and make active
                </Button>
                {c.enabled && (
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() =>
                            router.post("/settings/accounting/export")
                        }
                    >
                        Export now
                    </Button>
                )}
            </div>
        </Card>
    );
}

export default function Accounting({
    apps,
    connections,
    accounts,
}: {
    apps: { driver: string; label: string }[];
    connections: Connection[];
    accounts: string[];
}) {
    const today = new Date().toISOString().slice(0, 10);
    const first = today.slice(0, 8) + "01";

    return (
        <AppShell active="Settings">
            <Head title="Accounting" />
            <h1 className="mb-1 text-2xl font-semibold">Accounting</h1>
            <p className="mb-5 text-sm text-muted">
                Connect your accounting app to post daily journals
                automatically. Exports are always available below.
            </p>
            <Flash />
            <Card title="Connect an accounting app" className="mb-4">
                <div className="flex flex-wrap gap-2">
                    {apps.length === 0 && (
                        <p className="text-sm text-muted">
                            No accounting apps are offered yet.
                        </p>
                    )}
                    {apps.map((a) => (
                        <a
                            key={a.driver}
                            href={`/settings/accounting/${a.driver}/connect`}
                        >
                            <Button variant="secondary">
                                Connect {a.label}
                            </Button>
                        </a>
                    ))}
                </div>
            </Card>
            {connections.map((c) => (
                <ConnectionCard key={c.driver} c={c} accounts={accounts} />
            ))}
            <Card title="Exports (this month)">
                {["journal", "invoices", "payments", "vat"].map((t) => (
                    <p key={t} className="text-sm">
                        <span className="inline-block w-24 capitalize">
                            {t}
                        </span>
                        {["csv", "xlsx", "pdf"].map((f) => (
                            <a
                                key={f}
                                className="mr-3 text-teal-deep"
                                href={`/finance/exports/${t}?from=${first}&to=${today}&format=${f}`}
                            >
                                {f.toUpperCase()}
                            </a>
                        ))}
                    </p>
                ))}
            </Card>
        </AppShell>
    );
}
