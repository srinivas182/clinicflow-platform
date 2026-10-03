import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface App {
    driver: string;
    offered: boolean;
    clientId: string | null;
    hasSecret: boolean;
    callback: string;
}

function AppCard({ a }: { a: App }) {
    const [form, setForm] = useState({
        offered: a.offered,
        client_id: a.clientId ?? "",
        client_secret: "",
    });

    return (
        <Card
            title={
                a.driver === "google"
                    ? "Google Calendar"
                    : "Microsoft 365 / Outlook"
            }
            aside={
                <Badge tone={a.offered ? "success" : "neutral"}>
                    {a.offered ? "offered" : "off"}
                </Badge>
            }
            className="mb-4"
        >
            <p className="mb-2 text-xs text-muted">
                Redirect URL to register: <code>{a.callback}</code>
            </p>
            <div className="mb-2 grid grid-cols-2 gap-2 text-sm">
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
                    placeholder={a.hasSecret ? "Secret saved" : "Client secret"}
                    className="rounded-md border border-line px-2 py-1"
                    value={form.client_secret}
                    onChange={(e) =>
                        setForm({ ...form, client_secret: e.target.value })
                    }
                />
            </div>
            <label className="mb-2 flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="accent-teal"
                    checked={form.offered}
                    onChange={(e) =>
                        setForm({ ...form, offered: e.target.checked })
                    }
                />{" "}
                Offer to doctors
            </label>
            <Button
                size="sm"
                onClick={() =>
                    router.put(`/admin/calendars/${a.driver}`, form, {
                        preserveScroll: true,
                    })
                }
            >
                Save
            </Button>
        </Card>
    );
}

export default function AdminCalendars({ apps }: { apps: App[] }) {
    return (
        <AdminShell active="Calendars">
            <Head title="Calendar apps" />
            <h1 className="mb-5 text-2xl font-semibold">Calendar apps</h1>
            <Flash />
            {apps.map((a) => (
                <AppCard key={a.driver} a={a} />
            ))}
        </AdminShell>
    );
}
