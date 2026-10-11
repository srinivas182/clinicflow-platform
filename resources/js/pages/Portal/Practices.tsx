import { usePortalText } from "@/lib/portalText";
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
    const t = usePortalText();
    return (
        <PortalLayout provider={providerName}>
            <Head title={t("portal.practices.linked_practices")} />
            <h1 className="mb-1 text-2xl font-semibold">
                {t("portal.practices.practices_linked_to_you")}
            </h1>
            <p className="mb-5 text-sm text-muted">
                {t("portal.practices.these_practices_can_find_your_records_on")}
            </p>
            <Flash />
            <Card>
                {practices.length === 0 && (
                    <p className="text-sm text-muted">
                        {t("portal.practices.no_practices_are_linked")}
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
                                {t("portal.practices.remove")}
                            </Button>
                        </li>
                    ))}
                </ul>
            </Card>
        </PortalLayout>
    );
}
