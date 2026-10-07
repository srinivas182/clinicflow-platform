import { Head, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import { Flash } from "@/components/Flash";
import { Field } from "@/components/form/Field";
import { Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

export default function BillingSettings(props: {
    paymentTiming: string;
    refundRule: string;
    consultFee: number;
    consultCode: string;
    kioskUrl: string;
    displayUrl: string;
}) {
    const form = useForm({
        payment_timing: props.paymentTiming,
        refund_rule: props.refundRule,
        consult_fee: String(props.consultFee),
        consult_code: props.consultCode,
    });
    const select =
        "min-h-10 rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm";

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put("/settings/billing", { preserveScroll: true });
    };

    return (
        <AppShell active="Settings">
            <Head title="Billing settings" />
            <h1 className="mb-5 text-2xl font-semibold">Billing and devices</h1>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                <Card title="Payment rules">
                    <form onSubmit={submit} className="flex flex-col gap-3">
                        <label
                            className="text-xs font-medium"
                            htmlFor="payment_timing"
                        >
                            Cash patients pay the consult fee
                        </label>
                        <select
                            id="payment_timing"
                            className={select}
                            value={form.data.payment_timing}
                            onChange={(e) =>
                                form.setData("payment_timing", e.target.value)
                            }
                        >
                            <option value="check_in">
                                At check-in, before triage
                            </option>
                            <option value="end">At the end of the visit</option>
                        </select>
                        <label
                            className="text-xs font-medium"
                            htmlFor="refund_rule"
                        >
                            If a patient leaves before being seen
                        </label>
                        <select
                            id="refund_rule"
                            className={select}
                            value={form.data.refund_rule}
                            onChange={(e) =>
                                form.setData("refund_rule", e.target.value)
                            }
                        >
                            <option value="refund">
                                Refund the consult fee
                            </option>
                            <option value="credit">
                                Credit for the next visit
                            </option>
                            <option value="none">No refund</option>
                        </select>
                        <Field
                            label="Consult fee (R)"
                            name="consult_fee"
                            value={form.data.consult_fee}
                            onChange={(e) =>
                                form.setData("consult_fee", e.target.value)
                            }
                            error={form.errors.consult_fee}
                        />
                        <Field
                            label="Consult tariff code"
                            name="consult_code"
                            value={form.data.consult_code}
                            onChange={(e) =>
                                form.setData("consult_code", e.target.value)
                            }
                        />
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </form>
                </Card>
                <Card title="Devices">
                    <p className="text-sm text-muted">
                        Open these links on the check-in tablet and the
                        waiting-room TV. Anyone with a link can use that device,
                        so keep them private.
                    </p>
                    <dl className="mt-4 space-y-3 text-sm">
                        <div>
                            <dt className="font-medium">Self check-in kiosk</dt>
                            <dd className="break-all text-teal-deep">
                                {props.kioskUrl}
                            </dd>
                        </div>
                        <div>
                            <dt className="font-medium">
                                Waiting-room display
                            </dt>
                            <dd className="break-all text-teal-deep">
                                {props.displayUrl}
                            </dd>
                        </div>
                    </dl>
                </Card>
            </div>
        </AppShell>
    );
}
