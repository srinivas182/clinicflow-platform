import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Referral {
    id: string;
    direction: string;
    patient: string;
    other: string;
    specialty: string;
    urgency: string;
    status: string;
    reason: string;
    summary: Record<string, string[]>;
    feedback: string | null;
    appointment: string | null;
    network: boolean;
}

export default function Referrals({ referrals }: { referrals: Referral[] }) {
    const act = (id: string, action: string, value?: string) =>
        router.post(
            `/referrals/${id}/${action}`,
            { value },
            { preserveScroll: true },
        );

    return (
        <AppShell active="Referrals">
            <Head title="Referrals" />
            <h1 className="mb-5 text-2xl font-semibold">Referrals</h1>
            <Flash />
            <div className="flex flex-col gap-3">
                {referrals.map((r) => (
                    <Card
                        key={r.id}
                        title={`${r.direction === "out" ? "To" : "From"} ${r.other} · ${r.patient}`}
                        aside={
                            <span className="flex gap-1">
                                <Badge
                                    tone={
                                        r.urgency === "urgent"
                                            ? "danger"
                                            : "neutral"
                                    }
                                >
                                    {r.urgency}
                                </Badge>
                                <Badge>{r.status}</Badge>
                            </span>
                        }
                    >
                        <p className="text-sm">
                            {r.specialty}: {r.reason}
                        </p>
                        {Object.entries(r.summary).map(([k, v]) => (
                            <p key={k} className="text-xs text-muted">
                                <b>{k}:</b> {v.join("; ")}
                            </p>
                        ))}
                        {r.appointment && (
                            <p className="text-xs">
                                Appointment: {r.appointment}
                            </p>
                        )}
                        {r.feedback && (
                            <p className="mt-1 text-sm">
                                Feedback: {r.feedback}
                            </p>
                        )}
                        {r.direction === "in" && (
                            <div className="mt-2 flex gap-2">
                                {r.status === "sent" && (
                                    <Button
                                        size="sm"
                                        onClick={() => act(r.id, "accept")}
                                    >
                                        Accept
                                    </Button>
                                )}
                                {r.status === "accepted" && (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            act(
                                                r.id,
                                                "book",
                                                window.prompt(
                                                    "Appointment (YYYY-MM-DD HH:MM)",
                                                ) ?? "",
                                            )
                                        }
                                    >
                                        Book
                                    </Button>
                                )}
                                {r.status === "booked" && (
                                    <Button
                                        size="sm"
                                        onClick={() => act(r.id, "seen")}
                                    >
                                        Seen
                                    </Button>
                                )}
                                {["seen", "accepted", "booked"].includes(
                                    r.status,
                                ) && (
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() =>
                                            act(
                                                r.id,
                                                "feedback",
                                                window.prompt(
                                                    "Feedback for the referring doctor",
                                                ) ?? "",
                                            )
                                        }
                                    >
                                        Send feedback
                                    </Button>
                                )}
                            </div>
                        )}
                        {r.direction === "out" && (
                            <a
                                className="mt-2 inline-block text-xs text-teal-deep"
                                href={`/referrals/${r.id}/letter`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Referral letter (PDF)
                            </a>
                        )}
                    </Card>
                ))}
            </div>
        </AppShell>
    );
}
