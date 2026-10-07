import { Head, router, usePoll } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card, Ticket } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface ScriptItem {
    description: string;
    dose: string;
    quantity: number;
    repeats: number;
    schedule: string;
}

interface QueueRow {
    visitId: string;
    ticket: string;
    patient: string;
    stage: string;
    script: { id: string; version: number; items: ScriptItem[] } | null;
}

export default function Pharmacy({
    queue,
    owing,
    stock,
    register,
}: {
    queue: QueueRow[];
    owing: {
        id: number;
        patient: string;
        quantity: number;
        item: string | null;
    }[];
    stock: {
        description: string;
        schedule: string;
        onHand: number;
        reorder: boolean;
    }[];
    register: {
        at: string;
        schedule: string;
        movement: string;
        quantity: number;
        balance: number;
        item: string | null;
    }[];
}) {
    usePoll(15000, { only: ["queue", "owing"] });
    const [codes, setCodes] = useState<Record<string, string>>({});

    return (
        <AppShell active="Pharmacy">
            <Head title="Pharmacy" />
            <h1 className="mb-1 text-2xl font-semibold">Pharmacy</h1>
            <p className="mb-5 text-sm text-muted">
                Dispense the current signed version only. Short items go on the
                owing list and are billed when supplied.
            </p>
            <Flash />
            <div className="grid grid-cols-3 gap-4">
                <Card title="Queue" className="col-span-2">
                    {queue.length === 0 && (
                        <p className="text-sm text-muted">Nobody is waiting.</p>
                    )}
                    <ul className="space-y-3">
                        {queue.map((q) => (
                            <li
                                key={q.visitId}
                                className="rounded-lg border border-line p-3 text-sm"
                            >
                                <div className="flex items-center gap-3">
                                    <Ticket number={q.ticket} />
                                    <span className="flex-1 font-medium">
                                        {q.patient}
                                    </span>
                                    <Badge
                                        tone={
                                            q.stage === "dispatch"
                                                ? "warning"
                                                : "teal"
                                        }
                                    >
                                        {q.stage === "dispatch"
                                            ? "Ready to collect"
                                            : "To dispense"}
                                    </Badge>
                                </div>
                                {q.script && (
                                    <ul className="mt-2 text-xs text-muted">
                                        {q.script.items.map((i, k) => (
                                            <li key={k}>
                                                {i.description} · {i.dose} · qty{" "}
                                                {i.quantity}
                                                {i.repeats > 0 &&
                                                    ` · ${i.repeats} repeats`}{" "}
                                                {["S5", "S6"].includes(
                                                    i.schedule,
                                                ) && (
                                                    <Badge tone="danger">
                                                        {i.schedule} register
                                                    </Badge>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <div className="mt-2 flex gap-2">
                                    {q.stage === "pharmacy" && q.script && (
                                        <>
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/pharmacy/visits/${q.visitId}/dispense`,
                                                        {
                                                            prescription_id:
                                                                q.script?.id,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Dispense v{q.script.version}
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                onClick={() => {
                                                    const question =
                                                        window.prompt(
                                                            "Question for the doctor?",
                                                        );
                                                    if (question)
                                                        router.post(
                                                            `/pharmacy/visits/${q.visitId}/query`,
                                                            {
                                                                prescription_id:
                                                                    q.script
                                                                        ?.id,
                                                                question,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                }}
                                            >
                                                Query doctor
                                            </Button>
                                        </>
                                    )}
                                    {q.stage === "dispatch" && (
                                        <>
                                            <input
                                                aria-label="Collection code"
                                                inputMode="numeric"
                                                maxLength={4}
                                                placeholder="Code"
                                                value={codes[q.visitId] ?? ""}
                                                onChange={(e) =>
                                                    setCodes({
                                                        ...codes,
                                                        [q.visitId]:
                                                            e.target.value.replace(
                                                                /\D/g,
                                                                "",
                                                            ),
                                                    })
                                                }
                                                className="w-20 rounded border border-line px-2 py-1"
                                            />
                                            <Button
                                                size="sm"
                                                disabled={
                                                    (codes[q.visitId] ?? "")
                                                        .length !== 4
                                                }
                                                onClick={() =>
                                                    router.post(
                                                        `/pharmacy/visits/${q.visitId}/collect`,
                                                        {
                                                            code: codes[
                                                                q.visitId
                                                            ],
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Hand over
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
                <div className="flex flex-col gap-4">
                    <Card title="Owing list">
                        {owing.length === 0 && (
                            <p className="text-sm text-muted">Nothing owing.</p>
                        )}
                        <ul className="space-y-2 text-sm">
                            {owing.map((o) => (
                                <li
                                    key={o.id}
                                    className="flex items-center gap-2"
                                >
                                    <span className="flex-1">
                                        {o.patient}
                                        <span className="block text-xs text-muted">
                                            {o.item} × {o.quantity}
                                        </span>
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() =>
                                            router.post(
                                                `/pharmacy/owing/${o.id}/fulfil`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Supply
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card title="Stock">
                        <ul className="space-y-1 text-sm">
                            {stock.map((s) => (
                                <li key={s.description} className="flex gap-2">
                                    <span className="flex-1">
                                        {s.description}
                                    </span>
                                    <span
                                        className={
                                            s.reorder
                                                ? "font-semibold text-status-danger"
                                                : ""
                                        }
                                    >
                                        {s.onHand}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card title="S5/S6 register">
                        <ul className="space-y-1 text-xs">
                            {register.map((r, i) => (
                                <li key={i}>
                                    {r.at} · {r.schedule} · {r.item} ·{" "}
                                    {r.movement} {r.quantity} → {r.balance}
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>
        </AppShell>
    );
}
