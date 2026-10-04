import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";
import type { Ticket } from "../Support/Practice";

export default function AdminSupport({
    tickets,
    grants,
}: {
    tickets: Ticket[];
    grants: {
        id: number;
        practice: string | null;
        expires: string;
        ticket: number | null;
    }[];
}) {
    const act = (action: string, data: Record<string, unknown>) =>
        router.post(`/admin/support/${action}`, data as never, {
            preserveScroll: true,
        });

    return (
        <AdminShell active="Support">
            <Head title="Support" />
            <h1 className="mb-5 text-2xl font-semibold">Support</h1>
            <Flash />
            <Card
                title="Practices that allowed support access"
                className="mb-4"
            >
                {grants.length === 0 && (
                    <p className="text-sm text-muted">None right now.</p>
                )}
                {grants.map((g) => (
                    <p key={g.id} className="flex items-center gap-2 text-sm">
                        {g.practice} · until {g.expires.slice(0, 16)}
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => act("enter", { grant_id: g.id })}
                        >
                            Open (read-only)
                        </Button>
                    </p>
                ))}
            </Card>
            {tickets.map((t) => (
                <Card
                    key={t.id}
                    title={`${t.practice} · ${t.subject}`}
                    aside={<Badge>{t.status}</Badge>}
                    className="mb-3"
                >
                    {t.messages.map((m, i) => (
                        <p key={i} className="mb-2 text-sm">
                            <span className="text-xs text-muted">
                                {m.author} · {m.created_at.slice(0, 16)}
                            </span>
                            <br />
                            {m.body}
                        </p>
                    ))}
                    {t.status !== "closed" && (
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                onClick={() =>
                                    act("reply", {
                                        ticket_id: t.id,
                                        body: window.prompt("Reply") ?? "",
                                    })
                                }
                            >
                                Reply
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    act("close", { ticket_id: t.id })
                                }
                            >
                                Close
                            </Button>
                        </div>
                    )}
                </Card>
            ))}
        </AdminShell>
    );
}
