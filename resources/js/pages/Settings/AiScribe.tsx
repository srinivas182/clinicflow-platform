import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";

interface Props {
    offered: boolean;
    on: boolean;
    allowance: {
        enabled: boolean;
        included: number;
        used: number;
        left: number;
        price_per_minute_cents: number;
    };
    prices: {
        monthly: number;
        minutes: number;
        perMinute: number;
        maxMinutes: number;
    };
    available: boolean;
}

export default function AiScribeSettings({
    offered,
    on,
    allowance,
    prices,
    available,
}: Props) {
    return (
        <AppShell active="Settings">
            <Head title="AI scribe" />
            <h1 className="mb-1 text-2xl font-semibold">AI scribe</h1>
            <p className="mb-5 text-sm text-muted">
                With the patient&apos;s agreement, a consultation recording is
                transcribed and drafted into a note for the doctor to review,
                edit and save. Nothing is saved or signed by itself, and
                recordings are not kept.
            </p>
            <Flash />
            <Card
                title="Add-on"
                aside={
                    <Badge tone={on ? "success" : "neutral"}>
                        {on ? "on" : "off"}
                    </Badge>
                }
                className="mb-4"
            >
                <p className="mb-2 text-sm">
                    {rand(prices.monthly, 2)} per month, including{" "}
                    {prices.minutes} minutes. Extra minutes{" "}
                    {rand(prices.perMinute, 2)} each, paid from your wallet.
                    Recordings up to {prices.maxMinutes} minutes.
                </p>
                {!available && (
                    <p className="mb-2 text-xs text-status-warning">
                        The AI scribe service is not available yet.
                    </p>
                )}
                {offered ? (
                    <Button
                        size="sm"
                        variant={on ? "secondary" : "primary"}
                        onClick={() =>
                            router.post(
                                "/settings/ai-scribe",
                                { enabled: !on },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {on ? "Turn off" : "Turn on"}
                    </Button>
                ) : (
                    <p className="text-sm text-muted">
                        Your package does not offer the AI scribe.
                    </p>
                )}
            </Card>
            {allowance.enabled && (
                <Card title="This month">
                    <p className="text-sm">
                        Included minutes used: {allowance.used} of{" "}
                        {allowance.included} ({allowance.left} left). After
                        that, {rand(allowance.price_per_minute_cents / 100, 2)}{" "}
                        per minute from your wallet. A recording your wallet
                        cannot cover will not start.
                    </p>
                </Card>
            )}
        </AppShell>
    );
}
