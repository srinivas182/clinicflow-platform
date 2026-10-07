import { Head, Link } from "@inertiajs/react";
import { Badge, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

export default function ChatIndex({
    threads,
}: {
    threads: {
        id: number;
        patient: string;
        kind: string;
        open: boolean;
        closes: string;
    }[];
}) {
    return (
        <AppShell active="Chats">
            <Head title="Chats" />
            <h1 className="mb-5 text-2xl font-semibold">Chats</h1>
            <Card>
                {threads.length === 0 && (
                    <p className="text-sm text-muted">No open chats.</p>
                )}
                <ul className="divide-y divide-line-soft text-sm">
                    {threads.map((t) => (
                        <li key={t.id} className="flex items-center gap-3 py-3">
                            <Link
                                href={`/chats/${t.id}`}
                                className="flex-1 font-medium text-teal-deep"
                            >
                                {t.patient}
                            </Link>
                            <Badge>
                                {t.kind === "consult"
                                    ? "Chat consult"
                                    : "Follow-up"}
                            </Badge>
                            <Badge tone={t.open ? "success" : "neutral"}>
                                {t.open ? "open" : "not yet open"}
                            </Badge>
                            <span className="text-xs text-muted">
                                until {t.closes}
                            </span>
                        </li>
                    ))}
                </ul>
            </Card>
        </AppShell>
    );
}
