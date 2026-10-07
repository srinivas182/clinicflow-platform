import { Head, Link, router, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import { rand } from "@/lib/money";

interface Props {
    accounts: {
        id: number;
        name: string;
        contact: string | null;
        email: string | null;
        rate: number;
        active: boolean;
    }[];
    events: {
        id: number;
        company: string;
        title: string;
        location: string;
        starts: string;
        ends: string;
        status: string;
        link: string;
        registered: number;
        screened: number;
        invoice: {
            id: number;
            number: string;
            total: number;
            paid: boolean;
        } | null;
        reportSent: boolean;
    }[];
    services: string[];
}

export default function WellnessIndex({ accounts, events, services }: Props) {
    const acc = useForm({
        name: "",
        contact_name: "",
        contact_email: "",
        rate: "",
    });
    const ev = useForm({
        corporate_account_id: "",
        title: "Wellness day",
        location: "",
        starts_at: "",
        ends_at: "",
        slot_minutes: 15,
        per_slot: 2,
        services: ["bp", "glucose", "cholesterol", "bmi"] as string[],
    });
    const input = "rounded-md border border-line px-2 py-1 text-sm";

    return (
        <AppShell active="Wellness">
            <Head title="Corporate wellness" />
            <h1 className="mb-1 text-2xl font-semibold">Corporate wellness</h1>
            <p className="mb-5 text-sm text-muted">
                Employers pay; employees register themselves and their results
                stay private to them. Employers only ever receive anonymised
                totals.
            </p>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                <Card title="Corporate accounts">
                    {accounts.map((a) => (
                        <p key={a.id} className="text-sm">
                            {a.name} · {a.contact} · {rand(a.rate, 2)}/employee{" "}
                            {!a.active && <Badge>inactive</Badge>}
                        </p>
                    ))}
                    <div className="mt-3 grid grid-cols-2 gap-2">
                        <input
                            aria-label="Company"
                            placeholder="Company"
                            className={input}
                            value={acc.data.name}
                            onChange={(e) =>
                                acc.setData("name", e.target.value)
                            }
                        />
                        <input
                            aria-label="Contact"
                            placeholder="Contact person"
                            className={input}
                            value={acc.data.contact_name}
                            onChange={(e) =>
                                acc.setData("contact_name", e.target.value)
                            }
                        />
                        <input
                            aria-label="Contact email"
                            placeholder="Contact email"
                            className={input}
                            value={acc.data.contact_email}
                            onChange={(e) =>
                                acc.setData("contact_email", e.target.value)
                            }
                        />
                        <input
                            aria-label="Rate per employee"
                            placeholder="Rate per employee (R)"
                            className={input}
                            value={acc.data.rate}
                            onChange={(e) =>
                                acc.setData("rate", e.target.value)
                            }
                        />
                    </div>
                    <Button
                        className="mt-2"
                        size="sm"
                        onClick={() =>
                            acc.post("/corporate-wellness/accounts", {
                                preserveScroll: true,
                                onSuccess: () => acc.reset(),
                            })
                        }
                    >
                        Add account
                    </Button>
                </Card>
                <Card title="New wellness day">
                    <div className="grid grid-cols-2 gap-2">
                        <select
                            aria-label="Company"
                            className={input}
                            value={ev.data.corporate_account_id}
                            onChange={(e) =>
                                ev.setData(
                                    "corporate_account_id",
                                    e.target.value,
                                )
                            }
                        >
                            <option value="">Company…</option>
                            {accounts
                                .filter((a) => a.active)
                                .map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                        </select>
                        <input
                            aria-label="Title"
                            className={input}
                            value={ev.data.title}
                            onChange={(e) =>
                                ev.setData("title", e.target.value)
                            }
                        />
                        <input
                            aria-label="Location"
                            placeholder="Location"
                            className={input}
                            value={ev.data.location}
                            onChange={(e) =>
                                ev.setData("location", e.target.value)
                            }
                        />
                        <input
                            aria-label="Starts"
                            type="datetime-local"
                            className={input}
                            value={ev.data.starts_at}
                            onChange={(e) =>
                                ev.setData("starts_at", e.target.value)
                            }
                        />
                        <input
                            aria-label="Ends"
                            type="datetime-local"
                            className={input}
                            value={ev.data.ends_at}
                            onChange={(e) =>
                                ev.setData("ends_at", e.target.value)
                            }
                        />
                        <input
                            aria-label="People per slot"
                            className={input}
                            value={ev.data.per_slot}
                            onChange={(e) =>
                                ev.setData("per_slot", Number(e.target.value))
                            }
                        />
                    </div>
                    <div className="mt-2 flex flex-wrap gap-3 text-sm">
                        {services.map((sv) => (
                            <label key={sv} className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    className="accent-teal"
                                    checked={ev.data.services.includes(sv)}
                                    onChange={(e) =>
                                        ev.setData(
                                            "services",
                                            e.target.checked
                                                ? [...ev.data.services, sv]
                                                : ev.data.services.filter(
                                                      (x) => x !== sv,
                                                  ),
                                        )
                                    }
                                />
                                {sv}
                            </label>
                        ))}
                    </div>
                    {Object.values(ev.errors)[0] && (
                        <p
                            role="alert"
                            className="mt-1 text-xs text-status-danger"
                        >
                            {Object.values(ev.errors)[0]}
                        </p>
                    )}
                    <Button
                        className="mt-2"
                        size="sm"
                        onClick={() =>
                            ev.post("/corporate-wellness/events", {
                                preserveScroll: true,
                            })
                        }
                    >
                        Create wellness day
                    </Button>
                </Card>
            </div>
            <Card title="Wellness days" className="mt-4">
                {events.map((e) => (
                    <div
                        key={e.id}
                        className="flex flex-wrap items-center gap-2 border-t border-line-soft py-2 text-sm first:border-0"
                    >
                        <span className="flex-1">
                            <b>{e.company}</b> · {e.title} ·{" "}
                            {e.starts.slice(0, 16)} · {e.location} ·{" "}
                            {e.registered} registered · {e.screened} screened
                            <span className="block font-mono text-xs text-muted">
                                {e.link}
                            </span>
                        </span>
                        <span className="flex items-center gap-2 text-xs">
                            {e.invoice ? (
                                <>
                                    <a
                                        className="text-teal-deep"
                                        href={`/corporate-wellness/events/${e.id}/employer/invoice.pdf`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {e.invoice.number} ·{" "}
                                        {rand(e.invoice.total, 2)}
                                    </a>
                                    {e.invoice.paid ? (
                                        <Badge tone="success">paid</Badge>
                                    ) : (
                                        <button
                                            className="text-teal-deep"
                                            onClick={() => {
                                                const reference =
                                                    window.prompt(
                                                        "Payment reference",
                                                    );
                                                if (reference)
                                                    router.post(
                                                        `/corporate-wellness/invoices/${e.invoice?.id}/paid`,
                                                        { reference },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                            }}
                                        >
                                            mark paid
                                        </button>
                                    )}
                                </>
                            ) : (
                                e.screened > 0 && (
                                    <button
                                        className="text-teal-deep"
                                        onClick={() =>
                                            router.post(
                                                `/corporate-wellness/events/${e.id}/employer/invoice`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Invoice employer
                                    </button>
                                )
                            )}
                            <a
                                className="text-teal-deep"
                                href={`/corporate-wellness/events/${e.id}/employer/report.pdf`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Summary
                            </a>
                            <button
                                className="text-teal-deep"
                                onClick={() =>
                                    router.post(
                                        `/corporate-wellness/events/${e.id}/employer/send`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {e.reportSent
                                    ? "Send again"
                                    : "Send to employer"}
                            </button>
                        </span>
                        <Link href={`/corporate-wellness/events/${e.id}`}>
                            <Button size="sm" variant="secondary">
                                Screening
                            </Button>
                        </Link>
                    </div>
                ))}
            </Card>
        </AppShell>
    );
}
