import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

/** Practice Settings → Security (owner only). */
export default function PracticeSecurity({
    required,
    staff,
    withoutApp,
}: {
    required: boolean;
    staff: number;
    withoutApp: number;
}) {
    return (
        <AppShell active="Settings">
            <Head title="Security" />
            <h1 className="mb-1 text-2xl font-semibold">Security</h1>
            <p className="mb-5 text-sm text-muted">
                Owners and practice admins always use an authenticator app. You
                can require it for everyone at the practice.
            </p>
            <Flash />
            <Card
                title="Authenticator app for all staff"
                aside={
                    <Badge tone={required ? "success" : "neutral"}>
                        {required ? "required" : "optional"}
                    </Badge>
                }
            >
                <p className="mb-3 text-sm">
                    {staff} staff · {withoutApp} without an authenticator app
                    yet.{" "}
                    {required
                        ? "They are asked to set it up when they next open Dr Business Flow."
                        : "Requiring it protects patient records if a password is stolen."}
                </p>
                <Button
                    size="sm"
                    variant={required ? "secondary" : "primary"}
                    onClick={() =>
                        router.post(
                            "/settings/security",
                            { required: !required },
                            { preserveScroll: true },
                        )
                    }
                >
                    {required ? "Make it optional" : "Require for all staff"}
                </Button>
            </Card>
        </AppShell>
    );
}
