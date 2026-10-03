import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";

interface Props {
    buckets: Record<string, number>;
    patients: { id: string; name: string; balance: number; days: number }[];
    writeOffs: {
        id: number;
        invoice_id: string;
        amount_cents: number;
        reason: string;
    }[];
}

export default function Debtors({ buckets, patients, writeOffs }: Props) {
    return (
        <AppShell active="Finance">
            <Head title="Debtors" />
            <div className="mb-5 flex items-center">
                <h1 className="flex-1 text-2xl font-semibold">Debtors</h1>
                <Button
                    onClick={() => router.post("/finance/debtors/statements")}
                >
                    Send this month's statements
                </Button>
            </div>
            <Flash />
            <div className="mb-4 grid grid-cols-4 gap-3">
                {Object.entries(buckets).map(([k, v]) => (
                    <Card
                        key={k}
                        title={k === "current" ? "Current" : `${k} days`}
                    >
                        <p className="text-xl font-semibold">{rand(v, 2)}</p>
                    </Card>
                ))}
            </div>
            {writeOffs.length > 0 && (
                <Card title="Write-offs waiting for approval" className="mb-4">
                    {writeOffs.map((w) => (
                        <div
                            key={w.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <span className="flex-1">
                                {rand(w.amount_cents / 100, 2)} · {w.reason}
                            </span>
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post("/finance/debtors/approve", {
                                        write_off_id: w.id,
                                    })
                                }
                            >
                                Approve
                            </Button>
                        </div>
                    ))}
                </Card>
            )}
            <Card title="Patients who owe">
                <ul className="divide-y divide-[#EBF0EE] text-sm">
                    {patients.map((p) => (
                        <li key={p.id} className="flex py-2">
                            <span className="flex-1">{p.name}</span>
                            <span className="w-24 text-xs text-muted">
                                {p.days} days
                            </span>
                            <span className="w-28 text-right font-medium">
                                {rand(p.balance, 2)}
                            </span>
                        </li>
                    ))}
                </ul>
            </Card>
        </AppShell>
    );
}
