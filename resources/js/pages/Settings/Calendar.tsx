import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Props {
    connection: {
        driver: string | null;
        showInitials: boolean;
        importBusy: boolean;
        error: string | null;
    };
    icalUrl: string;
    apps: string[];
}

export default function CalendarSettings({ connection, icalUrl, apps }: Props) {
    const [initials, setInitials] = useState(connection.showInitials);
    const [busy, setBusy] = useState(connection.importBusy);

    return (
        <AppShell active="Settings">
            <Head title="My calendar" />
            <h1 className="mb-1 text-2xl font-semibold">My calendar</h1>
            <p className="mb-5 text-sm text-muted">
                Your appointments appear as "Appointment" in your own calendar —
                no patient names or reasons.
            </p>
            <Flash />
            <Card
                title="Connected calendar"
                aside={
                    connection.driver ? (
                        <Badge tone="success">{connection.driver}</Badge>
                    ) : (
                        <Badge>not connected</Badge>
                    )
                }
                className="mb-4"
            >
                {connection.error && (
                    <p role="alert" className="mb-2 text-sm text-status-danger">
                        {connection.error}
                    </p>
                )}
                <div className="mb-3 flex gap-2">
                    {apps.map((a) => (
                        <a key={a} href={`/me/calendar/${a}/connect`}>
                            <Button variant="secondary">
                                Connect{" "}
                                {a === "google"
                                    ? "Google Calendar"
                                    : "Microsoft 365 / Outlook"}
                            </Button>
                        </a>
                    ))}
                </div>
                <label className="mb-1 flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="accent-teal"
                        checked={initials}
                        onChange={(e) => setInitials(e.target.checked)}
                    />{" "}
                    Show patient initials
                </label>
                <label className="mb-3 flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="accent-teal"
                        checked={busy}
                        onChange={(e) => setBusy(e.target.checked)}
                    />{" "}
                    Block my bookable times when my calendar is busy
                </label>
                <div className="flex gap-2">
                    <Button
                        size="sm"
                        onClick={() =>
                            router.put("/me/calendar", {
                                show_initials: initials,
                                import_busy: busy,
                            })
                        }
                    >
                        Save
                    </Button>
                    {connection.driver && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.put("/me/calendar", {
                                    show_initials: initials,
                                    import_busy: busy,
                                    disconnect: true,
                                })
                            }
                        >
                            Disconnect
                        </Button>
                    )}
                </div>
            </Card>
            <Card title="Calendar feed (any calendar app)">
                <p className="mb-2 break-all font-mono text-xs">{icalUrl}</p>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        router.put("/me/calendar", {
                            show_initials: initials,
                            import_busy: busy,
                            new_feed: true,
                        })
                    }
                >
                    Make a new link (stops the old one)
                </Button>
            </Card>
        </AppShell>
    );
}
