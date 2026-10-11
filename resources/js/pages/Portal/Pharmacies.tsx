import { usePortalText } from "@/lib/portalText";
import { Head } from "@inertiajs/react";
import { Badge, Card } from "@/components/ui";
import { PortalLayout } from "@/layouts/PortalLayout";
import { rand } from "@/lib/money";

interface Props {
    items: { description: string; quantity: number }[];
    pharmacies: {
        id: string;
        name: string;
        publishes: boolean;
        has_all: boolean;
        available: number;
        of: number;
        estimate: number | null;
    }[];
}

export default function Pharmacies({ items, pharmacies }: Props) {
    const t = usePortalText();
    return (
        <PortalLayout provider="Dr Business Flow">
            <Head title={t("portal.pharmacies.compare_pharmacies")} />
            <h1 className="mb-1 text-2xl font-semibold">
                {t("portal.pharmacies.compare_pharmacies")}
            </h1>
            <p className="mb-4 text-sm text-muted">
                {t(
                    "portal.pharmacies.for_your_latest_script_prices_are_estima",
                )}
            </p>
            {items.length === 0 ? (
                <p className="text-sm text-muted">
                    {t("portal.pharmacies.you_have_no_current_script")}
                </p>
            ) : (
                <>
                    <Card
                        title={t("portal.pharmacies.your_script")}
                        className="mb-4"
                    >
                        {items.map((i, k) => (
                            <p key={k} className="text-sm">
                                {i.quantity} × {i.description}
                            </p>
                        ))}
                    </Card>
                    <Card title={t("portal.pharmacies.pharmacies")}>
                        <ul className="divide-y divide-line-soft text-sm">
                            {pharmacies.map((p) => (
                                <li
                                    key={p.id}
                                    className="flex items-center gap-2 py-2"
                                >
                                    <span className="flex-1">{p.name}</span>
                                    {!p.publishes ? (
                                        <Badge>
                                            {t(
                                                "portal.pharmacies.stock_not_published",
                                            )}
                                        </Badge>
                                    ) : (
                                        <Badge
                                            tone={
                                                p.has_all
                                                    ? "success"
                                                    : "warning"
                                            }
                                        >
                                            {t("portal.pharmacies.in_stock", {
                                                available: p.available,
                                                total: p.of,
                                            })}
                                        </Badge>
                                    )}
                                    <span className="w-28 text-right font-medium">
                                        {p.estimate !== null
                                            ? `≈ ${rand(p.estimate, 2)}`
                                            : ""}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </>
            )}
        </PortalLayout>
    );
}
