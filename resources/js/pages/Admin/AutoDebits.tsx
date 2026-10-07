import { Head } from "@inertiajs/react";
import { Badge, Card, Kpi } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface Mandate {
    provider: string;
    gateway: string;
    mode: string;
    card: string;
    status: string;
    failures: number;
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
            <h1 className="mb-1 text-2xl font-semibold">
                Automatic subscription payments
            </h1>
            <p className="mb-5 text-sm text-muted">
                Saved cards are held by the gateway; Clinic Flow keeps only an
                encrypted token, the card brand and last four digits.
            </p>
            <div className="mb-4 flex gap-4">
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
                        {mandates.map((m, i) => (
                            <tr key={i} className="border-t border-line-soft">
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
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </AdminShell>
    );
}
