import { Head, router, useForm } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Field } from "@/components/form/Field";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Msg {
    key: string;
    label: string;
    channel: string;
    included: boolean;
    editable: boolean;
    placeholders: string[];
    subject: string | null;
    body: string;
    customised: boolean;
}

function Bar({
    label,
    used,
    limit,
    overage,
}: {
    label: string;
    used: number;
    limit: number;
    overage: number;
}) {
    const pct = limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0;
    return (
        <div className="flex-1">
            <div className="flex text-sm">
                <span className="flex-1 font-medium">{label}</span>
                <span>
                    {used} / {limit}
                </span>
            </div>
            <div className="mt-1 h-2 rounded-full bg-paper">
                <div
                    className={`h-2 rounded-full ${pct >= 100 ? "bg-status-danger" : pct >= 80 ? "bg-triage-orange" : "bg-teal"}`}
                    style={{ width: `${pct}%` }}
                />
            </div>
            <p className="mt-1 text-xs text-muted">
                Above the allowance: R{overage.toFixed(2)} per message on your
                next invoice.
            </p>
        </div>
    );
}

function Wording({ m }: { m: Msg }) {
    const [subject, setSubject] = useState(m.subject ?? "");
    const [body, setBody] = useState(m.body);

    return (
        <li
            className={`rounded-lg border border-line p-3 text-sm ${m.included ? "" : "opacity-50"}`}
        >
            <div className="flex items-center gap-2">
                <b className="flex-1">{m.label}</b>
                <Badge>{m.channel}</Badge>
                {!m.included ? (
                    <Badge>Not in your package</Badge>
                ) : m.customised ? (
                    <Badge tone="teal">Your wording</Badge>
                ) : (
                    <Badge>Standard</Badge>
                )}
            </div>
            {m.editable ? (
                <>
                    {m.channel === "email" && (
                        <input
                            aria-label="Subject"
                            className="mt-2 w-full rounded-md border border-line px-2 py-1.5"
                            value={subject}
                            onChange={(e) => setSubject(e.target.value)}
                        />
                    )}
                    <textarea
                        aria-label="Message"
                        className="mt-2 min-h-16 w-full rounded-md border border-line px-2 py-1.5"
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                    />
                    <div className="mt-1 flex items-center gap-2">
                        <span className="flex-1 text-xs text-muted">
                            You can use:{" "}
                            {m.placeholders.map((p) => `{{ ${p} }}`).join(" ")}
                        </span>
                        {m.customised && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.put(
                                        "/settings/messaging/wording",
                                        {
                                            key: m.key,
                                            channel: m.channel,
                                            reset: true,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Use standard
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.put(
                                    "/settings/messaging/wording",
                                    {
                                        key: m.key,
                                        channel: m.channel,
                                        subject,
                                        body,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Save
                        </Button>
                    </div>
                </>
            ) : (
                <p className="mt-2 whitespace-pre-line text-muted">{m.body}</p>
            )}
        </li>
    );
}

export default function MessagingSettings({
    fromName,
    replyTo,
    usage,
    messages,
}: {
    fromName: string;
    replyTo: string;
    usage: {
        sms: number;
        email: number;
        smsLimit: number;
        emailLimit: number;
        smsOverage: number;
        emailOverage: number;
    };
    messages: Msg[];
}) {
    const form = useForm({ from_name: fromName, reply_to: replyTo });

    return (
        <AppShell active="Settings">
            <Head title="Messaging" />
            <h1 className="mb-1 text-2xl font-semibold">SMS and email</h1>
            <p className="mb-5 text-sm text-muted">
                Messages are sent by Dr Business Flow on your behalf. You choose the
                name patients see and where email replies go.
            </p>
            <Flash />
            <div className="mb-4 grid grid-cols-2 gap-4">
                <Card title="Sender">
                    <div className="flex flex-col gap-3">
                        <Field
                            label="Email from-name"
                            name="from_name"
                            value={form.data.from_name}
                            onChange={(e) =>
                                form.setData("from_name", e.target.value)
                            }
                            error={form.errors.from_name}
                        />
                        <Field
                            label="Reply-to email"
                            name="reply_to"
                            type="email"
                            value={form.data.reply_to}
                            onChange={(e) =>
                                form.setData("reply_to", e.target.value)
                            }
                            error={form.errors.reply_to}
                            hint="Patients' replies go here"
                        />
                        <Button
                            onClick={() =>
                                form.put("/settings/messaging/sender", {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Save
                        </Button>
                    </div>
                </Card>
                <Card title="This month">
                    <div className="flex flex-col gap-4">
                        <Bar
                            label="SMS"
                            used={usage.sms}
                            limit={usage.smsLimit}
                            overage={usage.smsOverage}
                        />
                        <Bar
                            label="Email"
                            used={usage.email}
                            limit={usage.emailLimit}
                            overage={usage.emailOverage}
                        />
                    </div>
                </Card>
            </div>
            <Card title="Message wording">
                <ul className="flex flex-col gap-3">
                    {messages.map((m) => (
                        <Wording key={`${m.key}-${m.channel}`} m={m} />
                    ))}
                </ul>
            </Card>
        </AppShell>
    );
}
