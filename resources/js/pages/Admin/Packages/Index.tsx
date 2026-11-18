import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";
import { featureLabel } from "@/pages/Public/Pricing";
import { type Catalogue, PackageDetails } from "./PackageDetails";

interface Pkg {
    id: number;
    name: string;
    providerType: string;
    priceMonthly: number;
    trialDays: number;
    limits: Record<string, number>;
    features: string[];
    isActive: boolean;
    pending?: { from: string; current: number } | null;
    paying?: number;
    summary?: string | null;
    addons?: string[];
}

function Row({ pkg, catalogue }: { pkg: Pkg; catalogue: Catalogue }) {
    const [editing, setEditing] = useState(false);
    const [price, setPrice] = useState(String(pkg.priceMonthly));
    const [trial, setTrial] = useState(String(pkg.trialDays));
    const [active, setActive] = useState(pkg.isActive);

    return (
        <>
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
                    {pkg.pending && (
                        <div className="mt-1 text-xs text-muted">
                            From {pkg.pending.from}; now R{pkg.pending.current}
                        </div>
                    )}
                    {!!pkg.paying && (
                        <div className="mt-1 text-xs text-muted">
                            {pkg.paying} on this plan
                        </div>
                    )}
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
                            (Number(price) <= pkg.priceMonthly ||
                                !pkg.paying ||
                                window.confirm(
                                    `${pkg.paying} practice(s) are on ${pkg.name}. They will be emailed 30 days' notice and the new price applies from then. Continue?`,
                                )) &&
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
                    <Button
                        size="sm"
                        variant="secondary"
                        className="ml-2"
                        onClick={() => setEditing(!editing)}
                    >
                        {editing ? "Close" : "Details"}
                    </Button>
                </td>
            </tr>
            {editing && (
                <tr className="border-t border-line-soft">
                    <td colSpan={5} className="px-4 py-3">
                        <PackageDetails
                            catalogue={catalogue}
                            packageId={pkg.id}
                            paying={pkg.paying}
                            onDone={() => setEditing(false)}
                            initial={{
                                name: pkg.name,
                                summary: pkg.summary ?? "",
                                features: pkg.features,
                                addons: pkg.addons ?? [],
                                limits: pkg.limits,
                            }}
                        />
                    </td>
                </tr>
            )}
        </>
    );
}

export default function PackagesIndex({
    packages,
    catalogue,
}: {
    packages: Pkg[];
    catalogue: Catalogue;
}) {
    const [adding, setAdding] = useState(false);
    return (
        <AdminShell active="Packages">
            <Head title="Packages" />
            <h1 className="mb-1 text-2xl font-semibold">Package builder</h1>
            <p className="mb-5 text-sm text-muted">
                Prices excl. VAT, shown live on the pricing page. A price rise
                for practices already on a package applies after 30 days'
                notice. Packages are never deleted: switching one off hides it
                from new sign-ups, and practices on it keep it until they change
                plan.
            </p>
            <Flash />
            <div className="mb-4">
                {adding ? (
                    <div className="rounded-xl border border-line bg-surface p-4">
                        <PackageDetails
                            catalogue={catalogue}
                            onDone={() => setAdding(false)}
                            initial={{
                                name: "",
                                summary: "",
                                features: [],
                                addons: [],
                                limits: {
                                    doctors: 1,
                                    branches: 1,
                                    storage_gb: 10,
                                },
                            }}
                        />
                    </div>
                ) : (
                    <Button size="sm" onClick={() => setAdding(true)}>
                        Add package
                    </Button>
                )}
            </div>
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
                            <Row key={p.id} pkg={p} catalogue={catalogue} />
                        ))}
                    </tbody>
                </table>
            </div>
        </AdminShell>
    );
}
