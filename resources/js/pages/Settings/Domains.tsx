import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Props {
    domains: {
        id: number;
        domain: string;
        token: string;
        status: string;
        ssl_status: string;
        last_check: string | null;
    }[];
    target: string;
    spf: string;
}

export default function Domains({ domains, target, spf }: Props) {
    return (
        <AppShell active="Settings">
            <Head title="Your domain" />
            <div className="mb-1 flex items-center">
                <h1 className="flex-1 text-2xl font-semibold">
                    Your own domain
                </h1>
                <Button
                    onClick={() =>
                        router.post("/settings/domains", {
                            domain:
                                window.prompt(
                                    "Domain, e.g. book.yourpractice.co.za",
                                ) ?? "",
                        })
                    }
                >
                    Add domain
                </Button>
            </div>
            <p className="mb-5 text-sm text-muted">
                Use your own address instead of {target}. A secure certificate
                is issued automatically after verification.
            </p>
            <Flash />
            {domains.map((d) => (
                <Card
                    key={d.id}
                    title={d.domain}
                    aside={
                        <Badge
                            tone={
                                d.status === "verified" ? "success" : "warning"
                            }
                        >
                            {d.status}
                        </Badge>
                    }
                    className="mb-4"
                >
                    <p className="mb-2 text-sm">
                        Create these DNS records with your domain provider:
                    </p>
                    <table className="mb-3 w-full text-xs">
                        <tbody>
                            <tr>
                                <td className="pr-3 font-medium">CNAME</td>
                                <td className="pr-3">{d.domain}</td>
                                <td className="font-mono">{target}</td>
                            </tr>
                            <tr>
                                <td className="pr-3 font-medium">TXT</td>
                                <td className="pr-3">_clinicflow.{d.domain}</td>
                                <td className="font-mono">{d.token}</td>
                            </tr>
                            <tr>
                                <td className="pr-3 font-medium">
                                    TXT (email)
                                </td>
                                <td className="pr-3">
                                    {d.domain.split(".").slice(-3).join(".")}
                                </td>
                                <td className="font-mono">{spf}</td>
                            </tr>
                        </tbody>
                    </table>
                    {d.last_check && (
                        <p className="mb-2 text-xs text-status-warning">
                            {d.last_check}
                        </p>
                    )}
                    <div className="flex gap-2">
                        {d.status !== "verified" && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/settings/domains/${d.id}/verify`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Verify
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                window.confirm("Remove this domain?") &&
                                router.delete(`/settings/domains/${d.id}`)
                            }
                        >
                            Remove
                        </Button>
                    </div>
                </Card>
            ))}
        </AppShell>
    );
}
