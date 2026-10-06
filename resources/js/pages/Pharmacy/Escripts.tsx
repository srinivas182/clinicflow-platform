import { Head, router } from "@inertiajs/react";
import { ShieldCheck } from "lucide-react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { Pager, type Paginated } from "@/components/Pager";

interface Item {
    description: string;
    nappi_code: string;
    schedule: string;
    dose: string;
    quantity: number;
    repeats: number;
}

interface Escript {
    id: string;
    status: string;
    note: string | null;
    version: number;
    sentAt: string;
    practice: string;
    fingerprint: string;
    payload: {
        patient: { name: string; date_of_birth: string };
        doctor: { name: string | null; hpcsa: string | null };
        signed_at: string | null;
        items: Item[];
    };
}

const tone: Record<
    string,
    "teal" | "success" | "danger" | "neutral" | "warning"
> = {
    sent: "warning",
    accepted: "teal",
    dispensed: "success",
    rejected: "danger",
    cancelled: "neutral",
};

export default function Escripts({
    escripts,
}: {
    escripts: Paginated<Escript>;
}) {
    const act = (
        id: string,
        action: string,
        data: Record<string, string> = {},
    ) =>
        router.post(`/escripts/${id}/${action}`, data, {
            preserveScroll: true,
        });

    return (
        <AppShell active="E-scripts">
            <Head title="E-scripts" />
            <h1 className="mb-1 text-2xl font-semibold">E-scripts</h1>
            <p className="mb-5 text-sm text-muted">
                Scripts sent to you by doctors on the network. Each shows the
                signed content and its signature fingerprint. Replaced versions
                are cancelled automatically.
            </p>
            <Flash />
            <div className="flex flex-col gap-3">
                {escripts.data.length === 0 && (
                    <p className="text-sm text-muted">No e-scripts yet.</p>
                )}
                {escripts.data.map((e) => (
                    <Card
                        key={e.id}
                        title={`${e.payload.patient.name} · born ${e.payload.patient.date_of_birth}`}
                        aside={
                            <Badge tone={tone[e.status] ?? "neutral"}>
                                {e.status}
                            </Badge>
                        }
                    >
                        <p className="text-xs text-muted">
                            {e.practice} · Dr {e.payload.doctor.name} (
                            {e.payload.doctor.hpcsa}) · version {e.version} ·
                            received {e.sentAt}
                        </p>
                        <p className="mt-1 flex items-center gap-1 text-xs text-status-success">
                            <ShieldCheck className="size-3.5" /> Signed{" "}
                            {e.payload.signed_at
                                ?.slice(0, 16)
                                .replace("T", " ")}{" "}
                            · fingerprint {e.fingerprint}…
                        </p>
                        <ul className="mt-2 text-sm">
                            {e.payload.items.map((i, k) => (
                                <li key={k}>
                                    {i.description} ({i.schedule}) — {i.dose} ·
                                    qty {i.quantity}
                                    {i.repeats > 0 && ` · ${i.repeats} repeats`}
                                </li>
                            ))}
                        </ul>
                        {e.note && (
                            <p className="mt-1 text-xs text-muted">{e.note}</p>
                        )}
                        <div className="mt-3 flex gap-2">
                            {e.status === "sent" && (
                                <>
                                    <Button
                                        size="sm"
                                        onClick={() => act(e.id, "accept")}
                                    >
                                        Accept
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => {
                                            const reason = window.prompt(
                                                "Reason for the doctor and patient?",
                                            );
                                            if (reason)
                                                act(e.id, "reject", { reason });
                                        }}
                                    >
                                        Reject
                                    </Button>
                                </>
                            )}
                            {e.status === "accepted" && (
                                <Button
                                    size="sm"
                                    onClick={() => act(e.id, "dispense")}
                                >
                                    Mark dispensed
                                </Button>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
            <Pager page={escripts} />
        </AppShell>
    );
}
