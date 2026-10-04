import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

export interface Ticket {
    id: number;
    subject: string;
    status: string;
    practice: string | null;
    messages: { author: string; body: string; created_at: string }[];
}
const ask = (l: string, d = "") => window.prompt(l, d) ?? "";

export default function SupportPractice({
    tickets,
    grants,
    maxHours,
}: {
    tickets: Ticket[];
    grants: {
        id: number;
        expires: string;
        active: boolean;
        ticket: number | null;
    }[];
    maxHours: number;
}) {
    const act = (action: string, data: Record<string, unknown>) =>
        router.post(`/support/${action}`, data as never, {
            preserveScroll: true,
        });
    const active = grants.find((g) => g.active);

    return (
        <AppShell active="Settings">
            <Head title="Support" />
            <div className="mb-5 flex items-center">
                <h1 className="flex-1 text-2xl font-semibold">
                    Clinic Flow support
                </h1>
                <Button
                    onClick={() =>
                        act("open", {
                            subject: ask("Subject"),
                            body: ask("Describe the problem"),
                        })
                    }
                >
                    New ticket
                </Button>
            </div>
            <Flash />
            <Card title="Support access to your workspace" className="mb-4">
                {active ? (
                    <div className="flex items-center gap-2 text-sm">
                        <Badge tone="warning">
                            Support can view (not change) until{" "}
                            {active.expires.slice(0, 16)}
                        </Badge>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                act("revoke", { grant_id: active.id })
                            }
                        >
                            End access now
                        </Button>
                    </div>
                ) : (
                    <div className="text-sm">
                        <p className="mb-2 text-muted">
                            Clinic Flow staff cannot see your workspace unless
                            you allow it. Access is read-only, time-limited and
                            every page they open is in your audit log.
                        </p>
                        <Button
                            size="sm"
                            onClick={() =>
                                act("grant", {
                                    hours: Number(
                                        ask(
                                            `For how many hours (1–${maxHours})?`,
                                            "24",
                                        ),
                                    ),
                                })
                            }
                        >
                            Allow support access
                        </Button>
                    </div>
                )}
            </Card>
            {tickets.map((t) => (
                <Card
                    key={t.id}
                    title={t.subject}
                    aside={<Badge>{t.status}</Badge>}
                    className="mb-3"
                >
                    {t.messages.map((m, i) => (
                        <p key={i} className="mb-2 text-sm">
                            <span className="text-xs text-muted">
                                {m.author === "support" ? "Clinic Flow" : "You"}{" "}
                                · {m.created_at.slice(0, 16)}
                            </span>
                            <br />
                            {m.body}
                        </p>
                    ))}
                    {t.status !== "closed" && (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                act("reply", {
                                    ticket_id: t.id,
                                    body: ask("Reply"),
                                })
                            }
                        >
                            Reply
                        </Button>
                    )}
                </Card>
            ))}
        </AppShell>
    );
}
