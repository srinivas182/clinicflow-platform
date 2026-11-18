import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";
import { rand } from "@/lib/money";

export interface Period {
    period: string;
    amount: number;
    paid: boolean;
    reference: string | null;
}
interface Reseller {
    id: number;
    name: string;
    email: string;
    code: string;
    percent: number;
    months: number;
    link: string;
    active?: boolean;
    linked?: boolean;
    referrals: { practice: string; referred: string }[];
    periods: Period[];
}
const ask = (l: string, d = "") => window.prompt(l, d) ?? "";

export default function Resellers({ resellers }: { resellers: Reseller[] }) {
    return (
        <AdminShell active="Resellers">
            <Head title="Resellers" />
            <div className="mb-5 flex items-center">
                <h1 className="flex-1 text-2xl font-semibold">Resellers</h1>
                <Button
                    onClick={() =>
                        router.post("/admin/resellers", {
                            name: ask("Name"),
                            email: ask("Email"),
                            phone: ask("Phone"),
                            commission_percent: Number(
                                ask("Commission %", "20"),
                            ),
                            commission_months: Number(
                                ask("For how many months", "12"),
                            ),
                        })
                    }
                >
                    Add reseller
                </Button>
            </div>
            <Flash />
            {resellers.length === 0 && (
                <p className="text-sm text-muted">No resellers yet.</p>
            )}
            {resellers.map((r) => (
                <Card
                    key={r.id}
                    title={`${r.name} · ${r.code}`}
                    aside={
                        <Badge tone={r.active === false ? "neutral" : "teal"}>
                            {r.active === false
                                ? "switched off"
                                : `${r.percent}% for ${r.months} months`}
                        </Badge>
                    }
                    className="mb-4"
                >
                    <p className="mb-2 font-mono text-xs">{r.link}</p>
                    <p className="mb-2 flex flex-wrap gap-3 text-xs">
                        <button
                            type="button"
                            className="font-medium text-teal-deep"
                            onClick={() => {
                                const percent = ask(
                                    "Commission % for practices referred from now on (0–50)",
                                    String(r.percent),
                                );
                                const months = percent
                                    ? ask(
                                          "For how many months (1–60)",
                                          String(r.months),
                                      )
                                    : "";
                                if (percent && months)
                                    router.put(
                                        `/admin/resellers/${r.id}`,
                                        {
                                            commission_percent: percent,
                                            commission_months: months,
                                            active: r.active !== false,
                                        },
                                        { preserveScroll: true },
                                    );
                            }}
                        >
                            Change commission
                        </button>
                        <button
                            type="button"
                            className="font-medium text-teal-deep"
                            onClick={() =>
                                window.confirm(
                                    r.active === false
                                        ? `Switch ${r.name} on again?`
                                        : `Switch ${r.name} off? Their link stops working; commission already earned is still paid.`,
                                ) &&
                                router.put(
                                    `/admin/resellers/${r.id}`,
                                    {
                                        commission_percent: r.percent,
                                        commission_months: r.months,
                                        active: r.active === false,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {r.active === false ? "Switch on" : "Switch off"}
                        </button>
                        <button
                            type="button"
                            className="font-medium text-teal-deep"
                            onClick={() =>
                                router.post(
                                    `/admin/resellers/${r.id}/invite`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {r.linked
                                ? "Email portal link again"
                                : "Invite to the reseller portal"}
                        </button>
                    </p>
                    <p className="mb-2 text-sm">
                        Referred:{" "}
                        {r.referrals
                            .map((x) => `${x.practice} (${x.referred})`)
                            .join(", ") || "none yet"}
                    </p>
                    {r.periods.map((m) => (
                        <p
                            key={m.period}
                            className="flex items-center gap-2 text-sm"
                        >
                            {m.period} · {rand(m.amount / 100, 2)}
                            <Badge tone={m.paid ? "success" : "warning"}>
                                {m.paid
                                    ? `paid ${m.reference ?? ""}`
                                    : "to pay"}
                            </Badge>
                            {!m.paid && (
                                <button
                                    className="text-xs text-teal-deep"
                                    onClick={() =>
                                        router.post(
                                            `/admin/resellers/${r.id}/pay`,
                                            {
                                                period: m.period,
                                                reference: ask("EFT reference"),
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    mark paid
                                </button>
                            )}
                        </p>
                    ))}
                </Card>
            ))}
        </AdminShell>
    );
}
