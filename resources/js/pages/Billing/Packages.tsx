import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";

type Item = { service: string; label: string; quantity: number };
interface Props {
    packages: {
        id: number;
        name: string;
        description: string | null;
        price: number;
        items: Item[];
        active: boolean;
    }[];
    sold: {
        id: string;
        patient: string;
        package: string;
        status: string;
        remaining: Record<string, number>;
        expires: string | null;
    }[];
    validYears: number;
}

export default function Packages({ packages, sold, validYears }: Props) {
    const [form, setForm] = useState({
        name: "",
        description: "",
        price: "",
        items: [
            { service: "consultation", label: "Consultation", quantity: 3 },
        ] as Item[],
    });

    return (
        <AppShell active="Billing">
            <Head title="Prepaid packages" />
            <h1 className="mb-1 text-2xl font-semibold">Prepaid packages</h1>
            <p className="mb-5 text-sm text-muted">
                Defined services paid in advance — not medical cover. Each
                purchase is valid for {validYears} years (Consumer Protection
                Act). Confirm package wording with your legal reviewer.
            </p>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                <Card title="New package">
                    <input
                        aria-label="Name"
                        placeholder="Name, e.g. 3 consultations"
                        className="mb-2 w-full rounded-md border border-line px-2 py-1 text-sm"
                        value={form.name}
                        onChange={(e) =>
                            setForm({ ...form, name: e.target.value })
                        }
                    />
                    <input
                        aria-label="Description"
                        placeholder="Description"
                        className="mb-2 w-full rounded-md border border-line px-2 py-1 text-sm"
                        value={form.description}
                        onChange={(e) =>
                            setForm({ ...form, description: e.target.value })
                        }
                    />
                    <input
                        aria-label="Price"
                        placeholder="Price (R)"
                        className="mb-2 w-32 rounded-md border border-line px-2 py-1 text-sm"
                        value={form.price}
                        onChange={(e) =>
                            setForm({ ...form, price: e.target.value })
                        }
                    />
                    {form.items.map((it, i) => (
                        <div key={i} className="mb-1 flex gap-2 text-sm">
                            <input
                                aria-label="Service"
                                title="consultation, procedure:CODE or lab:CODE"
                                className="w-40 rounded-md border border-line px-2 py-1"
                                value={it.service}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        items: form.items.map((x, j) =>
                                            j === i
                                                ? {
                                                      ...x,
                                                      service: e.target.value,
                                                  }
                                                : x,
                                        ),
                                    })
                                }
                            />
                            <input
                                aria-label="Label"
                                className="flex-1 rounded-md border border-line px-2 py-1"
                                value={it.label}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        items: form.items.map((x, j) =>
                                            j === i
                                                ? {
                                                      ...x,
                                                      label: e.target.value,
                                                  }
                                                : x,
                                        ),
                                    })
                                }
                            />
                            <input
                                aria-label="Quantity"
                                className="w-16 rounded-md border border-line px-2 py-1"
                                value={it.quantity}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        items: form.items.map((x, j) =>
                                            j === i
                                                ? {
                                                      ...x,
                                                      quantity: Number(
                                                          e.target.value,
                                                      ),
                                                  }
                                                : x,
                                        ),
                                    })
                                }
                            />
                        </div>
                    ))}
                    <div className="mt-2 flex gap-2">
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                setForm({
                                    ...form,
                                    items: [
                                        ...form.items,
                                        {
                                            service: "lab:HBA1C",
                                            label: "HbA1c test",
                                            quantity: 1,
                                        },
                                    ],
                                })
                            }
                        >
                            Add service
                        </Button>
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(
                                    "/packages",
                                    { ...form, price: Number(form.price) },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Save package
                        </Button>
                    </div>
                </Card>
                <Card title="Packages">
                    {packages.map((p) => (
                        <div key={p.id} className="mb-3 text-sm">
                            <p className="font-medium">
                                {p.name} · {rand(p.price, 2)}{" "}
                                {!p.active && <Badge>inactive</Badge>}
                            </p>
                            <p className="text-xs text-muted">
                                {p.items
                                    .map((i) => `${i.quantity} × ${i.label}`)
                                    .join(", ")}
                            </p>
                        </div>
                    ))}
                </Card>
            </div>
            <Card title="Sold packages" className="mt-4">
                <table className="w-full text-sm">
                    <tbody>
                        {sold.map((s) => (
                            <tr
                                key={s.id}
                                className="border-t border-[#EBF0EE]"
                            >
                                <td className="py-1.5">{s.patient}</td>
                                <td>{s.package}</td>
                                <td>
                                    <Badge
                                        tone={
                                            s.status === "active"
                                                ? "success"
                                                : "neutral"
                                        }
                                    >
                                        {s.status}
                                    </Badge>
                                </td>
                                <td className="text-xs">
                                    {Object.entries(s.remaining)
                                        .map(([k, v]) => `${k}: ${v}`)
                                        .join(", ")}
                                </td>
                                <td className="text-xs text-muted">
                                    {s.expires
                                        ? `until ${s.expires}`
                                        : "awaiting payment"}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </AppShell>
    );
}
