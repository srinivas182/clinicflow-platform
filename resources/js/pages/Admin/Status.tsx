import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";
import type { StatusProps } from "../Status/Show";

const ask = (l: string, d = "") => window.prompt(l, d) ?? "";

export default function AdminStatus({
    components,
    incidents,
    componentNames,
}: StatusProps & { componentNames: Record<string, string> }) {
    const act = (action: string, data: Record<string, unknown>) =>
        router.post(`/admin/status/${action}`, data as never, {
            preserveScroll: true,
        });

    return (
        <AdminShell active="Status">
            <Head title="Status page" />
            <div className="mb-5 flex items-center gap-2">
                <h1 className="flex-1 text-2xl font-semibold">Status page</h1>
                <a href="/status" target="_blank" rel="noreferrer">
                    <Button variant="ghost">View public page</Button>
                </a>
                <Button
                    onClick={() =>
                        act("report", {
                            title: ask("Title"),
                            kind: ask("incident or maintenance", "incident"),
                            impact: ask("minor, major or critical", "minor"),
                            components: ask(
                                `Components (${Object.keys(componentNames).join(", ")})`,
                            )
                                .split(",")
                                .map((s) => s.trim()),
                            body: ask("First update"),
                            scheduled_for:
                                ask(
                                    "Scheduled for (maintenance, YYYY-MM-DD HH:MM)",
                                ) || null,
                        })
                    }
                >
                    Report incident / maintenance
                </Button>
            </div>
            <Flash />
            <Card
                title="Components (checked every minute unless set by hand)"
                className="mb-4"
            >
                {components.map((c) => (
                    <p
                        key={c.key}
                        className="flex items-center gap-2 py-1 text-sm"
                    >
                        <span className="flex-1">{c.name}</span>
                        <Badge
                            tone={
                                c.status === "operational"
                                    ? "success"
                                    : c.status === "degraded"
                                      ? "warning"
                                      : "danger"
                            }
                        >
                            {c.status}
                        </Badge>
                        <select
                            aria-label={`Set ${c.name}`}
                            className="rounded border border-line px-1 text-xs"
                            defaultValue=""
                            onChange={(e) =>
                                act("component", {
                                    key: c.key,
                                    status: e.target.value || null,
                                })
                            }
                        >
                            <option value="">Automatic</option>
                            <option value="operational">Operational</option>
                            <option value="degraded">Degraded</option>
                            <option value="outage">Outage</option>
                        </select>
                    </p>
                ))}
            </Card>
            {incidents.map((i) => (
                <Card
                    key={i.id}
                    title={i.title}
                    aside={<Badge>{i.status}</Badge>}
                    className="mb-3"
                >
                    {i.updates.map((u, n) => (
                        <p key={n} className="text-sm">
                            <span className="text-xs text-muted">
                                {u.status}
                            </span>{" "}
                            {u.body}
                        </p>
                    ))}
                    {!["resolved", "completed"].includes(i.status) && (
                        <Button
                            className="mt-2"
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                act("update", {
                                    incident_id: i.id,
                                    status: ask(
                                        "Status",
                                        i.kind === "maintenance"
                                            ? "completed"
                                            : "resolved",
                                    ),
                                    body: ask("Update"),
                                })
                            }
                        >
                            Post update
                        </Button>
                    )}
                </Card>
            ))}
        </AdminShell>
    );
}
