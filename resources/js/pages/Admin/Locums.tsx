import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface Profile {
    id: number;
    name: string;
    email: string;
    hpcsa: string;
    qualifications: string;
    status: string;
    documents: {
        id: number;
        kind: string;
        filename: string;
        expires_on: string | null;
    }[];
}

export default function AdminLocums({ profiles }: { profiles: Profile[] }) {
    return (
        <AdminShell active="Locums">
            <Head title="Locum verification" />
            <h1 className="mb-1 text-2xl font-semibold">Locum verification</h1>
            <p className="mb-5 text-sm text-muted">
                Check each HPCSA number on the HPCSA online register and confirm
                the indemnity cover before verifying.
            </p>
            <Flash />
            {profiles.map((p) => (
                <Card
                    key={p.id}
                    title={`${p.name} · ${p.hpcsa}`}
                    aside={
                        <Badge
                            tone={
                                p.status === "verified"
                                    ? "success"
                                    : p.status === "pending"
                                      ? "warning"
                                      : "neutral"
                            }
                        >
                            {p.status}
                        </Badge>
                    }
                    className="mb-3"
                >
                    <p className="mb-2 text-sm">
                        {p.qualifications} · {p.email}
                    </p>
                    <ul className="mb-2 text-sm">
                        {p.documents.map((d) => (
                            <li key={d.id}>
                                <a
                                    className="text-teal-deep"
                                    href={`/admin/locums/documents/${d.id}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    {d.kind}: {d.filename}
                                </a>{" "}
                                {d.expires_on && (
                                    <span className="text-xs text-muted">
                                        valid until {d.expires_on}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `/admin/locums/${p.id}/review`,
                                    { approve: true },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Verify
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                                const note = window.prompt(
                                    "Reason (shown to the locum)",
                                );
                                if (note)
                                    router.post(
                                        `/admin/locums/${p.id}/review`,
                                        { approve: false, note },
                                        { preserveScroll: true },
                                    );
                            }}
                        >
                            Not verified
                        </Button>
                    </div>
                </Card>
            ))}
        </AdminShell>
    );
}
