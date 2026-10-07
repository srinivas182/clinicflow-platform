import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Props {
    branches: {
        id: number;
        name: string;
        address: string | null;
        phone: string | null;
        is_main: boolean;
        active: boolean;
        staff: number[];
    }[];
    allowed: number | null;
    extraPrice: number;
    staff: { id: number; name: string }[];
    stock: { id: number; name: string; perBranch: Record<string, number> }[];
}
const ask = (l: string, d = "") => window.prompt(l, d) ?? "";

export default function Branches({
    branches,
    allowed,
    extraPrice,
    staff,
    stock,
}: Props) {
    const full =
        allowed !== null && branches.filter((b) => b.active).length >= allowed;

    return (
        <AppShell active="Settings">
            <Head title="Branches" />
            <div className="mb-1 flex items-center">
                <h1 className="flex-1 text-2xl font-semibold">Branches</h1>
                <Button
                    disabled={full}
                    onClick={() =>
                        router.post("/settings/branches", {
                            name: ask("Branch name"),
                            address: ask("Address"),
                            phone: ask("Phone"),
                        })
                    }
                >
                    Add branch
                </Button>
            </div>
            <p className="mb-5 text-sm text-muted">
                Patients are shared across branches. Queues, appointments,
                cash-up and stock are per branch.{" "}
                {allowed === null
                    ? "Your package allows unlimited branches."
                    : `Your package allows ${allowed} branch(es)${full ? ` — add more for R${extraPrice} a month each from Settings → Subscription.` : "."}`}
            </p>
            <Flash />
            <div className="mb-6 grid grid-cols-2 gap-4">
                {branches.map((b) => (
                    <Card
                        key={b.id}
                        title={b.name}
                        aside={
                            b.is_main ? (
                                <Badge tone="teal">main</Badge>
                            ) : undefined
                        }
                    >
                        <p className="mb-2 text-xs text-muted">
                            {b.address} {b.phone}
                        </p>
                        <div className="flex flex-wrap gap-2 text-sm">
                            {staff.map((s) => (
                                <label
                                    key={s.id}
                                    className="flex items-center gap-1"
                                >
                                    <input
                                        type="checkbox"
                                        className="accent-teal"
                                        checked={b.staff.includes(s.id)}
                                        onChange={(e) =>
                                            router.put(
                                                `/settings/branches/${b.id}/staff`,
                                                {
                                                    staff_ids: e.target.checked
                                                        ? [...b.staff, s.id]
                                                        : b.staff.filter(
                                                              (x) => x !== s.id,
                                                          ),
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                    {s.name}
                                </label>
                            ))}
                        </div>
                    </Card>
                ))}
            </div>
            {branches.length > 1 && (
                <Card title="Stock per branch">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs text-muted">
                                <th scope="col">Item</th>
                                {branches.map((b) => (
                                    <th scope="col" key={b.id}>
                                        {b.name}
                                    </th>
                                ))}
                                <th scope="col" />
                            </tr>
                        </thead>
                        <tbody>
                            {stock.map((s) => (
                                <tr
                                    key={s.id}
                                    className="border-t border-line-soft"
                                >
                                    <td className="py-1">{s.name}</td>
                                    {branches.map((b) => (
                                        <td key={b.id}>
                                            {s.perBranch[b.id] ?? 0}
                                        </td>
                                    ))}
                                    <td>
                                        <button
                                            className="text-xs text-teal-deep"
                                            onClick={() =>
                                                router.post(
                                                    "/branches/transfer",
                                                    {
                                                        stock_item_id: s.id,
                                                        from: Number(
                                                            ask(
                                                                `From branch (${branches.map((b) => `${b.id}=${b.name}`).join(", ")})`,
                                                            ),
                                                        ),
                                                        to: Number(
                                                            ask("To branch"),
                                                        ),
                                                        quantity: Number(
                                                            ask("Quantity"),
                                                        ),
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            move
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}
        </AppShell>
    );
}
