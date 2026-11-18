import { useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import { Button } from "@/components/ui";

export interface Catalogue {
    features: Record<string, string>;
    limits: Record<string, string>;
    addons: Record<string, string>;
    types: Record<string, string>;
}

export interface DetailValues {
    name: string;
    summary: string;
    features: string[];
    addons: string[];
    limits: Record<string, number>;
}

const box = "rounded-md border border-line px-2 py-1 text-sm";

/** Ticks for one list (features or add-ons). */
function Ticks({
    legend,
    options,
    value,
    onChange,
}: {
    legend: string;
    options: Record<string, string>;
    value: string[];
    onChange: (v: string[]) => void;
}) {
    return (
        <fieldset className="min-w-0">
            <legend className="mb-1 text-xs font-medium">{legend}</legend>
            <div className="grid gap-1 sm:grid-cols-3">
                {Object.entries(options).map(([code, label]) => (
                    <label
                        key={code}
                        className="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            className="accent-teal"
                            checked={value.includes(code)}
                            onChange={(e) =>
                                onChange(
                                    e.target.checked
                                        ? [...value, code]
                                        : value.filter((v) => v !== code),
                                )
                            }
                        />
                        {label}
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

/**
 * Add a package, or edit what one includes (beta #19, #20). For a new package: code, type, price
 * and trial too. Editing a package with paying practices warns that they are emailed what they lose.
 */
export function PackageDetails({
    catalogue,
    initial,
    packageId,
    paying = 0,
    onDone,
}: {
    catalogue: Catalogue;
    initial: DetailValues;
    packageId?: number;
    paying?: number;
    onDone?: () => void;
}) {
    const creating = packageId === undefined;
    const form = useForm({
        ...initial,
        code: "",
        provider_type: Object.keys(catalogue.types)[0] ?? "clinic",
        price_monthly: "0",
        trial_days: "30",
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (
            !creating &&
            paying > 0 &&
            !window.confirm(
                `${paying} practice(s) are on this package. Anything removed is emailed to them and applies straight away. Continue?`,
            )
        )
            return;
        const options = { preserveScroll: true, onSuccess: () => onDone?.() };
        if (creating) form.post("/admin/packages", options);
        else form.put(`/admin/packages/${packageId}/details`, options);
    };
    const err = form.errors as Record<string, string | undefined>;
    return (
        <form onSubmit={submit} className="grid gap-3 text-sm">
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="flex flex-col gap-1 text-xs font-medium">
                    Name
                    <input
                        className={box}
                        value={form.data.name}
                        onChange={(e) => form.setData("name", e.target.value)}
                    />
                    {err.name && (
                        <span className="text-status-danger">{err.name}</span>
                    )}
                </label>
                <label className="flex flex-col gap-1 text-xs font-medium">
                    Summary (shown on the pricing page)
                    <input
                        className={box}
                        maxLength={160}
                        value={form.data.summary}
                        onChange={(e) =>
                            form.setData("summary", e.target.value)
                        }
                    />
                </label>
                {creating && (
                    <>
                        <label className="flex flex-col gap-1 text-xs font-medium">
                            Code (cannot change later)
                            <input
                                className={box}
                                placeholder="clinic-plus"
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData("code", e.target.value)
                                }
                            />
                            {err.code && (
                                <span className="text-status-danger">
                                    {err.code}
                                </span>
                            )}
                        </label>
                        <label className="flex flex-col gap-1 text-xs font-medium">
                            For
                            <select
                                className={box}
                                value={form.data.provider_type}
                                onChange={(e) =>
                                    form.setData(
                                        "provider_type",
                                        e.target.value,
                                    )
                                }
                            >
                                {Object.entries(catalogue.types).map(
                                    ([v, l]) => (
                                        <option key={v} value={v}>
                                            {l}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <label className="flex flex-col gap-1 text-xs font-medium">
                            Monthly price (R, excl. VAT; 0 = free)
                            <input
                                className={box}
                                inputMode="decimal"
                                value={form.data.price_monthly}
                                onChange={(e) =>
                                    form.setData(
                                        "price_monthly",
                                        e.target.value,
                                    )
                                }
                            />
                            {err.price_monthly && (
                                <span className="text-status-danger">
                                    {err.price_monthly}
                                </span>
                            )}
                        </label>
                        <label className="flex flex-col gap-1 text-xs font-medium">
                            Trial days
                            <input
                                className={box}
                                inputMode="numeric"
                                value={form.data.trial_days}
                                onChange={(e) =>
                                    form.setData("trial_days", e.target.value)
                                }
                            />
                        </label>
                    </>
                )}
            </div>
            <fieldset className="min-w-0">
                <legend className="mb-1 text-xs font-medium">
                    Limits (0 = unlimited)
                </legend>
                <div className="flex flex-wrap gap-3">
                    {Object.entries(catalogue.limits).map(([key, label]) => (
                        <label
                            key={key}
                            className="flex flex-col gap-1 text-xs"
                        >
                            {label}
                            <input
                                className={`${box} w-24`}
                                inputMode="numeric"
                                value={form.data.limits[key] ?? 0}
                                onChange={(e) =>
                                    form.setData("limits", {
                                        ...form.data.limits,
                                        [key]: Number(e.target.value) || 0,
                                    })
                                }
                            />
                        </label>
                    ))}
                </div>
            </fieldset>
            <Ticks
                legend="Features"
                options={catalogue.features}
                value={form.data.features}
                onChange={(v) => form.setData("features", v)}
            />
            <Ticks
                legend="Add-ons practices may buy"
                options={catalogue.addons}
                value={form.data.addons}
                onChange={(v) => form.setData("addons", v)}
            />
            <div>
                <Button size="sm" type="submit" disabled={form.processing}>
                    {creating ? "Add package (switched off)" : "Save details"}
                </Button>
            </div>
        </form>
    );
}
