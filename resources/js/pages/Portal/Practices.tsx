import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Button, Card } from "@/components/ui";
import { PortalLayout } from "@/layouts/PortalLayout";

export default function Practices({
    practices,
    providerName,
}: {
    practices: { provider: string; linked_at: string; provider_id: string }[];
    providerName: string;
}) {
    return (
        <PortalLayout provider={providerName}>
            <Head title="Linked practices" />
            <h1 className="mb-1 text-2xl font-semibold">
                Practices linked to you
            </h1>
            <p className="mb-5 text-sm text-muted">
                These practices can find your records on the Clinic Flow network
                and send your e-scripts. Remove one at any time.
            </p>
            <Flash />
            <Card>
                {practices.length === 0 && (
                    <p className="text-sm text-muted">
                        No practices are linked.
                    </p>
                )}
                <ul className="divide-y divide-line-soft text-sm">
                    {practices.map((p) => (
                        <li
                            key={p.provider_id}
                            className="flex items-center gap-3 py-3"
                        >
                            <span className="flex-1 font-medium">
                                {p.provider}
                            </span>
                            <span className="text-xs text-muted">
                                since {p.linked_at}
                            </span>
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => {
                                    if (window.confirm(`Remove ${p.provider}?`))
                                        router.post(
                                            `/my/practices/${p.provider_id}/revoke`,
                                            {},
                                            { preserveScroll: true },
                                        );
                                }}
                            >
                                Remove
                            </Button>
                        </li>
                    ))}
                </ul>
            </Card>
        </PortalLayout>
    );
}
