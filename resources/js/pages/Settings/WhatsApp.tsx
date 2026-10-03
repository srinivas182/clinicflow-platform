import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Props {
    offered: boolean;
    active: boolean;
    supplierReady: boolean;
    fee: number;
    prices: { utility: number; marketing: number };
    optedIn: number;
}

export default function WhatsAppSettings({
    offered,
    active,
    supplierReady,
    fee,
    prices,
    optedIn,
}: Props) {
    return (
        <AppShell active="Settings">
            <Head title="WhatsApp" />
            <h1 className="mb-1 text-2xl font-semibold">WhatsApp</h1>
            <p className="mb-5 text-sm text-muted">
                Patients who choose WhatsApp and opt in get messages there;
                everyone else, and any message WhatsApp cannot deliver, goes by
                SMS.
            </p>
            <Flash />
            <Card
                title="WhatsApp add-on"
                aside={
                    <Badge tone={active ? "success" : "neutral"}>
                        {active ? "on" : "off"}
                    </Badge>
                }
            >
                {!offered ? (
                    <p className="text-sm text-muted">
                        Your package does not offer WhatsApp.
                    </p>
                ) : (
                    <>
                        <p className="mb-2 text-sm">
                            R{fee} a month on your subscription, plus R
                            {prices.utility} per reminder or update and R
                            {prices.marketing} per marketing message, paid from
                            your wallet.
                        </p>
                        {!supplierReady && (
                            <p className="mb-2 text-xs text-status-warning">
                                WhatsApp is not yet available on Clinic Flow.
                                You can switch it on now; messages go by SMS
                                until it is.
                            </p>
                        )}
                        <p className="mb-3 text-xs text-muted">
                            {optedIn} patient(s) have opted in.
                        </p>
                        <Button
                            onClick={() =>
                                router.put("/settings/whatsapp", {
                                    enabled: !active,
                                })
                            }
                        >
                            {active ? "Switch off" : "Switch on"}
                        </Button>
                    </>
                )}
            </Card>
        </AppShell>
    );
}
