import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";

interface Props {
    suppliers: {
        id: number;
        name: string;
        email: string | null;
        vat_number: string | null;
    }[];
    orders: {
        id: number;
        number: string;
        supplier: string;
        status: string;
        total: number;
        vat: number;
        lines: {
            id: number;
            description: string;
            quantity: number;
            received_quantity: number;
        }[];
    }[];
    reorder: {
        id: number;
        medicine_id: number;
        name: string;
        onHand: number;
        reorderLevel: number;
    }[];
    stock: { id: number; name: string; onHand: number }[];
}
const ask = (l: string, d = "") => window.prompt(l, d) ?? "";

export default function Procurement({
    suppliers,
    orders,
    reorder,
    stock,
}: Props) {
    const post = (action: string, data: Record<string, unknown>) =>
        router.post(`/procurement/${action}`, data as never, {
            preserveScroll: true,
        });

    return (
        <AppShell active="Pharmacy">
            <Head title="Stock and ordering" />
            <div className="mb-5 flex items-center gap-2">
                <h1 className="flex-1 text-2xl font-semibold">
                    Stock and ordering
                </h1>
                <Button
                    variant="secondary"
                    onClick={() =>
                        post("supplier", {
                            name: ask("Supplier name"),
                            email: ask("Email"),
                            vat_number: ask("VAT number (optional)") || null,
                        })
                    }
                >
                    Add supplier
                </Button>
                <Button
                    variant="secondary"
                    onClick={() => post("write-off-expired", {})}
                >
                    Write off expired
                </Button>
            </div>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                <Card title="Below reorder level">
                    {reorder.length === 0 && (
                        <p className="text-sm text-muted">
                            All items above their reorder level.
                        </p>
                    )}
                    {reorder.map((r) => (
                        <div
                            key={r.id}
                            className="flex items-center gap-2 py-1 text-sm"
                        >
                            <span className="flex-1">{r.name}</span>
                            <Badge tone="warning">
                                {r.onHand} / {r.reorderLevel}
                            </Badge>
                            {suppliers[0] && (
                                <button
                                    className="text-xs text-teal-deep"
                                    onClick={() =>
                                        post("order", {
                                            supplier_id: Number(
                                                ask(
                                                    `Supplier:\n${suppliers.map((s) => `${s.id}. ${s.name}`).join("\n")}`,
                                                    String(suppliers[0]?.id),
                                                ),
                                            ),
                                            lines: [
                                                {
                                                    medicine_id: r.medicine_id,
                                                    quantity: Number(
                                                        ask(
                                                            "Quantity",
                                                            String(
                                                                r.reorderLevel *
                                                                    2,
                                                            ),
                                                        ),
                                                    ),
                                                    unit_cost: Number(
                                                        ask(
                                                            "Unit cost (R, excl. VAT)",
                                                        ),
                                                    ),
                                                },
                                            ],
                                        })
                                    }
                                >
                                    order
                                </button>
                            )}
                        </div>
                    ))}
                </Card>
                <Card title="Stock take">
                    {stock.map((s) => (
                        <div
                            key={s.id}
                            className="flex items-center gap-2 py-0.5 text-sm"
                        >
                            <span className="flex-1">{s.name}</span>
                            <span className="w-12 text-right">{s.onHand}</span>
                            <button
                                className="text-xs text-teal-deep"
                                onClick={() =>
                                    post("stock-take", {
                                        stock_item_id: s.id,
                                        counted: Number(
                                            ask(
                                                `Counted ${s.name}`,
                                                String(s.onHand),
                                            ),
                                        ),
                                        reason: ask(
                                            "Reason for any difference",
                                        ),
                                    })
                                }
                            >
                                count
                            </button>
                        </div>
                    ))}
                </Card>
            </div>
            <h2 className="mt-6 mb-3 text-lg font-semibold">Purchase orders</h2>
            <div className="flex flex-col gap-3">
                {orders.map((o) => (
                    <Card
                        key={o.id}
                        title={`${o.number} · ${o.supplier}`}
                        aside={
                            <span className="flex gap-1">
                                <Badge>{o.status}</Badge>
                                <Badge>
                                    {rand(o.total, 2)} + VAT {rand(o.vat, 2)}
                                </Badge>
                            </span>
                        }
                    >
                        {o.lines.map((l) => (
                            <div
                                key={l.id}
                                className="flex items-center gap-2 text-sm"
                            >
                                <span className="flex-1">{l.description}</span>
                                <span>
                                    {l.received_quantity}/{l.quantity}
                                </span>
                                {["sent", "partial"].includes(o.status) &&
                                    l.received_quantity < l.quantity && (
                                        <button
                                            className="text-xs text-teal-deep"
                                            onClick={() =>
                                                post("receive", {
                                                    order_id: o.id,
                                                    receipts: [
                                                        {
                                                            line_id: l.id,
                                                            quantity: Number(
                                                                ask(
                                                                    "Quantity received",
                                                                    String(
                                                                        l.quantity -
                                                                            l.received_quantity,
                                                                    ),
                                                                ),
                                                            ),
                                                            batch: ask(
                                                                "Batch number",
                                                            ),
                                                            expiry: ask(
                                                                "Expiry (YYYY-MM-DD)",
                                                            ),
                                                        },
                                                    ],
                                                })
                                            }
                                        >
                                            receive
                                        </button>
                                    )}
                            </div>
                        ))}
                        {o.status === "draft" && (
                            <Button
                                className="mt-2"
                                size="sm"
                                onClick={() => post("send", { order_id: o.id })}
                            >
                                Send to supplier
                            </Button>
                        )}
                    </Card>
                ))}
            </div>
        </AppShell>
    );
}
