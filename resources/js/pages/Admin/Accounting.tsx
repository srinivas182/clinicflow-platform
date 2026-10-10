import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface App {
    driver: string;
    label: string;
    offered: boolean;
    clientId: string | null;
    hasSecret: boolean;
    region: string | null;
    platform: {
        enabled: boolean;
        autoExport: string;
        map: Record<string, string>;
        exportedUntil: string | null;
        error: string | null;
    } | null;
}

function AppCard({
    a,
    callback,
    accounts,
}: {
    a: App;
    callback: string;
    accounts: string[];
}) {
    const [form, setForm] = useState({
        offered: a.offered,
        client_id: a.clientId ?? "",
        client_secret: "",
        region: a.region ?? "com",
    });
    const [map, setMap] = useState<Record<string, string>>(
        a.platform?.map ?? {},
    );

    return (
        <Card
            title={a.label}
            aside={
                <Badge tone={a.offered ? "success" : "neutral"}>
                    {a.offered ? "offered to providers" : "off"}
                </Badge>
            }
            className="mb-4"
        >
            <p className="mb-2 text-xs text-muted">
                Register Dr Business Flow as an app with {a.label} using this
                redirect URL: <code>{callback}</code>
            </p>
            <div className="grid grid-cols-3 gap-2 text-sm">
                <input
                    aria-label="Client ID"
                    placeholder="Client ID"
                    className="rounded-md border border-line px-2 py-1"
                    value={form.client_id}
                    onChange={(e) =>
                        setForm({ ...form, client_id: e.target.value })
                    }
                />
                <input
                    aria-label="Client secret"
                    type="password"
                    placeholder={
                        a.hasSecret
                            ? "Secret saved — enter to replace"
                            : "Client secret"
                    }
                    className="rounded-md border border-line px-2 py-1"
                    value={form.client_secret}
                    onChange={(e) =>
                        setForm({ ...form, client_secret: e.target.value })
                    }
                />
                {a.driver === "zoho" && (
                    <select
                        aria-label="Region"
                        className="rounded-md border border-line px-2 py-1"
                        value={form.region}
                        onChange={(e) =>
                            setForm({ ...form, region: e.target.value })
                        }
                    >
                        {["com", "eu", "in", "com.au"].map((r) => (
                            <option key={r}>{r}</option>
                        ))}
                    </select>
                )}
            </div>
            <label className="mt-2 flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="accent-teal"
                    checked={form.offered}
                    onChange={(e) =>
                        setForm({ ...form, offered: e.target.checked })
                    }
                />{" "}
                Offer to providers
            </label>
            <div className="mt-2 flex gap-2">
                <Button
                    size="sm"
                    onClick={() =>
                        router.put(`/admin/accounting/${a.driver}`, form, {
                            preserveScroll: true,
                        })
                    }
                >
                    Save
                </Button>
                <a href={`/admin/accounting/${a.driver}/connect`}>
                    <Button size="sm" variant="secondary">
                        Connect the platform's books
                    </Button>
                </a>
            </div>
            {a.platform && (
                <div className="mt-3 border-t border-line pt-3 text-sm">
                    {a.platform.error && (
                        <p role="alert" className="text-status-danger">
                            {a.platform.error}
                        </p>
                    )}
                    {accounts.map((k) => (
                        <label key={k} className="mb-1 flex items-center gap-2">
                            <span className="w-48">{k}</span>
                            <input
                                aria-label={`Platform account ${k}`}
                                className="rounded-md border border-line px-2 py-1"
                                value={map[k] ?? ""}
                                onChange={(e) =>
                                    setMap({ ...map, [k]: e.target.value })
                                }
                            />
                        </label>
                    ))}
                    <Button
                        size="sm"
                        onClick={() =>
                            router.put(
                                `/admin/accounting/${a.driver}/platform`,
                                {
                                    enabled: true,
                                    auto_export: "daily",
                                    account_map: map,
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Save and make active
                    </Button>{" "}
                    {a.platform.enabled && (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.post("/admin/accounting/export")
                            }
                        >
                            Export now
                        </Button>
                    )}
                </div>
            )}
        </Card>
    );
}

export default function AdminAccounting({
    apps,
    callbackUrls,
    platformAccounts,
}: {
    apps: App[];
    callbackUrls: Record<string, string>;
    platformAccounts: string[];
}) {
    return (
        <AdminShell active="Accounting">
            <Head title="Accounting apps" />
            <h1 className="mb-5 text-2xl font-semibold">Accounting apps</h1>
            <Flash />
            {apps.map((a) => (
                <AppCard
                    key={a.driver}
                    a={a}
                    callback={callbackUrls[a.driver] ?? ""}
                    accounts={platformAccounts}
                />
            ))}
        </AdminShell>
    );
}
