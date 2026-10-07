import { Head } from "@inertiajs/react";
import { Card, Kpi } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";

interface DoctorRow {
    doctor: string;
    consultation: number;
    procedure: number;
    medicine: number;
    lab: number;
    other: number;
    total: number;
}

export default function FinanceDashboard({
    takings,
    byDoctor,
    bySource,
    claimsAgeing,
    cashUps,
    messagesThisMonth,
}: {
    takings: Record<string, number>;
    byDoctor: DoctorRow[];
    bySource: Record<string, number>;
    claimsAgeing: Record<string, number>;
    cashUps: {
        staff: string | null;
        day: string;
        difference: number;
        reason: string | null;
    }[];
    messagesThisMonth: number;
}) {
    const today = Object.values(takings).reduce((a, b) => a + b, 0);
    const month = Object.values(bySource).reduce((a, b) => a + b, 0);
    const unpaidClaims = Object.values(claimsAgeing).reduce((a, b) => a + b, 0);

    return (
        <AppShell active="Finance">
            <Head title="Finance" />
            <h1 className="mb-5 text-2xl font-semibold">Finance</h1>
            <div className="mb-4 flex gap-4">
                <Kpi
                    label="Takings today"
                    value={rand(today)}
                    hint={
                        Object.entries(takings)
                            .map(
                                ([m, v]) => `${m.replace("_", " ")} ${rand(v)}`,
                            )
                            .join(" · ") || "No payments yet"
                    }
                />
                <Kpi label="Billed this month" value={rand(month)} />
                <Kpi
                    label="Unpaid claims"
                    value={rand(unpaidClaims)}
                    hint={`90+ days: ${rand(claimsAgeing["90+"] ?? 0)}`}
                    trend={(claimsAgeing["90+"] ?? 0) > 0 ? "down" : undefined}
                />
                <Kpi
                    label="Messages this month"
                    value={String(messagesThisMonth)}
                    hint="Email + SMS, one allowance"
                />
            </div>
            <Card title="Revenue by doctor — this month" className="mb-4">
                <table className="w-full text-sm">
                    <thead className="text-left text-xs text-muted">
                        <tr>
                            {[
                                "Doctor",
                                "Consults",
                                "Procedures",
                                "Medicines",
                                "Lab",
                                "Other",
                                "Total",
                            ].map((h) => (
                                <th
                                    scope="col"
                                    key={h}
                                    className="py-2 font-medium"
                                >
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {byDoctor.map((r) => (
                            <tr
                                key={r.doctor}
                                className="border-t border-line-soft"
                            >
                                <td className="py-2 font-medium">{r.doctor}</td>
                                <td>{rand(r.consultation)}</td>
                                <td>{rand(r.procedure)}</td>
                                <td>{rand(r.medicine)}</td>
                                <td>{rand(r.lab)}</td>
                                <td>{rand(r.other)}</td>
                                <td className="font-semibold">
                                    {rand(r.total)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <p className="mt-2 text-xs text-muted">
                    Medicines count for the prescribing doctor and lab tests for
                    the ordering doctor (in-house only).
                </p>
            </Card>
            <Card title="Recent cash-ups">
                <ul className="space-y-1 text-sm">
                    {cashUps.map((c, i) => (
                        <li key={i} className="flex gap-2">
                            <span className="w-28">{c.day}</span>
                            <span className="flex-1">{c.staff}</span>
                            <span
                                className={
                                    c.difference !== 0
                                        ? "font-semibold text-status-danger"
                                        : "text-status-success"
                                }
                            >
                                {c.difference === 0
                                    ? "Balanced"
                                    : rand(c.difference, 2)}
                            </span>
                            {c.reason && (
                                <span className="text-xs text-muted">
                                    {c.reason}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            </Card>
        </AppShell>
    );
}
