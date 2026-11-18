import { Head, useForm } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Check {
    id: number;
    label: string;
    reference: string | null;
    status: "pending" | "verified" | "rejected";
    reason: string | null;
    resubmitted: string | null;
    document?: string | null;
    documentAt?: string | null;
}

/** Upload the document behind a check (PDF, JPG or PNG up to 10 MB; virus-scanned). */
function Upload({ check }: { check: Check }) {
    const form = useForm<{ file: File | null }>({ file: null });
    return (
        <form
            className="mt-2 flex flex-wrap items-center gap-2 text-xs"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/settings/verification/${check.id}/document`, {
                    preserveScroll: true,
                    forceFormData: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <span className="text-muted">
                {check.document
                    ? `Document: ${check.document} (${check.documentAt})`
                    : "No document uploaded yet."}
            </span>
            <input
                type="file"
                aria-label={`Document for ${check.label}`}
                accept="application/pdf,image/jpeg,image/png"
                className="min-w-0 text-xs"
                onChange={(e) =>
                    form.setData("file", e.target.files?.[0] ?? null)
                }
            />
            <Button
                size="sm"
                variant="secondary"
                type="submit"
                disabled={!form.data.file || form.processing}
            >
                {check.document ? "Replace" : "Upload"}
            </Button>
            {form.errors.file && (
                <span role="alert" className="w-full text-status-danger">
                    {form.errors.file}
                </span>
            )}
        </form>
    );
}

const tone = {
    pending: "warning",
    verified: "success",
    rejected: "danger",
} as const;

/** One rejected check: correct the number and send it back. */
function Resubmit({ check }: { check: Check }) {
    const form = useForm({ reference: check.reference ?? "" });
    return (
        <form
            className="mt-3 flex flex-wrap items-end gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/settings/verification/${check.id}`, {
                    preserveScroll: true,
                });
            }}
        >
            <label className="min-w-0 flex-1 basis-48 text-xs font-medium">
                Corrected number
                <input
                    className="mt-1 w-full rounded-md border border-line px-2 py-1.5 text-sm"
                    value={form.data.reference}
                    maxLength={80}
                    onChange={(e) => form.setData("reference", e.target.value)}
                />
            </label>
            <Button size="sm" type="submit" disabled={form.processing}>
                Send back for review
            </Button>
            {form.errors.reference && (
                <p role="alert" className="w-full text-xs text-status-danger">
                    {form.errors.reference}
                </p>
            )}
        </form>
    );
}

/** Settings → Verification: the practice's registration checks, the reviewer's reasons and resubmission. */
export default function Verification({
    status,
    checks,
}: {
    status: string;
    checks: Check[];
}) {
    const [open, setOpen] = useState<number | null>(null);
    return (
        <AppShell active="Verification">
            <Head title="Verification" />
            <h1 className="text-2xl font-semibold">Verification</h1>
            <p className="mt-1 mb-5 text-sm text-muted">
                {status === "pending_verification"
                    ? "Your practice goes live once every check below is verified."
                    : status === "suspended"
                      ? "Your workspace is read-only until the rejected check below is corrected and verified."
                      : "The registration details we checked for your practice."}
            </p>
            <Flash />
            <Card title="Registration checks">
                <ul className="divide-y divide-line-soft text-sm">
                    {checks.map((c) => (
                        <li key={c.id} className="py-3">
                            <div className="flex flex-wrap items-center gap-3">
                                <span className="min-w-0 flex-1">
                                    <span className="font-medium">
                                        {c.label}
                                    </span>
                                    <span className="block text-xs text-muted break-words">
                                        {c.reference ?? "Not supplied"}
                                    </span>
                                </span>
                                <Badge tone={tone[c.status]}>
                                    {c.status === "pending" && c.resubmitted
                                        ? "sent back for review"
                                        : c.status}
                                </Badge>
                            </div>
                            {c.status !== "verified" && <Upload check={c} />}
                            {c.status === "rejected" && (
                                <div className="mt-2 rounded-md bg-paper p-3">
                                    <p className="text-sm">
                                        <b>Reason:</b> {c.reason}
                                    </p>
                                    {open === c.id ? (
                                        <Resubmit check={c} />
                                    ) : (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            className="mt-2"
                                            onClick={() => setOpen(c.id)}
                                        >
                                            Edit and resubmit
                                        </Button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </Card>
        </AppShell>
    );
}
