import { Head, router, useForm } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";

type Avail = {
    mode: string;
    weekday: number;
    start_time: string;
    end_time: string;
};
type Price = {
    staff_id: number | null;
    mode: string;
    duration_minutes: number;
    price: string;
};
interface Doctor {
    id: number;
    name: string;
    availability: Avail[];
    exceptions: {
        id: number;
        date: string;
        type: string;
        mode: string | null;
        start_time: string | null;
        end_time: string | null;
        note: string | null;
    }[];
}
interface Props {
    doctors: Doctor[];
    prices: {
        staff_id: number | null;
        mode: string;
        duration_minutes: number;
        price_cents: number;
    }[];
    rules: Record<string, number>;
    noShowMinutes: number;
    enabled: boolean;
    refundTasks: {
        id: number;
        amount: number;
        reason: string;
        due: string;
        overdue: boolean;
        gateway: string;
    }[];
}

const DAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
const MODES = ["video", "audio", "chat"];
const input = "rounded-md border border-line px-2 py-1 text-sm";

function DoctorHours({ d }: { d: Doctor }) {
    const [rows, setRows] = useState<Avail[]>(
        d.availability.map((a) => ({
            ...a,
            start_time: a.start_time.slice(0, 5),
            end_time: a.end_time.slice(0, 5),
        })),
    );
    const ex = useForm({
        date: "",
        type: "off",
        mode: "",
        start_time: "",
        end_time: "",
        note: "",
    });

    return (
        <Card title={d.name} className="mb-3">
            <div className="mb-2 text-xs font-medium">Weekly online hours</div>
            {rows.map((r, i) => (
                <div key={i} className="mb-1.5 flex flex-wrap gap-2">
                    <select
                        aria-label="Mode"
                        className={input}
                        value={r.mode}
                        onChange={(e) =>
                            setRows(
                                rows.map((x, j) =>
                                    j === i
                                        ? { ...x, mode: e.target.value }
                                        : x,
                                ),
                            )
                        }
                    >
                        {["all", ...MODES].map((m) => (
                            <option key={m}>{m}</option>
                        ))}
                    </select>
                    <select
                        aria-label="Day"
                        className={input}
                        value={r.weekday}
                        onChange={(e) =>
                            setRows(
                                rows.map((x, j) =>
                                    j === i
                                        ? {
                                              ...x,
                                              weekday: Number(e.target.value),
                                          }
                                        : x,
                                ),
                            )
                        }
                    >
                        {DAYS.map((day, k) => (
                            <option key={k} value={k}>
                                {day}
                            </option>
                        ))}
                    </select>
                    <input
                        aria-label="From"
                        type="time"
                        className={input}
                        value={r.start_time}
                        onChange={(e) =>
                            setRows(
                                rows.map((x, j) =>
                                    j === i
                                        ? { ...x, start_time: e.target.value }
                                        : x,
                                ),
                            )
                        }
                    />
                    <input
                        aria-label="To"
                        type="time"
                        className={input}
                        value={r.end_time}
                        onChange={(e) =>
                            setRows(
                                rows.map((x, j) =>
                                    j === i
                                        ? { ...x, end_time: e.target.value }
                                        : x,
                                ),
                            )
                        }
                    />
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setRows(rows.filter((_, j) => j !== i))}
                    >
                        Remove
                    </Button>
                </div>
            ))}
            <div className="flex gap-2">
                <Button
                    size="sm"
                    variant="secondary"
                    onClick={() =>
                        setRows([
                            ...rows,
                            {
                                mode: "all",
                                weekday: 1,
                                start_time: "09:00",
                                end_time: "12:00",
                            },
                        ])
                    }
                >
                    Add hours
                </Button>
                <Button
                    size="sm"
                    onClick={() =>
                        router.put(
                            `/settings/telemedicine/availability/${d.id}`,
                            { rows },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save hours
                </Button>
            </div>
            <div className="mt-4 mb-2 text-xs font-medium">
                Date exceptions (leave, holidays, extra sessions)
            </div>
            <ul className="mb-2 text-sm">
                {d.exceptions.map((e) => (
                    <li key={e.id} className="flex items-center gap-2">
                        <Badge tone={e.type === "off" ? "warning" : "success"}>
                            {e.type === "off" ? "off" : "extra"}
                        </Badge>
                        {e.date.slice(0, 10)}{" "}
                        {e.start_time
                            ? `${e.start_time.slice(0, 5)}–${e.end_time?.slice(0, 5)}`
                            : "all day"}{" "}
                        {e.note}
                        <button
                            type="button"
                            className="text-xs text-status-danger"
                            onClick={() =>
                                router.delete(
                                    `/settings/telemedicine/exceptions/${e.id}`,
                                    { preserveScroll: true },
                                )
                            }
                        >
                            remove
                        </button>
                    </li>
                ))}
            </ul>
            <div className="flex flex-wrap gap-2">
                <input
                    aria-label="Date"
                    type="date"
                    className={input}
                    value={ex.data.date}
                    onChange={(e) => ex.setData("date", e.target.value)}
                />
                <select
                    aria-label="Exception type"
                    className={input}
                    value={ex.data.type}
                    onChange={(e) => ex.setData("type", e.target.value)}
                >
                    <option value="off">Not available</option>
                    <option value="extra">Extra session</option>
                </select>
                <input
                    aria-label="Exception from"
                    type="time"
                    className={input}
                    value={ex.data.start_time}
                    onChange={(e) => ex.setData("start_time", e.target.value)}
                />
                <input
                    aria-label="Exception to"
                    type="time"
                    className={input}
                    value={ex.data.end_time}
                    onChange={(e) => ex.setData("end_time", e.target.value)}
                />
                <input
                    aria-label="Note"
                    placeholder="Note"
                    className={input}
                    value={ex.data.note}
                    onChange={(e) => ex.setData("note", e.target.value)}
                />
                <Button
                    size="sm"
                    variant="secondary"
                    onClick={() =>
                        ex.post(`/settings/telemedicine/exceptions/${d.id}`, {
                            preserveScroll: true,
                            onSuccess: () => ex.reset(),
                        })
                    }
                >
                    Add
                </Button>
            </div>
        </Card>
    );
}

export default function TelemedicineSettings({
    doctors,
    prices,
    rules,
    noShowMinutes,
    enabled,
    refundTasks,
}: Props) {
    const [rows, setRows] = useState<Price[]>(
        prices.length
            ? prices.map((p) => ({
                  staff_id: p.staff_id,
                  mode: p.mode,
                  duration_minutes: p.duration_minutes,
                  price: String(p.price_cents / 100),
              }))
            : MODES.map((m) => ({
                  staff_id: null,
                  mode: m,
                  duration_minutes: 15,
                  price: "",
              })),
    );
    const rulesForm = useForm({ ...rules });

    return (
        <AppShell active="Settings">
            <Head title="Online consults" />
            <h1 className="mb-1 text-2xl font-semibold">Online consults</h1>
            <p className="mb-5 text-sm text-muted">
                Patients choose doctor, mode, duration and time, and pay at
                booking through your payment gateway.{" "}
                {!enabled &&
                    "Switch on the Telemedicine add-on in Settings → Wallet first."}
            </p>
            <Flash />
            {refundTasks.length > 0 && (
                <Card
                    title="Refunds to make in your payment gateway"
                    className="mb-4"
                >
                    <ul className="space-y-2 text-sm">
                        {refundTasks.map((t) => (
                            <li key={t.id} className="flex items-center gap-2">
                                <span className="flex-1">
                                    {rand(t.amount, 2)} · {t.reason} ·{" "}
                                    {t.gateway}
                                </span>
                                <Badge tone={t.overdue ? "danger" : "warning"}>
                                    due {t.due}
                                </Badge>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => {
                                        const reference = window.prompt(
                                            "Refund reference from your gateway or bank",
                                        );
                                        if (reference)
                                            router.post(
                                                `/refund-tasks/${t.id}/complete`,
                                                { reference },
                                                { preserveScroll: true },
                                            );
                                    }}
                                >
                                    Mark refunded
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
            <div className="grid grid-cols-2 gap-4">
                <div>
                    {doctors.map((d) => (
                        <DoctorHours key={d.id} d={d} />
                    ))}
                </div>
                <div>
                    <Card title="Prices (excl. extras)" className="mb-4">
                        <p className="mb-2 text-xs text-muted">
                            15 minutes is the minimum. Add longer durations to
                            offer them; leave doctor empty for the practice
                            price.
                        </p>
                        {rows.map((r, i) => (
                            <div
                                key={i}
                                className="mb-1.5 flex flex-wrap items-center gap-2"
                            >
                                <select
                                    aria-label="Doctor"
                                    className={input}
                                    value={r.staff_id ?? ""}
                                    onChange={(e) =>
                                        setRows(
                                            rows.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          staff_id: e.target
                                                              .value
                                                              ? Number(
                                                                    e.target
                                                                        .value,
                                                                )
                                                              : null,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                >
                                    <option value="">Practice</option>
                                    {doctors.map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {d.name}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    aria-label="Mode"
                                    className={input}
                                    value={r.mode}
                                    onChange={(e) =>
                                        setRows(
                                            rows.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          mode: e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                >
                                    {MODES.map((m) => (
                                        <option key={m}>{m}</option>
                                    ))}
                                </select>
                                <select
                                    aria-label="Minutes"
                                    className={input}
                                    value={r.duration_minutes}
                                    onChange={(e) =>
                                        setRows(
                                            rows.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          duration_minutes:
                                                              Number(
                                                                  e.target
                                                                      .value,
                                                              ),
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                >
                                    {[15, 30, 45, 60].map((m) => (
                                        <option key={m} value={m}>
                                            {m} min
                                        </option>
                                    ))}
                                </select>
                                <input
                                    aria-label="Price (R)"
                                    placeholder="R"
                                    className={`${input} w-24`}
                                    value={r.price}
                                    onChange={(e) =>
                                        setRows(
                                            rows.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          price: e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                />
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        setRows(rows.filter((_, j) => j !== i))
                                    }
                                >
                                    Remove
                                </Button>
                            </div>
                        ))}
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() =>
                                    setRows([
                                        ...rows,
                                        {
                                            staff_id: null,
                                            mode: "video",
                                            duration_minutes: 30,
                                            price: "",
                                        },
                                    ])
                                }
                            >
                                Add price
                            </Button>
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.put(
                                        "/settings/telemedicine/prices",
                                        { rows },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Save prices
                            </Button>
                        </div>
                    </Card>
                    <Card title="Rules">
                        {(
                            [
                                [
                                    "hold_minutes",
                                    "Hold the slot while the patient pays (min)",
                                ],
                                [
                                    "grace_minutes",
                                    "Grace after the booked time (min)",
                                ],
                                [
                                    "cancel_cutoff_minutes",
                                    "Free cancellation until this long before (min)",
                                ],
                                [
                                    "followup_days",
                                    "Follow-up chat window (days)",
                                ],
                                ["extension_minutes", "Extension block (min)"],
                                [
                                    "buffer_minutes",
                                    "Buffer between consults (min)",
                                ],
                            ] as [string, string][]
                        ).map(([key, label]) => (
                            <label
                                key={key}
                                className="mb-2 flex items-center gap-2 text-sm"
                            >
                                <span className="flex-1">{label}</span>
                                <input
                                    aria-label={label}
                                    type="number"
                                    className={`${input} w-24`}
                                    value={rulesForm.data[key]}
                                    onChange={(e) =>
                                        rulesForm.setData(
                                            key,
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </label>
                        ))}
                        <p className="mb-2 text-xs text-muted">
                            If the doctor has not joined {noShowMinutes} minutes
                            after the start, the patient is refunded in full
                            automatically.
                        </p>
                        <Button
                            size="sm"
                            onClick={() =>
                                rulesForm.put("/settings/telemedicine/rules", {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Save rules
                        </Button>
                    </Card>
                </div>
            </div>
        </AppShell>
    );
}
