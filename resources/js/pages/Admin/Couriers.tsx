import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

export default function AdminCouriers({
    partners,
}: {
    partners: {
        driver: string;
        label: string;
        enabled: boolean;
        apiReady: boolean;
    }[];
}) {
    return (
        <AdminShell active="Couriers">
            <Head title="Couriers" />
            <h1 className="mb-1 text-2xl font-semibold">Courier partners</h1>
            <p className="mb-5 text-sm text-muted">
                Enable the couriers practices may link their own accounts to.
                Mark "API connected" once the courier's API has been set up and
                tested with sandbox credentials.
            </p>
            <Flash />
            {partners.map((p) => (
                <Card
                    key={p.driver}
                    title={p.label}
                    aside={
                        <span className="flex gap-1">
                            <Badge tone={p.enabled ? "success" : "neutral"}>
                                {p.enabled ? "enabled" : "off"}
                            </Badge>
                            <Badge>
                                {p.apiReady
                                    ? "API connected"
                                    : "manual booking"}
                            </Badge>
                        </span>
                    }
                    className="mb-3"
                >
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            onClick={() =>
                                router.put(
                                    `/admin/couriers/${p.driver}`,
                                    {
                                        enabled: !p.enabled,
                                        api_ready: p.apiReady,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {p.enabled ? "Disable" : "Enable"}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.put(
                                    `/admin/couriers/${p.driver}`,
                                    {
                                        enabled: p.enabled,
                                        api_ready: !p.apiReady,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {p.apiReady
                                ? "Mark API not connected"
                                : "Mark API connected"}
                        </Button>
                    </div>
                </Card>
            ))}
        </AdminShell>
    );
}
