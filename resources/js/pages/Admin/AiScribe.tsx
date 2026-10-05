import { Head, router, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";
import { rand } from "@/lib/money";

interface Props {
    providers: {
        driver: string;
        kind: string;
        enabled: boolean;
        configured: boolean;
        model: string | null;
        region: string | null;
        costPerMinute: number;
    }[];
    prices: {
        monthly: number;
        minutes: number;
        perMinute: number;
        maxMinutes: number;
    };
    usage: {
        practice: string;
        included: number;
        wallet: number;
        walletRands: number;
    }[];
}
const LABELS: Record<string, string> = {
    deepgram: "Deepgram Nova-3 Medical",
    azure: "Azure AI Speech",
    anthropic: "Claude (Anthropic)",
};

export default function AdminAiScribe({ providers, prices, usage }: Props) {
    const price = useForm({
        monthly: String(prices.monthly),
        minutes: String(prices.minutes),
        per_minute: String(prices.perMinute),
        max_minutes: String(prices.maxMinutes),
    });
    const input = "w-28 rounded-md border border-line px-2 py-1 text-sm";

    return (
        <AdminShell active="AI scribe">
            <Head title="AI scribe" />
            <h1 className="mb-1 text-2xl font-semibold">AI scribe</h1>
            <p className="mb-5 text-sm text-muted">
                One speech-to-text provider and one note writer can be active.
                Practices pay for every minute (included minutes first, then
                their wallet).
            </p>
            <Flash />
            <div className="grid grid-cols-3 gap-4">
                {providers.map((p) => (
                    <Card
                        key={p.driver}
                        title={LABELS[p.driver] ?? p.driver}
                        aside={
                            <Badge tone={p.enabled ? "success" : "neutral"}>
                                {p.enabled
                                    ? "active"
                                    : p.configured
                                      ? "configured"
                                      : "not set up"}
                            </Badge>
                        }
                    >
                        <p className="mb-2 text-xs text-muted">
                            {p.kind === "speech"
                                ? "Speech-to-text"
                                : "Note writer"}
                            {p.model ? ` · ${p.model}` : ""}
                            {p.region ? ` · ${p.region}` : ""} · cost{" "}
                            {rand(p.costPerMinute, 4)}/min
                        </p>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.post(
                                    `/admin/ai-scribe/providers/${p.driver}`,
                                    {
                                        api_key:
                                            window.prompt(
                                                "API key (leave empty to keep the current key)",
                                            ) || null,
                                        model:
                                            window.prompt(
                                                "Model",
                                                p.model ??
                                                    (p.driver === "anthropic"
                                                        ? "claude-sonnet-5-5"
                                                        : p.driver ===
                                                            "deepgram"
                                                          ? "nova-3-medical"
                                                          : ""),
                                            ) || null,
                                        region:
                                            p.driver === "azure"
                                                ? window.prompt(
                                                      "Region",
                                                      p.region ??
                                                          "southafricanorth",
                                                  )
                                                : null,
                                        summary_model:
                                            p.driver === "anthropic"
                                                ? window.prompt(
                                                      "Model for lab explanations",
                                                      "claude-haiku-4-5",
                                                  )
                                                : null,
                                        cost_per_minute:
                                            window.prompt(
                                                "Provider cost per minute (R)",
                                                String(p.costPerMinute),
                                            ) || 0,
                                        enabled: window.confirm(
                                            "Make this the active provider?",
                                        ),
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Set up
                        </Button>
                    </Card>
                ))}
            </div>
            <Card title="Prices for practices" className="mt-4">
                <div className="flex flex-wrap items-end gap-3 text-sm">
                    <label>
                        Add-on per month (R)
                        <br />
                        <input
                            className={input}
                            value={price.data.monthly}
                            onChange={(e) =>
                                price.setData("monthly", e.target.value)
                            }
                        />
                    </label>
                    <label>
                        Included minutes
                        <br />
                        <input
                            className={input}
                            value={price.data.minutes}
                            onChange={(e) =>
                                price.setData("minutes", e.target.value)
                            }
                        />
                    </label>
                    <label>
                        Extra minute (R)
                        <br />
                        <input
                            className={input}
                            value={price.data.per_minute}
                            onChange={(e) =>
                                price.setData("per_minute", e.target.value)
                            }
                        />
                    </label>
                    <label>
                        Max recording (min)
                        <br />
                        <input
                            className={input}
                            value={price.data.max_minutes}
                            onChange={(e) =>
                                price.setData("max_minutes", e.target.value)
                            }
                        />
                    </label>
                    <Button
                        size="sm"
                        onClick={() =>
                            price.post("/admin/ai-scribe/prices", {
                                preserveScroll: true,
                            })
                        }
                    >
                        Save prices
                    </Button>
                </div>
                {Object.values(price.errors)[0] && (
                    <p className="mt-2 text-xs text-status-danger">
                        {Object.values(price.errors)[0]}
                    </p>
                )}
            </Card>
            <Card title="Usage this month" className="mt-4">
                {usage.length === 0 && (
                    <p className="text-sm text-muted">No use yet.</p>
                )}
                {usage.map((u, i) => (
                    <p key={i} className="text-sm">
                        {u.practice}: {u.included} included minutes, {u.wallet}{" "}
                        wallet minutes ({rand(u.walletRands, 2)})
                    </p>
                ))}
            </Card>
        </AdminShell>
    );
}
