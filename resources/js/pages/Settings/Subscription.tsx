import { Head, router, usePage } from "@inertiajs/react";
import { CreditCard } from "lucide-react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";

interface Inv {
    number: string;
    period: string;
    total: number;
    status: string;
    due: string;
    payUrl: string | null;
    lastAttempt: {
        outcome: string;
        message: string | null;
        attempted_at: string;
    } | null;
}

interface Plan {
    id: number;
    name: string;
    summary: string | null;
    monthly: number;
    annual: number;
    free: boolean;
}

/** Change plan: pick a plan and billing period; the change applies at once. */
function ChangePlan({
    plans,
    currentId,
    currentPeriod,
}: {
    plans: Plan[];
    currentId: number;
    currentPeriod: string;
}) {
    const [period, setPeriod] = useState(
        currentPeriod === "annual" ? "annual" : "monthly",
    );
    const errors = usePage<{ errors: Record<string, string> }>().props.errors;
    const choose = (p: Plan) =>
        window.confirm(
            p.free
                ? `Move to ${p.name}? It is free; unpaid invoices for your current plan are cancelled.`
                : `Move to ${p.name} (${period})? The new price applies from your next invoice, or now if you are on a free plan.`,
        ) &&
        router.post(
            "/settings/subscription/plan",
            { package_id: p.id, billing_period: period },
            { preserveScroll: true },
        );
    return (
        <Card title="Change plan" className="mb-4">
            <div
                className="mb-3 inline-flex rounded-lg border border-line p-0.5 text-sm"
                role="group"
                aria-label="Billing period"
            >
                {(["monthly", "annual"] as const).map((k) => (
                    <button
                        key={k}
                        type="button"
                        aria-pressed={period === k}
                        className={`rounded-md px-3 py-1 ${period === k ? "bg-teal text-white" : ""}`}
                        onClick={() => setPeriod(k)}
                    >
                        {k === "monthly" ? "Monthly" : "Annual"}
                    </button>
                ))}
            </div>
            <ul className="grid gap-3 sm:grid-cols-3 *:min-w-0">
                {plans.map((p) => {
                    const current =
                        p.id === currentId && period === currentPeriod;
                    return (
                        <li
                            key={p.id}
                            className="flex flex-col rounded-lg border border-line p-3 text-sm"
                        >
                            <span className="font-semibold">{p.name}</span>
                            <span className="text-lg">
                                {p.free
                                    ? "Free"
                                    : period === "annual"
                                      ? `${rand(p.annual, 0)} / year`
                                      : `${rand(p.monthly, 0)} / month`}
                            </span>
                            {p.summary && (
                                <span className="mt-1 flex-1 text-xs text-muted">
                                    {p.summary}
                                </span>
                            )}
                            <Button
                                size="sm"
                                className="mt-3"
                                variant={current ? "secondary" : "primary"}
                                disabled={current}
                                onClick={() => choose(p)}
                            >
                                {current ? "Current plan" : `Choose ${p.name}`}
                            </Button>
                        </li>
                    );
                })}
            </ul>
            {errors.package && (
                <p role="alert" className="mt-2 text-sm text-status-danger">
                    {errors.package}
                </p>
            )}
            <p className="mt-2 text-xs text-muted">
                Prices exclude 15% VAT. Changes apply straight away.
            </p>
        </Card>
    );
}

interface AutoDebit {
    card: string;
    expiry: string | null;
    gateway: string;
    since: string;
    chargedBy: string;
}

