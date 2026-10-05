import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Message {
    id: number;
    lab: string | null;
    format: string;
    reason: string | null;
    orderId: string | null;
    received: string;
    name: string | null;
    dob: string | null;
    order_ref: string | null;
    barcode: string | null;
    results: {
        code: string;
        name: string;
        value: string;
        unit: string | null;
        flag: string | null;
        status: string;
    }[];
}

export default function LabUnmatched({
    messages,
    openOrders,
}: {
    messages: Message[];
    openOrders: { id: string; label: string }[];
}) {
    const [choice, setChoice] = useState<Record<number, string>>({});

    return (
        <AppShell active="Lab">
            <Head title="Unmatched lab results" />
            <h1 className="mb-1 text-2xl font-semibold">
                Unmatched lab results
            </h1>
            <p className="mb-5 text-sm text-muted">
                Results from connected lab systems that could not be filed
                automatically. Check the patient details carefully before
                matching — a result filed against the wrong patient is a safety
                risk.
            </p>
            <Flash />
            {messages.length === 0 && (
                <p className="text-sm text-muted">Nothing waiting.</p>
            )}
            {messages.map((m) => (
                <Card
                    key={m.id}
                    title={`${m.lab ?? "Lab system"} · ${m.received.slice(0, 16)}`}
                    aside={
                        <Badge tone="warning">{m.format.toUpperCase()}</Badge>
                    }
                    className="mb-3"
                >
                    <p className="mb-1 text-sm">
                        <b>Lab says:</b> {m.name ?? "no name"} · born{" "}
                        {m.dob ?? "—"} · order {m.order_ref ?? "—"} · sample{" "}
                        {m.barcode ?? "—"}
                    </p>
                    <p className="mb-2 text-xs text-status-warning">
                        {m.reason}
                    </p>
                    <table className="mb-3 w-full text-xs">
                        <tbody>
                            {m.results.map((r, i) => (
                                <tr
                                    key={i}
                                    className="border-t border-[#EBF0EE]"
                                >
                                    <td className="py-1">
                                        {r.name}{" "}
                                        <span className="font-mono text-muted">
                                            {r.code}
                                        </span>
                                    </td>
                                    <td>
                                        {r.value} {r.unit}
                                    </td>
                                    <td>
                                        {r.flag && (
                                            <Badge
                                                tone={
                                                    r.flag === "critical"
                                                        ? "danger"
                                                        : "warning"
                                                }
                                            >
                                                {r.flag}
                                            </Badge>
                                        )}
                                    </td>
                                    <td>
                                        {r.status === "F"
                                            ? "final"
                                            : "preliminary"}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="flex flex-wrap gap-2">
                        <select
                            aria-label="Order"
                            className="min-w-[24rem] rounded-md border border-line px-2 py-1 text-sm"
                            value={choice[m.id] ?? m.orderId ?? ""}
                            onChange={(e) =>
                                setChoice({ ...choice, [m.id]: e.target.value })
                            }
                        >
                            <option value="">
                                Choose the patient&apos;s order…
                            </option>
                            {openOrders.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                        <Button
                            size="sm"
                            disabled={!(choice[m.id] ?? m.orderId)}
                            onClick={() =>
                                window.confirm(
                                    "File these results against this patient's order?",
                                ) &&
                                router.post(
                                    `/lab/unmatched/${m.id}/match`,
                                    { order_id: choice[m.id] ?? m.orderId },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Match and apply
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                const reason = window.prompt(
                                    "Why reject this result?",
                                );
                                if (reason)
                                    router.post(
                                        `/lab/unmatched/${m.id}/reject`,
                                        { reason },
                                        { preserveScroll: true },
                                    );
                            }}
                        >
                            Reject
                        </Button>
                    </div>
                </Card>
            ))}
        </AppShell>
    );
}
