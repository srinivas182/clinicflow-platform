import { router, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import { Checkbox, Field } from "@/components/form/Field";
import { Badge, Button, Card } from "@/components/ui";

export interface GatewayField {
    key: string;
    label: string;
    secret: boolean;
    value: string;
    isSet: boolean;
}

export interface GatewayRow {
    gateway: string;
    label: string;
    enabled: boolean;
    isDefault: boolean;
    mode: string;
    offered: boolean;
    apiRefunds: boolean;
    lastTestOk: boolean | null;
    webhookUrl: string;
    fields: GatewayField[];
}

/**
 * One gateway account: on/off, test or live, credentials (secrets write-only),
 * default, test connection and the webhook address to paste into the gateway.
 */
export function GatewayCard({
    row,
    base,
    showOffered = false,
}: {
    row: GatewayRow;
    base: string;
    showOffered?: boolean;
}) {
    const form = useForm({
        enabled: row.enabled,
        is_default: row.isDefault,
        mode: row.mode,
        offered_to_providers: row.offered,
        credentials: Object.fromEntries(
            row.fields.map((f) => [f.key, f.value]),
        ) as Record<string, string>,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`${base}/${row.gateway}`, { preserveScroll: true });
    };

    return (
        <Card
            title={row.label}
            aside={
                <span className="inline-flex gap-1.5">
                    {row.enabled ? (
                        <Badge tone="success">On</Badge>
                    ) : (
                        <Badge>Off</Badge>
                    )}
                    <Badge tone={row.mode === "live" ? "danger" : "warning"}>
                        {row.mode === "live" ? "Live" : "Test"}
                    </Badge>
                    {row.lastTestOk === true && (
                        <Badge tone="teal">Tested</Badge>
                    )}
                </span>
            }
        >
            <form onSubmit={submit} className="flex flex-col gap-3">
                <div className="flex flex-wrap gap-4">
                    <Checkbox
                        name={`${row.gateway}-enabled`}
                        label="Enabled"
                        checked={form.data.enabled}
                        onChange={(v) => form.setData("enabled", v)}
                    />
                    <Checkbox
                        name={`${row.gateway}-default`}
                        label="Default for pay links"
                        checked={form.data.is_default}
                        onChange={(v) => form.setData("is_default", v)}
                    />
                    {showOffered && (
                        <Checkbox
                            name={`${row.gateway}-offered`}
                            label="Offer to providers"
                            checked={form.data.offered_to_providers}
                            onChange={(v) =>
                                form.setData("offered_to_providers", v)
                            }
                        />
                    )}
                </div>
                <div
                    role="radiogroup"
                    aria-label="Mode"
                    className="inline-flex w-fit rounded-lg bg-line-soft p-1 text-sm"
                >
                    {["test", "live"].map((m) => (
                        <button
                            key={m}
                            type="button"
                            role="radio"
                            aria-checked={form.data.mode === m}
                            onClick={() => form.setData("mode", m)}
                            className={`rounded-md px-3 py-1 ${form.data.mode === m ? "bg-surface font-medium shadow-sm" : "text-muted"}`}
                        >
                            {m === "test" ? "Test (sandbox)" : "Live"}
                        </button>
                    ))}
                </div>
                {row.fields.map((f) => (
                    <Field
                        key={f.key}
                        label={f.label}
                        name={`${row.gateway}-${f.key}`}
                        type={f.secret ? "password" : "text"}
                        autoComplete="off"
                        placeholder={
                            f.secret && f.isSet
                                ? "Saved — leave blank to keep"
                                : ""
                        }
                        value={form.data.credentials[f.key] ?? ""}
                        onChange={(e) =>
                            form.setData("credentials", {
                                ...form.data.credentials,
                                [f.key]: e.target.value,
                            })
                        }
                    />
                ))}
                {form.errors.credentials && (
                    <p role="alert" className="text-xs text-status-danger">
                        {form.errors.credentials}
                    </p>
                )}
                <div className="rounded-lg bg-paper px-3 py-2 text-xs">
                    <div className="font-medium">Webhook / notify URL</div>
                    <div className="break-all text-teal-deep">
                        {row.webhookUrl}
                    </div>
                </div>
                <p className="text-xs text-muted">
                    {row.apiRefunds
                        ? "Refunds go through the gateway automatically."
                        : "Refunds are made in the gateway dashboard, then recorded in Dr Business Flow."}
                </p>
                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        Save
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() =>
                            router.post(
                                `${base}/${row.gateway}/test`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Test connection
                    </Button>
                </div>
            </form>
        </Card>
    );
}
