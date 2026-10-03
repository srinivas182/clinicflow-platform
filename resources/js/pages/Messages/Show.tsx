import { Head, router, useForm } from "@inertiajs/react";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Message {
    id: number;
    from: string;
    at: string;
    body: string;
    corrects: number | null;
    visible: boolean;
    filed: boolean;
    attachment: boolean;
}

export default function MessagesShow({
    thread,
    messages,
    canPost,
}: {
    thread: {
        id: string;
        subject: string;
        urgent: boolean;
        external: boolean;
        patient: string | null;
    };
    messages: Message[];
    canPost: boolean;
}) {
    const form = useForm({
        body: "",
        visible_to_patient: false,
        corrects_id: null as number | null,
    });

    return (
        <AppShell active="Messages">
            <Head title={thread.subject} />
            <h1 className="mb-1 text-2xl font-semibold">{thread.subject}</h1>
            <p className="mb-4 text-sm text-muted">
                {thread.patient ? `About ${thread.patient}` : "General"}{" "}
                {thread.external && <Badge>with another practice</Badge>}{" "}
                {thread.urgent && <Badge tone="danger">urgent</Badge>}
            </p>
            <Card className="mb-3">
                <ul className="flex flex-col gap-3 text-sm">
                    {messages.map((m) => (
                        <li
                            key={m.id}
                            className="rounded-lg border border-line p-3"
                        >
                            <div className="mb-1 flex gap-2 text-xs text-muted">
                                <span className="flex-1">
                                    {m.from} · {m.at}
                                </span>
                                {m.corrects && (
                                    <Badge tone="warning">
                                        correction to #{m.corrects}
                                    </Badge>
                                )}
                                {m.visible && (
                                    <Badge tone="teal">
                                        visible to patient
                                    </Badge>
                                )}
                                {m.filed ? (
                                    <Badge tone="success">filed</Badge>
                                ) : (
                                    <button
                                        className="text-teal-deep"
                                        onClick={() =>
                                            router.post(
                                                `/thread-messages/${m.id}/file`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        file to record
                                    </button>
                                )}
                                <button
                                    className="text-muted"
                                    onClick={() =>
                                        form.setData("corrects_id", m.id)
                                    }
                                >
                                    correct
                                </button>
                            </div>
                            <p className="whitespace-pre-wrap">
                                #{m.id} {m.body}
                            </p>
                        </li>
                    ))}
                </ul>
            </Card>
            {canPost && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/messages/${thread.id}`, {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                    className="flex flex-col gap-2"
                >
                    {form.data.corrects_id && (
                        <p className="text-xs">
                            Correcting message #{form.data.corrects_id}
                        </p>
                    )}
                    <textarea
                        aria-label="Message"
                        className="min-h-20 rounded-lg border border-line px-3 py-2 text-sm"
                        value={form.data.body}
                        onChange={(e) => form.setData("body", e.target.value)}
                    />
                    <label className="text-sm">
                        <input
                            type="checkbox"
                            className="accent-teal"
                            checked={form.data.visible_to_patient}
                            onChange={(e) =>
                                form.setData(
                                    "visible_to_patient",
                                    e.target.checked,
                                )
                            }
                        />{" "}
                        Visible to the patient
                    </label>
                    <Button
                        type="submit"
                        disabled={
                            form.processing || form.data.body.trim() === ""
                        }
                    >
                        Send
                    </Button>
                </form>
            )}
        </AppShell>
    );
}
