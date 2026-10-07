import { Head, router, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";

interface Props {
    settings: {
        registered: boolean;
        number: string | null;
        rate: number;
        zero_rated_kinds: string[];
    };
    report: Record<string, number>;
    period: { from: string; to: string };
}
const KINDS = [
    "consultation",
    "procedure",
    "medicine",
    "lab",
    "certificate",
    "other",
];

export default function Vat({ settings, report, period }: Props) {
    const form = useForm({
        registered: settings.registered,
        number: settings.number ?? "",
        rate: settings.rate,
        zero_rated_kinds: settings.zero_rated_kinds,
    });
    const q = `from=${period.from}&to=${period.to}`;

    return (
        <AppShell active="Finance">
            <Head title="VAT" />
            <h1 className="mb-1 text-2xl font-semibold">VAT</h1>
            <p className="mb-5 text-sm text-muted">
                Prices include VAT. Confirm with your accountant which services
                are standard-rated, zero-rated or exempt.
            </p>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                <Card title="Settings">
                    <label className="mb-2 flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="accent-teal"
                            checked={form.data.registered}
                            onChange={(e) =>
                                form.setData("registered", e.target.checked)
                            }
                        />{" "}
                        VAT registered (issue tax invoices)
                    </label>
                    <input
                        aria-label="VAT number"
                        placeholder="VAT number (4xxxxxxxxx)"
                        className="mb-2 w-full rounded-md border border-line px-2 py-1 text-sm"
                        value={form.data.number}
                        onChange={(e) => form.setData("number", e.target.value)}
                    />
                    {form.errors.number && (
                        <p
                            role="alert"
                            className="mb-2 text-xs text-status-danger"
                        >
                            {form.errors.number}
                        </p>
                    )}
                    <label className="mb-2 flex items-center gap-2 text-sm">
                        Rate %{" "}
                        <input
                            aria-label="VAT rate"
                            className="w-20 rounded-md border border-line px-2 py-1"
                            value={String(form.data.rate)}
                            onChange={(e) =>
                                form.setData("rate", Number(e.target.value))
                            }
                        />
                    </label>
                    <div className="mb-2 text-xs font-medium">
                        Zero-rated or exempt line types
                    </div>
                    <div className="mb-3 flex flex-wrap gap-2 text-sm">
                        {KINDS.map((k) => (
                            <label key={k} className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    className="accent-teal"
                                    checked={form.data.zero_rated_kinds.includes(
                                        k,
                                    )}
                                    onChange={(e) =>
                                        form.setData(
                                            "zero_rated_kinds",
                                            e.target.checked
                                                ? [
                                                      ...form.data
                                                          .zero_rated_kinds,
                                                      k,
                                                  ]
                                                : form.data.zero_rated_kinds.filter(
                                                      (x) => x !== k,
                                                  ),
                                        )
                                    }
                                />{" "}
                                {k}
                            </label>
                        ))}
                    </div>
                    <Button
                        size="sm"
                        onClick={() =>
                            form.put("/settings/vat", { preserveScroll: true })
                        }
                    >
                        Save
                    </Button>
                </Card>
                <Card title={`VAT201 figures · ${period.from} to ${period.to}`}>
                    <div className="mb-2 flex gap-2 text-sm">
                        <input
                            aria-label="From"
                            type="date"
                            defaultValue={period.from}
                            id="vf"
                            className="rounded-md border border-line px-2 py-1"
                        />
                        <input
                            aria-label="To"
                            type="date"
                            defaultValue={period.to}
                            id="vt"
                            className="rounded-md border border-line px-2 py-1"
                        />
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.get("/finance/vat", {
                                    from: (
                                        document.getElementById(
                                            "vf",
                                        ) as HTMLInputElement
                                    ).value,
                                    to: (
                                        document.getElementById(
                                            "vt",
                                        ) as HTMLInputElement
                                    ).value,
                                })
                            }
                        >
                            Show
                        </Button>
                    </div>
                    <table className="w-full text-sm">
                        <tbody>
                            {[
                                ["Sales (incl. VAT)", "sales_cents"],
                                ["Output VAT", "output_vat_cents"],
                                ["Purchases (excl. VAT)", "purchases_cents"],
                                ["Input VAT", "input_vat_cents"],
                                ["VAT payable", "net_vat_cents"],
                            ].map(([l, k]) => (
                                <tr key={k}>
                                    <td className="py-1">{l}</td>
                                    <td className="py-1 text-right font-medium">
                                        {rand(report[k as string] ?? 0, 2)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="mt-3 flex gap-2 text-xs">
                        {["csv", "xlsx", "pdf"].map((f) => (
                            <a
                                key={f}
                                className="text-teal-deep"
                                href={`/finance/exports/vat?${q}&format=${f}`}
                            >
                                {f.toUpperCase()}
                            </a>
                        ))}
                    </div>
                </Card>
            </div>
        </AppShell>
    );
}