export default function Subscription({
    plan,
    plans = [],
    invoices,
    autoDebit,
    autoDebitAvailable,
    cardSaveAvailable = false,
    platformGateway,
}: {
    plan: {
        packageId: number;
        billingPeriod: string;
        package: string;
        status: string;
        trialEndsAt: string | null;
        periodEndsAt: string | null;
    } | null;
    plans?: Plan[];
    invoices: Inv[];
    autoDebit: AutoDebit | null;
    autoDebitAvailable: boolean;
    cardSaveAvailable?: boolean;
    platformGateway: string | null;
}) {
    const [consent, setConsent] = useState(false);
    const open = invoices.find((i) => i.status === "open");

    return (
        <AppShell active="Subscription">
            <Head title="Subscription" />
            <h1 className="mb-5 text-2xl font-semibold">Subscription</h1>
            <Flash />
            {plan && (
                <Card
                    title={plan.package}
                    aside={
                        <Badge
                            tone={
                                plan.status === "active" ? "success" : "warning"
                            }
                        >
                            {plan.status.replace("_", " ")}
                        </Badge>
                    }
                    className="mb-4"
                >
                    <p className="text-sm text-muted">
                        {plan.periodEndsAt
                            ? `Paid until ${plan.periodEndsAt}.`
                            : plan.trialEndsAt
                              ? `Free trial until ${plan.trialEndsAt}.`
                              : ""}{" "}
                        Invoices are issued 3 days before each period. Prices
                        exclude 15% VAT.
                    </p>
                </Card>
            )}
            {plan && plans.length > 1 && (
                <ChangePlan
                    plans={plans}
                    currentId={plan.packageId}
                    currentPeriod={plan.billingPeriod}
                />
            )}
            <Card title="Automatic payment" className="mb-4">
                {autoDebit ? (
                    <div className="flex items-center gap-4 text-sm">
                        <CreditCard
                            className="size-6 text-teal"
                            aria-hidden="true"
                        />
                        <div className="flex-1">
                            <div className="font-medium">
                                {autoDebit.card}
                                {autoDebit.expiry && (
                                    <span className="text-muted">
                                        {" "}
                                        · expires {autoDebit.expiry}
                                    </span>
                                )}
                            </div>
                            <div className="text-xs text-muted">
                                Via {autoDebit.gateway} since {autoDebit.since}.
                                Each invoice is charged on its due date by{" "}
                                {autoDebit.chargedBy}; failed payments are
                                retried twice, two days apart, and you're
                                emailed every time.
                            </div>
                        </div>
                        <Button
                            variant="secondary"
                            onClick={() => {
                                if (
                                    window.confirm(
                                        "Switch off automatic payment and remove this card?",
                                    )
                                )
                                    router.post(
                                        "/settings/subscription/auto-debit/stop",
                                    );
                            }}
                        >
                            Switch off
                        </Button>
                    </div>
                ) : !autoDebitAvailable ? (
                    <p className="text-sm text-muted">
                        Automatic payment isn't available with{" "}
                        {platformGateway ?? "the current payment provider"}.
                        Please pay each invoice when it's issued.
                    </p>
                ) : open ? (
                    <div className="text-sm">
                        <label className="flex items-start gap-2">
                            <input
                                type="checkbox"
                                checked={consent}
                                onChange={(e) => setConsent(e.target.checked)}
                                className="mt-0.5 accent-teal"
                            />
                            <span>
                                I authorise Dr Business Flow to save the card I
                                use for invoice {open.number} with{" "}
                                {platformGateway} and charge it for future
                                subscription invoices on their due date. I can
                                switch this off at any time.
                            </span>
                        </label>
                        <Button
                            className="mt-3"
                            disabled={!consent}
                            onClick={() =>
                                router.post(
                                    `/settings/subscription/invoices/${open.number}/auto-pay`,
                                    { consent: true },
                                )
                            }
                        >
                            Pay {rand(open.total, 2)} and turn on automatic
                            payment
                        </Button>
                    </div>
                ) : cardSaveAvailable ? (
                    <div className="text-sm">
                        <label className="flex items-start gap-2">
                            <input
                                type="checkbox"
                                checked={consent}
                                onChange={(e) => setConsent(e.target.checked)}
                                className="mt-0.5 accent-teal"
                            />
                            <span>
                                I authorise Dr Business Flow to save my card
                                with {platformGateway} and charge it for future
                                subscription invoices on their due date. A R1
                                card check is charged and refunded straight
                                away. I can switch this off at any time.
                            </span>
                        </label>
                        <Button
                            className="mt-3"
                            disabled={!consent}
                            onClick={() =>
                                router.post(
                                    "/settings/subscription/save-card",
                                    {
                                        consent: true,
                                    },
                                )
                            }
                        >
                            Save a card for automatic payment
                        </Button>
                    </div>
                ) : (
                    <p className="text-sm text-muted">
                        You can turn on automatic payment when you pay your next
                        invoice (issued 3 days before your period ends).
                    </p>
                )}
            </Card>
            <Card title="Invoices">
                <table className="w-full text-sm">
                    <tbody>
                        {invoices.length === 0 && (
                            <tr>
                                <td className="py-3 text-muted">
                                    No invoices yet.
                                </td>
                            </tr>
                        )}
                        {invoices.map((i) => (
                            <tr
                                key={i.number}
                                className="border-t border-line-soft first:border-0"
                            >
                                <td className="py-2.5 font-medium">
                                    {i.number}
                                </td>
                                <td className="py-2.5 text-muted">
                                    {i.period}
                                </td>
                                <td className="py-2.5">{rand(i.total, 2)}</td>
                                <td className="py-2.5">
                                    <Badge
                                        tone={
                                            i.status === "paid"
                                                ? "success"
                                                : "warning"
                                        }
                                    >
                                        {i.status}
                                    </Badge>
                                    {i.status === "open" &&
                                        i.lastAttempt?.outcome === "failed" && (
                                            <span className="ml-2 text-xs text-status-danger">
                                                Automatic payment failed
                                                {i.lastAttempt.message
                                                    ? `: ${i.lastAttempt.message}`
                                                    : ""}
                                            </span>
                                        )}
                                </td>
                                <td className="py-2.5 text-right">
                                    {i.payUrl && (
                                        <a href={i.payUrl}>
                                            <Button
                                                size="sm"
                                                variant={
                                                    autoDebit
                                                        ? "secondary"
                                                        : "primary"
                                                }
                                            >
                                                Pay now
                                            </Button>
                                        </a>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </AppShell>
    );
}
