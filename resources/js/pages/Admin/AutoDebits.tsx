import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Card, Kpi } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface Mandate {
    provider: string;
    gateway: string;
    mode: string;
    card: string;
    status: string;
    failures: number;
    id: number;
    lastFailure: { reason: string | null; at: string } | null;
    openInvoice: { number: string; due: string; total: number } | null;
    revokeNote: string | null;
    lastCharged: string | null;
    since: string;
}

export default function AutoDebits({
    mandates,
    failedRecently,
}: {
    mandates: Mandate[];
    failedRecently: number;
}) {
    const active = mandates.filter((m) => m.status === "active").length;

    return (
        <AdminShell active="Auto-debit">
            <Head title="Auto-debit" />
            <Flash />
            <h1 className="mb-1 text-2xl font-semibold">
                Automatic subscription payments
            </h1>
            <p className="mb-5 text-sm text-muted">
                Saved cards are held by the gateway; Dr Business Flow keeps only
                an encrypted token, the card brand and last four digits.
            </p>
            <div className="mb-4 flex flex-wrap gap-4">
                <Kpi label="Providers on auto-debit" value={String(active)} />
                <Kpi
                    label="Failed debits (14 days)"
                    value={String(failedRecently)}
                    trend={failedRecently > 0 ? "down" : undefined}
                />
            </div>
            <Card>
                <table className="w-full text-sm">
                    <thead className="text-left text-xs text-muted">
                        <tr>
                            <th scope="col" className="py-2 font-medium">
                                Provider
                            </th>
                            <th scope="col" className="py-2 font-medium">
                                Gateway
                            </th>
                            <th scope="col" className="py-2 font-medium">
                                Card
                            </th>
                            <th scope="col" className="py-2 font-medium">
                                Last charged
                            </th>
                            <th scope="col" className="py-2 font-medium">
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {mandates.map((m) => (
                            <tr
                                key={m.id}
                                className="border-t border-line-soft"
                            >
                                <td className="py-2.5 font-medium">
                                    {m.provider}
                                </td>
                                <td className="py-2.5">
                                    {m.gateway}{" "}
                                    <span className="text-xs text-muted">
                                        ({m.mode})
                                    </span>
                                </td>
                                <td className="py-2.5">{m.card}</td>
                                <td className="py-2.5 text-muted">
                                    {m.lastCharged ?? "—"}
                                </td>
                                <td className="py-2.5">
                                    <Badge
                                        tone={
                                            m.status === "active"
                                                ? m.failures > 0
                                                    ? "warning"
                                                    : "success"
                                                : "neutral"
                                        }
                                    >
                                        {m.status}
                                        {m.failures > 0
                                            ? ` · ${m.failures} failed`
                                            : ""}
                                    </Badge>
                                    {m.lastFailure && (
                                        <span className="mt-1 block text-xs text-status-danger">
                                            {m.lastFailure.at}:{" "}
                                            {m.lastFailure.reason ??
                                                "no reason given"}
                                        </span>
                                    )}
                                    {m.openInvoice && (
                                        <span className="block text-xs text-muted">
                                            Unpaid {m.openInvoice.number}, R
                                            {m.openInvoice.total.toFixed(2)},
                                            due {m.openInvoice.due}
                                        </span>
                                    )}
                                    {m.revokeNote && (
                                        <span className="block text-xs text-muted">
                                            {m.revokeNote}
                                        </span>
                                    )}
                                    {m.status === "active" && (
                                        <span className="mt-1 flex flex-wrap gap-2">
                                            {m.openInvoice && (
                                                <button
                                                    type="button"
                                                    className="text-xs font-medium text-teal-deep"
                                                    onClick={() =>
                                                        window.confirm(
                                                            `Charge ${m.card} R${m.openInvoice?.total.toFixed(2)} now?`,
                                                        ) &&
                                                        router.post(
                                                            `/admin/auto-debits/${m.id}/retry`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Retry now
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                className="text-xs font-medium text-status-danger"
                                                onClick={() => {
                                                    const note = window.prompt(
                                                        `Stop automatic payment for ${m.provider}? Reason (emailed to the practice):`,
                                                    );
                                                    if (note)
                                                        router.post(
                                                            `/admin/auto-debits/${m.id}/stop`,
                                                            { note },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                }}
                                            >
                                                Stop auto-debit
                                            </button>
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </AdminShell>
    );
}
