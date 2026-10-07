import { Head, Link, router } from "@inertiajs/react";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Thread {
    id: string;
    subject: string;
    urgent: boolean;
    escalated: boolean;
    external: boolean;
    patient: string | null;
    unread: number;
}

export default function MessagesIndex({
    threads,
    colleagues,
}: {
    threads: Thread[];
    colleagues: { id: number; name: string }[];
}) {
    return (
        <AppShell active="Messages">
            <Head title="Messages" />
            <div className="mb-5 flex items-center">
                <h1 className="flex-1 text-2xl font-semibold">
                    Clinician messages
                </h1>
                <Button
                    onClick={() => {
                        const subject = window.prompt("Subject");
                        const who = window.prompt(
                            `Colleague:\n${colleagues.map((c, i) => `${i + 1}. ${c.name}`).join("\n")}\nNumber?`,
                        );
                        const body = window.prompt("Message");
                        const c = colleagues[Number(who) - 1];
                        if (subject && c && body)
                            router.post("/messages", {
                                subject,
                                staff_ids: [c.id],
                                body,
                            });
                    }}
                >
                    New message
                </Button>
            </div>
            <p className="mb-4 text-sm text-muted">
                Messages are encrypted and cannot be edited or deleted; send a
                correction instead. File important advice to the patient record.
            </p>
            <Card>
                {threads.length === 0 && (
                    <p className="text-sm text-muted">No conversations yet.</p>
                )}
                <ul className="divide-y divide-line-soft text-sm">
                    {threads.map((t) => (
                        <li key={t.id} className="flex items-center gap-2 py-3">
                            <Link
                                href={`/messages/${t.id}`}
                                className="flex-1 font-medium text-teal-deep"
                            >
                                {t.subject}
                                {t.patient && (
                                    <span className="ml-2 text-xs text-muted">
                                        · {t.patient}
                                    </span>
                                )}
                            </Link>
                            {t.external && <Badge>other practice</Badge>}
                            {t.urgent && <Badge tone="danger">urgent</Badge>}
                            {t.escalated && (
                                <Badge tone="danger">escalated</Badge>
                            )}
                            {t.unread > 0 && (
                                <Badge tone="teal">{t.unread} new</Badge>
                            )}
                        </li>
                    ))}
                </ul>
            </Card>
        </AppShell>
    );
}
