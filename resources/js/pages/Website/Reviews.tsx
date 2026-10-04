import { Head, router, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Review {
    id: number;
    rating: number;
    comment: string | null;
    doctor: string | null;
    publicOk: boolean;
    reply: string | null;
    flagged: boolean;
    date: string;
}
interface Props {
    reviews: Review[];
    average: number;
    count: number;
    requested: number;
    settings: { enabled: boolean; public: boolean };
}

export default function Reviews({
    reviews,
    average,
    count,
    requested,
    settings,
}: Props) {
    const form = useForm({
        enabled: settings.enabled,
        public: settings.public,
        legal_confirmed: settings.public,
    });

    return (
        <AppShell active="Settings">
            <Head title="Patient feedback" />
            <h1 className="mb-1 text-2xl font-semibold">Patient feedback</h1>
            <p className="mb-5 text-sm text-muted">
                {count} responses from {requested} requests · average{" "}
                {average || "–"} / 5. Feedback is private to your practice
                unless you switch on public display.
            </p>
            <Flash />
            <Card title="Settings" className="mb-4">
                <label className="mb-2 flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="accent-teal"
                        checked={form.data.enabled}
                        onChange={(e) =>
                            form.setData("enabled", e.target.checked)
                        }
                    />{" "}
                    Ask patients for feedback the day after their visit
                </label>
                <label className="mb-2 flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="accent-teal"
                        checked={form.data.public}
                        onChange={(e) =>
                            form.setData("public", e.target.checked)
                        }
                    />{" "}
                    Show feedback on our website (only where the patient agreed)
                </label>
                {form.data.public && (
                    <label className="mb-2 flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="mt-1 accent-teal"
                            checked={form.data.legal_confirmed}
                            onChange={(e) =>
                                form.setData(
                                    "legal_confirmed",
                                    e.target.checked,
                                )
                            }
                        />
                        Our legal reviewer has approved showing patient feedback
                        publicly in line with HPCSA advertising rules.
                    </label>
                )}
                {form.errors.legal_confirmed && (
                    <p className="mb-2 text-xs text-status-danger">
                        {form.errors.legal_confirmed}
                    </p>
                )}
                <Button
                    size="sm"
                    onClick={() =>
                        form.put("/settings/website/feedback", {
                            preserveScroll: true,
                        })
                    }
                >
                    Save
                </Button>
            </Card>
            <div className="flex flex-col gap-3">
                {reviews.map((r) => (
                    <Card
                        key={r.id}
                        title={`${"★".repeat(r.rating)}${"☆".repeat(5 - r.rating)} · ${r.date}`}
                        aside={
                            <span className="flex gap-1">
                                {r.doctor && <Badge>{r.doctor}</Badge>}
                                {r.publicOk && (
                                    <Badge tone="teal">may be public</Badge>
                                )}
                                {r.flagged && (
                                    <Badge tone="danger">reported</Badge>
                                )}
                            </span>
                        }
                    >
                        {r.comment && (
                            <p className="mb-2 text-sm">{r.comment}</p>
                        )}
                        {r.reply && (
                            <p className="mb-2 text-sm text-muted">
                                Your reply: {r.reply}
                            </p>
                        )}
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => {
                                    const reply = window.prompt(
                                        "Reply",
                                        r.reply ?? "",
                                    );
                                    if (reply)
                                        router.post(
                                            `/settings/website/feedback/${r.id}/reply`,
                                            { reply },
                                            { preserveScroll: true },
                                        );
                                }}
                            >
                                Reply
                            </Button>
                            {!r.flagged && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        const reason = window.prompt(
                                            "Why is this review abusive?",
                                        );
                                        if (reason)
                                            router.post(
                                                `/settings/website/feedback/${r.id}/flag`,
                                                { reason },
                                                { preserveScroll: true },
                                            );
                                    }}
                                >
                                    Report abuse
                                </Button>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
        </AppShell>
    );
}
