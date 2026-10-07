import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";
import { featureLabel } from "@/pages/Public/Pricing";

interface Pkg {
    id: number;
    name: string;
    providerType: string;
    priceMonthly: number;
    trialDays: number;
    limits: Record<string, number>;
    features: string[];
    isActive: boolean;
}

function Row({ pkg }: { pkg: Pkg }) {
    const [price, setPrice] = useState(String(pkg.priceMonthly));
    const [trial, setTrial] = useState(String(pkg.trialDays));
    const [active, setActive] = useState(pkg.isActive);

    return (
        <tr className="border-t border-line-soft align-top">
            <td className="px-4 py-3">
                <div className="font-medium">{pkg.name}</div>
                <div className="text-xs text-muted">{pkg.providerType}</div>
                <div className="mt-1 flex flex-wrap gap-1">
                    {pkg.features.slice(0, 4).map((f) => (
                        <Badge key={f}>{featureLabel(f)}</Badge>
                    ))}
                </div>
            </td>
            <td className="px-4 py-3">
                <label className="sr-only" htmlFor={`price-${pkg.id}`}>
                    Monthly price
                </label>
                <input
                    id={`price-${pkg.id}`}
                    className="w-28 rounded-md border border-line px-2 py-1"
                    value={price}
                    onChange={(e) => setPrice(e.target.value)}
                />
            </td>
            <td className="px-4 py-3">
                <label className="sr-only" htmlFor={`trial-${pkg.id}`}>
                    Trial days
                </label>
                <input
                    id={`trial-${pkg.id}`}
                    className="w-16 rounded-md border border-line px-2 py-1"
                    value={trial}
                    onChange={(e) => setTrial(e.target.value)}
                />
            </td>
            <td className="px-4 py-3">
                <input
                    type="checkbox"
                    aria-label="Active"
                    checked={active}
                    onChange={(e) => setActive(e.target.checked)}
                    className="accent-teal"
                />
            </td>
            <td className="px-4 py-3 text-right">
                <Button
                    size="sm"
                    onClick={() =>
                        router.put(
                            `/admin/packages/${pkg.id}`,
                            {
                                price_monthly: price,
                                trial_days: trial,
                                is_active: active,
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save
                </Button>
            </td>
        </tr>
    );
}

export default function PackagesIndex({ packages }: { packages: Pkg[] }) {
    return (
        <AdminShell active="Packages">
            <Head title="Packages" />
            <h1 className="mb-1 text-2xl font-semibold">Package builder</h1>
            <p className="mb-5 text-sm text-muted">
                Prices excl. VAT, shown live on the pricing page. Existing
                providers move at their next billing date.
            </p>
            <Flash />
            <div className="overflow-hidden rounded-xl border border-line bg-surface">
                <table className="w-full text-sm">
                    <thead className="bg-paper text-left text-xs text-muted">
                        <tr>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Package
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Monthly (R)
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Trial days
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Active
                            </th>
                            <th scope="col" />
                        </tr>
                    </thead>
                    <tbody>
                        {packages.map((p) => (
                            <Row key={p.id} pkg={p} />
                        ))}
                    </tbody>
                </table>
            </div>
        </AdminShell>
    );
}
