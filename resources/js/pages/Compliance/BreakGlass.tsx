import { Head, Link, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Review {
    id: number;
    patient: string | null;
    patientId: string;
    reason: string;
    details: string;
    mine: boolean;
    approved: boolean;
    open: boolean;
    expires: string | null;
}

export default function BreakGlass({
    reasons,
    reviews,
}: {
    reasons: Record<string, string>;
    reviews: Review[];
}) {
    return (
        <AppShell active="Compliance">
            <Head title="Conversation reviews" />
            <h1 className="mb-1 text-2xl font-semibold">
                Conversation reviews (break-glass)
            </h1>
            <p className="mb-4 text-sm text-muted">
                For complaints, claims, regulators or breaches only. A second
                senior person approves; access is read-only for 7 days;
                clinicians and the patient are told.
            </p>
            <Flash />
            <Button
                className="mb-4"
                onClick={() => {
                    const patient_id = window.prompt("Patient ID");
                    const reason = window.prompt(
                        `Reason:\n${Object.entries(reasons)
                            .map(([k, v]) => `${k} — ${v}`)
                            .join("\n")}`,
                    );
                    const details = window.prompt("Why is the review needed?");
                    if (patient_id && reason && details)
                        router.post("/compliance/break-glass/request", {
                            patient_id,
                            reason,
                            details,
                        });
                }}
            >
                Request a review
            </Button>
            <Card>
                <ul className="divide-y divide-line-soft text-sm">
                    {reviews.map((r) => (
                        <li key={r.id} className="flex items-center gap-2 py-2">
                            <span className="flex-1">
                                {r.patient} · {r.reason} · {r.details}
                            </span>
                            {r.approved ? (
                                <Badge tone="success">
                                    approved until {r.expires?.slice(0, 10)}
                                </Badge>
                            ) : (
                                <Badge tone="warning">awaiting approval</Badge>
                            )}
                            {!r.approved && !r.mine && (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            "/compliance/break-glass/approve",
                                            { review_id: r.id },
                                        )
                                    }
                                >
                                    Approve
                                </Button>
                            )}
                            {r.open && (
                                <Link
                                    href={`/compliance/break-glass/patients/${r.patientId}`}
                                >
                                    <Button size="sm" variant="secondary">
                                        Read
                                    </Button>
                                </Link>
                            )}
                        </li>
                    ))}
                </ul>
            </Card>
        </AppShell>
    );
}
