import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

export default function FlaggedReviews({
    flags,
}: {
    flags: {
        id: number;
        practice: string | null;
        rating: number;
        comment: string | null;
        reason: string;
    }[];
}) {
    return (
        <AdminShell active="Reviews">
            <Head title="Reported reviews" />
            <h1 className="mb-5 text-2xl font-semibold">Reported reviews</h1>
            <Flash />
            {flags.length === 0 && (
                <p className="text-sm text-muted">Nothing to decide.</p>
            )}
            {flags.map((f) => (
                <Card
                    key={f.id}
                    title={`${f.practice} · ${"★".repeat(f.rating)}`}
                    className="mb-3"
                >
                    <p className="text-sm">{f.comment ?? "(no comment)"}</p>
                    <p className="mb-2 text-xs text-muted">
                        Reported because: {f.reason}
                    </p>
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="danger"
                            onClick={() =>
                                router.post(`/admin/reviews/${f.id}`, {
                                    decision: "remove",
                                })
                            }
                        >
                            Remove comment
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.post(`/admin/reviews/${f.id}`, {
                                    decision: "keep",
                                })
                            }
                        >
                            Keep
                        </Button>
                    </div>
                </Card>
            ))}
        </AdminShell>
    );
}
