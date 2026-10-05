import { Head, useForm, usePoll, router } from "@inertiajs/react";
import { scribePost } from "@/lib/scribe";
import type { FormEvent } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";

interface Props {
    thread: {
        id: number;
        kind: string;
        open: boolean;
        opensAt: string;
        closesAt: string;
        other: string;
    };
    me: "doctor" | "patient";
    postUrl: string;
    messages: { id: number; sender: string; body: string; at: string }[];
    scribe?: {
        appointmentId: string;
        session: { id: string; status: string } | null;
        enabled: boolean;
    } | null;
}

export default function Thread({
    thread,
    me,
    postUrl,
    messages,
    scribe,
}: Props) {
    usePoll(4000, { only: ["messages", "thread"] });
    const form = useForm({ body: "" });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(postUrl, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const status = scribe?.session?.status ?? null;
    const reload = () => router.reload({ only: ["scribe"] });
    const scribeBar =
        scribe && scribe.enabled ? (
            me === "doctor" ? (
                <div className="mb-3 flex items-center gap-2 text-sm">
                    {!status ||
                    ["declined", "discarded", "accepted", "failed"].includes(
                        status,
                    ) ? (
                        <button
                            className="rounded border border-line px-3 py-1"
                            onClick={() =>
                                scribePost(
                                    `/appointments/${scribe.appointmentId}/scribe/request`,
                                    { source: "chat" },
                                ).then(reload)
                            }
                        >
                            AI scribe: draft note from chat
                        </button>
                    ) : status === "awaiting" ? (
                        <span className="text-muted">
                            Waiting for the patient to agree…{" "}
                            <button className="text-teal-deep" onClick={reload}>
                                refresh
                            </button>
                        </span>
                    ) : status === "created" && scribe.session ? (
                        <button
                            className="rounded bg-teal px-3 py-1 text-white"
                            onClick={() =>
                                scribePost(
                                    `/scribe/${scribe.session?.id}/chat`,
                                ).then(reload)
                            }
                        >
                            Patient agreed — write draft
                        </button>
                    ) : status === "drafted" ? (
                        <span className="text-muted">
                            Draft ready — review it in the consultation note.
                        </span>
                    ) : null}
                    {status === "declined" && (
                        <span className="text-xs text-muted">
                            The patient declined.
                        </span>
                    )}
                </div>
            ) : status === "awaiting" && scribe.session ? (
                <div className="mb-3 rounded-lg bg-paper p-3 text-sm">
                    Your doctor would like to use an AI scribe to help write
                    your notes from this chat. Your doctor checks everything. It
                    is your choice.
                    <div className="mt-2 flex gap-2">
                        <button
                            className="rounded bg-teal px-3 py-1 text-white"
                            onClick={() =>
                                scribePost(
                                    `/my/scribe/${scribe.session?.id}/agree`,
                                ).then(reload)
                            }
                        >
                            Agree
                        </button>
                        <button
                            className="rounded border border-line px-3 py-1"
                            onClick={() =>
                                scribePost(
                                    `/my/scribe/${scribe.session?.id}/decline`,
                                ).then(reload)
                            }
                        >
                            Decline
                        </button>
                    </div>
                </div>
            ) : null
        ) : null;

    return (
        <div className="mx-auto flex min-h-screen max-w-2xl flex-col px-4 py-6">
            <Head title={`Chat with ${thread.other}`} />
            <div className="mb-3 flex items-center gap-2">
                <h1 className="text-xl font-semibold">
                    {thread.kind === "consult"
                        ? "Chat consult"
                        : "Follow-up questions"}{" "}
                    · {thread.other}
                </h1>
                <Badge tone={thread.open ? "success" : "neutral"}>
                    {thread.open
                        ? `open until ${thread.closesAt.slice(11, 16)}`
                        : "closed"}
                </Badge>
            </div>
            <Flash />
            <Card className="mb-3 flex-1">
                {scribeBar}
                <ul className="flex flex-col gap-2">
                    {messages.length === 0 && (
                        <li className="text-sm text-muted">No messages yet.</li>
                    )}
                    {messages.map((m) => (
                        <li
                            key={m.id}
                            className={`max-w-[80%] rounded-xl px-3 py-2 text-sm ${m.sender === me ? "self-end bg-teal text-white" : "self-start bg-paper"}`}
                        >
                            {m.body}
                            <span className="ml-2 text-[10px] opacity-70">
                                {m.at}
                            </span>
                        </li>
                    ))}
                </ul>
            </Card>
            {thread.open ? (
                <form onSubmit={submit} className="flex gap-2">
                    <textarea
                        aria-label="Message"
                        className="min-h-12 flex-1 rounded-lg border border-line px-3 py-2 text-sm"
                        value={form.data.body}
                        onChange={(e) => form.setData("body", e.target.value)}
                    />
                    <Button
                        type="submit"
                        disabled={
                            form.processing || form.data.body.trim() === ""
                        }
                    >
                        Send
                    </Button>
                </form>
            ) : (
                <p className="text-sm text-muted">
                    This chat is closed.{" "}
                    {me === "patient"
                        ? "Book another consult if you need more help."
                        : ""}
                </p>
            )}
            {form.errors.body && (
                <p role="alert" className="mt-1 text-xs text-status-danger">
                    {form.errors.body}
                </p>
            )}
        </div>
    );
}
