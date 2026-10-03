import { Head, Link, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";
import { rand } from "@/lib/money";

interface Group {
    id: number;
    name: string;
    billing: string;
    members: { id: string; name: string }[];
    invoices: {
        id: number;
        number: string;
        total_cents: number;
        status: string;
    }[];
}
const ask = (l: string) => window.prompt(l) ?? "";

export default function AdminGroups({
    groups,
    providers,
}: {
    groups: Group[];
    providers: { id: string; name: string }[];
}) {
    const act = (action: string, data: Record<string, unknown>) =>
        router.post(`/admin/groups/${action}`, data as never, {
            preserveScroll: true,
        });

    return (
        <AdminShell active="Groups">
            <Head title="Groups" />
            <div className="mb-5 flex items-center">
                <h1 className="flex-1 text-2xl font-semibold">
                    Practice groups
                </h1>
                <Button
                    onClick={() =>
                        act("create", {
                            name: ask("Group name"),
                            billing: "separate",
                        })
                    }
                >
                    New group
                </Button>
            </div>
            <Flash />
            {groups.map((g) => (
                <Card
                    key={g.id}
                    title={g.name}
                    aside={
                        <Badge>
                            {g.billing === "combined"
                                ? "one combined invoice"
                                : "each practice pays"}
                        </Badge>
                    }
                    className="mb-4"
                >
                    <p className="mb-2 text-sm">
                        {g.members.map((m) => m.name).join(", ") ||
                            "No practices yet."}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                act("member", {
                                    group_id: g.id,
                                    tenant_id: ask(
                                        `Practice ID:\n${providers.map((p) => `${p.id} ${p.name}`).join("\n")}`,
                                    ),
                                })
                            }
                        >
                            Add practice
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                act("admin", {
                                    group_id: g.id,
                                    email: ask("Group admin email"),
                                })
                            }
                        >
                            Add group admin
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                act("billing", {
                                    group_id: g.id,
                                    billing:
                                        g.billing === "combined"
                                            ? "separate"
                                            : "combined",
                                })
                            }
                        >
                            Switch billing
                        </Button>
                        {g.billing === "combined" && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    act("invoice", { group_id: g.id })
                                }
                            >
                                Issue combined invoice
                            </Button>
                        )}
                        <Link href={`/groups/${g.id}`}>
                            <Button size="sm" variant="ghost">
                                Dashboard
                            </Button>
                        </Link>
                    </div>
                    {g.invoices.map((i) => (
                        <p
                            key={i.id}
                            className="mt-2 flex items-center gap-2 text-sm"
                        >
                            {i.number} · {rand(i.total_cents / 100, 2)}{" "}
                            <Badge>{i.status}</Badge>
                            {i.status !== "paid" && (
                                <button
                                    className="text-xs text-teal-deep"
                                    onClick={() =>
                                        act("settle", {
                                            group_id: g.id,
                                            group_invoice_id: i.id,
                                            reference: ask("Payment reference"),
                                        })
                                    }
                                >
                                    record payment
                                </button>
                            )}
                        </p>
                    ))}
                </Card>
            ))}
        </AdminShell>
    );
}
