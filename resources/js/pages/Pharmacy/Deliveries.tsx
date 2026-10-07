import { Head, router, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";
import { Pager, type Paginated } from "@/components/Pager";

interface Props {
    deliveries: Paginated<{
        id: number;
        patient: string;
        driver: string;
        address: string;
        status: string;
        tracking: string | null;
        fee: number;
        payer: string;
        failure: string | null;
    }>;
    couriers: Record<string, string>;
    partners: {
        driver: string;
        label: string;
        apiReady: boolean;
        linked: boolean;
    }[];
    rules: {
        mode: string;
        fee: number;
        threshold: number;
        below_payer: string;
        above_payer: string;
        allow_scheduled: boolean;
        publish_stock: boolean;
    };
}
const ask = (l: string, d = "") => window.prompt(l, d) ?? "";
const sel = "rounded-md border border-line px-2 py-1 text-sm";

export default function Deliveries({
    deliveries,
    couriers,
    partners,
    rules,
}: Props) {
    const f = useForm({ ...rules });
    const act = (id: number, action: string, data: Record<string, string>) =>
        router.post(`/deliveries/${id}/${action}`, data, {
            preserveScroll: true,
        });

    return (
        <AppShell active="Pharmacy">
            <Head title="Deliveries" />
            <h1 className="mb-5 text-2xl font-semibold">Deliveries</h1>
            <Flash />
            <div className="mb-4 grid grid-cols-2 gap-4">
                <Card title="Who pays for delivery">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <select
                            aria-label="Rule"
                            className={sel}
                            value={f.data.mode}
                            onChange={(e) => f.setData("mode", e.target.value)}
                        >
                            <option value="threshold">
                                Depends on the order value
                            </option>
                            <option value="patient">Patient always pays</option>
                            <option value="practice">
                                Practice always pays
                            </option>
                        </select>
                        Fee R
                        <input
                            aria-label="Delivery fee"
                            className={`${sel} w-20`}
                            value={String(f.data.fee)}
                            onChange={(e) =>
                                f.setData("fee", Number(e.target.value))
                            }
                        />
                    </div>
                    {f.data.mode === "threshold" && (
                        <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
                            Orders below R
                            <input
                                aria-label="Threshold"
                                className={`${sel} w-24`}
                                value={String(f.data.threshold)}
                                onChange={(e) =>
                                    f.setData(
                                        "threshold",
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <select
                                aria-label="Below payer"
                                className={sel}
                                value={f.data.below_payer}
                                onChange={(e) =>
                                    f.setData("below_payer", e.target.value)
                                }
                            >
                                <option value="patient">patient pays</option>
                                <option value="practice">practice pays</option>
                            </select>
                            and from that amount
                            <select
                                aria-label="Above payer"
                                className={sel}
                                value={f.data.above_payer}
                                onChange={(e) =>
                                    f.setData("above_payer", e.target.value)
                                }
                            >
                                <option value="practice">practice pays</option>
                                <option value="patient">patient pays</option>
                            </select>
                        </div>
                    )}
                    <label className="mt-2 flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="accent-teal"
                            checked={f.data.allow_scheduled}
                            onChange={(e) =>
                                f.setData("allow_scheduled", e.target.checked)
                            }
                        />{" "}
                        We are approved to deliver Schedule 5 and higher
                        medicine
                    </label>
                    <label className="mt-1 flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="accent-teal"
                            checked={f.data.publish_stock}
                            onChange={(e) =>
                                f.setData("publish_stock", e.target.checked)
                            }
                        />{" "}
                        Publish our stock and prices for pharmacy comparison
                    </label>
                    <Button
                        className="mt-2"
                        size="sm"
                        onClick={() =>
                            f.put("/settings/delivery", {
                                preserveScroll: true,
                            })
                        }
                    >
                        Save
                    </Button>
                </Card>
                <Card title="Courier accounts">
                    {partners.length === 0 && (
                        <p className="text-sm text-muted">
                            No couriers are enabled on Clinic Flow yet. Manual
                            courier is always available.
                        </p>
                    )}
                    {partners.map((p) => (
                        <div
                            key={p.driver}
                            className="flex items-center gap-2 py-1 text-sm"
                        >
                            <span className="flex-1">{p.label}</span>
                            <Badge tone={p.linked ? "success" : "neutral"}>
                                {p.linked ? "linked" : "not linked"}
                            </Badge>
                            {!p.apiReady && (
                                <Badge>book in courier portal</Badge>
                            )}
                            <button
                                className="text-xs text-teal-deep"
                                onClick={() =>
                                    router.put(
                                        `/settings/couriers/${p.driver}`,
                                        {
                                            account_ref: ask(
                                                `${p.label} account number`,
                                            ),
                                            api_key: ask("API key (optional)"),
                                            enabled: true,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                link
                            </button>
                        </div>
                    ))}
                </Card>
            </div>
            <Card title="Recent deliveries">
                <ul className="divide-y divide-line-soft text-sm">
                    {deliveries.data.length === 0 && (
                        <li className="py-2 text-muted">
                            None yet. Request a delivery from the dispensing
                            screen.
                        </li>
                    )}
                    {deliveries.data.map((d) => (
                        <li
                            key={d.id}
                            className="flex flex-wrap items-center gap-2 py-2"
                        >
                            <span className="flex-1">
                                {d.patient} · {d.address}
                            </span>
                            <Badge>{couriers[d.driver] ?? d.driver}</Badge>
                            <Badge
                                tone={
                                    d.status === "delivered"
                                        ? "success"
                                        : d.status === "failed"
                                          ? "danger"
                                          : "neutral"
                                }
                            >
                                {d.status}
                            </Badge>
                            <span className="text-xs text-muted">
                                {rand(d.fee, 2)} · {d.payer} pays{" "}
                                {d.tracking ? `· ${d.tracking}` : ""}
                            </span>
                            {d.status === "requested" && (
                                <button
                                    className="text-xs text-teal-deep"
                                    onClick={() =>
                                        act(d.id, "book", {
                                            tracking_number: ask(
                                                "Tracking / waybill number",
                                            ),
                                        })
                                    }
                                >
                                    booked
                                </button>
                            )}
                            {["booked", "collected"].includes(d.status) && (
                                <button
                                    className="text-xs text-teal-deep"
                                    onClick={() =>
                                        act(d.id, "status", {
                                            status:
                                                d.status === "booked"
                                                    ? "collected"
                                                    : "in_transit",
                                        })
                                    }
                                >
                                    {d.status === "booked"
                                        ? "collected"
                                        : "in transit"}
                                </button>
                            )}
                            {!["delivered", "failed", "requested"].includes(
                                d.status,
                            ) && (
                                <button
                                    className="text-xs text-teal-deep"
                                    onClick={() =>
                                        act(d.id, "confirm", {
                                            code: ask("Code from the patient"),
                                        })
                                    }
                                >
                                    delivered
                                </button>
                            )}
                            {!["delivered", "failed"].includes(d.status) && (
                                <button
                                    className="text-xs text-status-danger"
                                    onClick={() =>
                                        act(d.id, "status", {
                                            status: "failed",
                                            reason: ask("Why did it fail?"),
                                        })
                                    }
                                >
                                    failed
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            </Card>
            <Pager page={deliveries} />
        </AppShell>
    );
}
