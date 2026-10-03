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
    return (
        <PortalLayout provider="Clinic Flow">
            <Head title="Compare pharmacies" />
            <h1 className="mb-1 text-2xl font-semibold">Compare pharmacies</h1>
            <p className="mb-4 text-sm text-muted">
                For your latest script. Prices are estimates — medicine prices
                are regulated, so differences are mostly dispensing fees.
                Confirm at the pharmacy.
            </p>
            {items.length === 0 ? (
                <p className="text-sm text-muted">
                    You have no current script.
                </p>
            ) : (
                <>
                    <Card title="Your script" className="mb-4">
                        {items.map((i, k) => (
                            <p key={k} className="text-sm">
                                {i.quantity} × {i.description}
                            </p>
                        ))}
                    </Card>
                    <Card title="Pharmacies">
                        <ul className="divide-y divide-[#EBF0EE] text-sm">
                            {pharmacies.map((p) => (
                                <li
                                    key={p.id}
                                    className="flex items-center gap-2 py-2"
                                >
                                    <span className="flex-1">{p.name}</span>
                                    {!p.publishes ? (
                                        <Badge>stock not published</Badge>
                                    ) : (
                                        <Badge
                                            tone={
                                                p.has_all
                                                    ? "success"
                                                    : "warning"
                                            }
                                        >
                                            {p.available} of {p.of} in stock
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
