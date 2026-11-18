import { Head, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";
import { statusTone } from "./Index";

interface Check {
    id: number;
    label: string;
    reference: string | null;
    status: string;
    notes: string | null;
    resubmitted: string | null;
}

/**
 * Super admin: one practice's registration checks. Approve, Verify and Reject ask for confirmation;
 * a rejection needs a reason (emailed to the practice) and, on a live practice, can suspend it.
 */
export default function ProviderShow({
    provider,
    checks,
}: {
    provider: {
        id: string;
        name: string;
        type: string;
        status: string;
        canApprove: boolean;
        live: boolean;
    };
    checks: Check[];
}) {
    const errors = usePage<{ errors: Record<string, string> }>().props.errors;
    const [rejecting, setRejecting] = useState<number | null>(null);
    const [reason, setReason] = useState("");
    const [suspend, setSuspend] = useState(false);

    const verify = (c: Check) =>
        window.confirm(`Mark ${c.label} as verified?`) &&
        router.post(
            `/admin/verification-checks/${c.id}`,
            { status: "verified" },
            { preserveScroll: true },
        );
    const reject = (c: Check) =>
        window.confirm(
            `Reject ${c.label}? The practice is emailed the reason${suspend ? " and its workspace becomes read-only" : ""}.`,
        ) &&
        router.post(
            `/admin/verification-checks/${c.id}`,
            { status: "rejected", notes: reason, suspend },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejecting(null);
                    setReason("");
                    setSuspend(false);
                },
            },
        );

    return (
        <AdminShell active="Verification">
            <Head title={provider.name} />
            <div className="mb-5 flex flex-wrap items-end gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">{provider.name}</h1>
                    <p className="text-sm text-muted">{provider.type}</p>
                </div>
                <Badge tone={statusTone[provider.status] ?? "neutral"}>
                    {provider.status.replace("_", " ")}
                </Badge>
                {provider.canApprove && (
                    <div className="ml-auto">
                        <Button
                            onClick={() =>
                                window.confirm(
                                    `Approve ${provider.name}? It is listed in the directory, bookings open and the owner is emailed.`,
                                ) &&
                                router.post(
                                    `/admin/providers/${provider.id}/approve`,
                                )
                            }
                        >
                            Approve — list and open bookings
                        </Button>
                    </div>
                )}
            </div>
            <Flash />
            {(errors.verification || errors.notes) && (
                <p role="alert" className="mb-3 text-sm text-status-danger">
                    {errors.verification ?? errors.notes}
                </p>
            )}
            <Card title="Registration checks">
                <ul className="divide-y divide-line-soft text-sm">
                    {checks.map((c) => (
                        <li key={c.id} className="py-3">
                            <div className="flex flex-wrap items-center gap-3">
                                <span className="min-w-0 flex-1 font-medium">
                                    {c.label}
                                    <span className="block text-xs font-normal text-muted break-words">
                                        {c.reference ?? "Not supplied"}
                                        {c.resubmitted &&
                                            ` · corrected by the practice on ${c.resubmitted}`}
                                    </span>
                                    {c.notes && (
                                        <span className="block text-xs font-normal text-muted">
                                            Reason given: {c.notes}
                                        </span>
                                    )}
                                </span>
                                <Badge
                                    tone={
                                        c.status === "verified"
                                            ? "success"
                                            : c.status === "rejected"
                                              ? "danger"
                                              : "warning"
                                    }
                                >
                                    {c.status}
                                </Badge>
                                <span className="inline-flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() =>
                                            setRejecting(
                                                rejecting === c.id
                                                    ? null
                                                    : c.id,
                                            )
                                        }
                                    >
                                        Reject
                                    </Button>
                                    <Button size="sm" onClick={() => verify(c)}>
                                        Verify
                                    </Button>
                                </span>
                            </div>
                            {rejecting === c.id && (
                                <div className="mt-3 grid gap-2">
                                    <label className="text-xs font-medium">
                                        Reason (shown to the practice)
                                        <textarea
                                            className="mt-1 w-full rounded-md border border-line px-2 py-1 text-sm"
                                            rows={2}
                                            maxLength={500}
                                            value={reason}
                                            onChange={(e) =>
                                                setReason(e.target.value)
                                            }
                                        />
                                    </label>
                                    {provider.live && (
                                        <label className="flex items-center gap-2 text-xs">
                                            <input
                                                type="checkbox"
                                                className="accent-teal"
                                                checked={suspend}
                                                onChange={(e) =>
                                                    setSuspend(e.target.checked)
                                                }
                                            />
                                            This practice is live: also make it
                                            read-only and unlisted until the
                                            check is verified again
                                        </label>
                                    )}
                                    <div>
                                        <Button
                                            size="sm"
                                            disabled={reason.trim() === ""}
                                            onClick={() => reject(c)}
                                        >
                                            Reject with this reason
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </Card>
        </AdminShell>
    );
}
