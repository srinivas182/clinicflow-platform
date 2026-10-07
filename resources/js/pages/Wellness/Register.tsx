import { Head, useForm, usePage } from "@inertiajs/react";
import { Button, Card } from "@/components/ui";

interface Props {
    token: string;
    practice: string;
    company: string;
    title: string;
    location: string;
    open: boolean;
    slots: { at: string; free: number }[];
    services: string[];
}
const LABELS: Record<string, string> = {
    bp: "blood pressure",
    glucose: "blood sugar",
    cholesterol: "cholesterol",
    bmi: "weight and BMI",
    flu: "flu vaccine",
};

export default function WellnessRegister({
    token,
    practice,
    company,
    title,
    location,
    open,
    slots,
    services,
}: Props) {
    const { flash } = usePage<{ flash: { success: string | null } }>().props;
    const form = useForm({
        first_names: "",
        surname: "",
        id_number: "",
        date_of_birth: "",
        cell: "",
        email: "",
        slot_at: slots[0]?.at ?? "",
        consent: false,
    });
    const input = "mb-2 w-full rounded-md border border-line px-3 py-2 text-sm";

    return (
        <div className="mx-auto max-w-md px-4 py-10">
            <Head title={title} />
            <Card title={`${title} · ${company}`}>
                <p className="mb-3 text-sm text-muted">
                    With {practice} at {location}. Checks:{" "}
                    {services.map((s) => LABELS[s] ?? s).join(", ")}.
                </p>
                {flash.success ? (
                    <p className="text-sm">{flash.success}</p>
                ) : !open || slots.length === 0 ? (
                    <p className="text-sm">Registration is closed or full.</p>
                ) : (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/wellness/${token}`);
                        }}
                    >
                        <input
                            aria-label="First names"
                            placeholder="First names"
                            className={input}
                            value={form.data.first_names}
                            onChange={(e) =>
                                form.setData("first_names", e.target.value)
                            }
                        />
                        <input
                            aria-label="Surname"
                            placeholder="Surname"
                            className={input}
                            value={form.data.surname}
                            onChange={(e) =>
                                form.setData("surname", e.target.value)
                            }
                        />
                        <input
                            aria-label="SA ID number"
                            placeholder="SA ID number"
                            className={input}
                            value={form.data.id_number}
                            onChange={(e) =>
                                form.setData("id_number", e.target.value)
                            }
                        />
                        {!form.data.id_number && (
                            <input
                                aria-label="Date of birth"
                                type="date"
                                className={input}
                                value={form.data.date_of_birth}
                                onChange={(e) =>
                                    form.setData(
                                        "date_of_birth",
                                        e.target.value,
                                    )
                                }
                            />
                        )}
                        <input
                            aria-label="Cell number"
                            placeholder="Cell number"
                            className={input}
                            value={form.data.cell}
                            onChange={(e) =>
                                form.setData("cell", e.target.value)
                            }
                        />
                        <input
                            aria-label="Email"
                            placeholder="Email (optional)"
                            className={input}
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData("email", e.target.value)
                            }
                        />
                        <select
                            aria-label="Time"
                            className={input}
                            value={form.data.slot_at}
                            onChange={(e) =>
                                form.setData("slot_at", e.target.value)
                            }
                        >
                            {slots.map((s) => (
                                <option key={s.at} value={s.at}>
                                    {s.at.slice(11, 16)} ({s.free} left)
                                </option>
                            ))}
                        </select>
                        <label className="mb-3 flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={form.data.consent}
                                onChange={(e) =>
                                    form.setData("consent", e.target.checked)
                                }
                            />
                            I agree to the health checks and to {practice}{" "}
                            keeping my results in my patient record. My results
                            are private to me — my employer only receives
                            anonymous totals. Taking part is voluntary.
                        </label>
                        {Object.values(form.errors)[0] && (
                            <p
                                role="alert"
                                className="mb-2 text-xs text-status-danger"
                            >
                                {Object.values(form.errors)[0]}
                            </p>
                        )}
                        <Button type="submit" disabled={form.processing}>
                            Register
                        </Button>
                    </form>
                )}
            </Card>
        </div>
    );
}
