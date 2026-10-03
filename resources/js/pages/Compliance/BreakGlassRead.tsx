import { Head } from "@inertiajs/react";
import { Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

export default function BreakGlassRead({
    patient,
    threads,
}: {
    patient: string;
    threads: {
        subject: string;
        messages: {
            from: string;
            at: string;
            body: string;
            corrects: number | null;
        }[];
    }[];
}) {
    return (
        <AppShell active="Compliance">
            <Head title="Review (read-only)" />
            <h1 className="mb-1 text-2xl font-semibold">
                Read-only review · {patient}
            </h1>
            <p className="mb-4 text-sm text-muted">
                This access is logged and visible to the patient and the
                clinicians involved.
            </p>
            {threads.map((t, i) => (
                <Card key={i} title={t.subject} className="mb-3">
                    {t.messages.map((m, j) => (
                        <p key={j} className="mb-2 text-sm">
                            <span className="text-xs text-muted">
                                {m.from} · {m.at}
                                {m.corrects
                                    ? ` · correction to #${m.corrects}`
                                    : ""}
                            </span>
                            <br />
                            {m.body}
                        </p>
                    ))}
                </Card>
            ))}
        </AppShell>
    );
}
