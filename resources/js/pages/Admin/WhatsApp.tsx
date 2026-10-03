import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface Driver {
    driver: string;
    label: string;
    fields: Record<string, string>;
    senderLabel: string;
    enabled: boolean;
    sender: string | null;
    configured: string[];
}
interface Props {
    drivers: Driver[];
    templates: {
        id: number;
        message_key: string;
        template_name: string;
        language: string;
        category: string;
        status: string;
        rejected_reason: string | null;
    }[];
    messages: { key: string; label: string; category: string }[];
    prices: Record<string, number>;
}
const input = "rounded-md border border-line px-2 py-1 text-sm";

function DriverCard({ d }: { d: Driver }) {
    const [creds, setCreds] = useState<Record<string, string>>({});
    const [sender, setSender] = useState(d.sender ?? "");
    return (
        <Card
            title={d.label}
            aside={
                <Badge tone={d.enabled ? "success" : "neutral"}>
                    {d.enabled ? "active" : "off"}
                </Badge>
            }
            className="mb-3"
        >
            <div className="grid grid-cols-3 gap-2">
                {Object.entries(d.fields).map(([k, label]) => (
                    <input
                        key={k}
                        aria-label={label}
                        type="password"
                        placeholder={
                            d.configured.includes(k)
                                ? `${label} (saved)`
                                : label
                        }
                        className={input}
                        value={creds[k] ?? ""}
                        onChange={(e) =>
                            setCreds({ ...creds, [k]: e.target.value })
                        }
                    />
                ))}
                <input
                    aria-label={d.senderLabel}
                    placeholder={d.senderLabel}
                    className={input}
                    value={sender}
                    onChange={(e) => setSender(e.target.value)}
                />
            </div>
            <div className="mt-2 flex gap-2">
                <Button
                    size="sm"
                    onClick={() =>
                        router.put(
                            `/admin/whatsapp/providers/${d.driver}`,
                            { enabled: true, sender, credentials: creds },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save and make active
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        router.put(
                            `/admin/whatsapp/providers/${d.driver}`,
                            { enabled: false, sender, credentials: creds },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save, keep off
                </Button>
            </div>
        </Card>
    );
}

export default function AdminWhatsApp({
    drivers,
    templates,
    messages,
    prices,
}: Props) {
    const [p, setP] = useState(prices);
    const ask = (l: string, d = "") => window.prompt(l, d) ?? "";
    return (
        <AdminShell active="WhatsApp">
            <Head title="WhatsApp" />
            <h1 className="mb-1 text-2xl font-semibold">WhatsApp</h1>
            <p className="mb-5 text-sm text-muted">
                One supplier is active. Practices switch on the WhatsApp add-on;
                each message is paid from their wallet. Business-initiated
                messages need approved templates.
            </p>
            <Flash />
            {drivers.map((d) => (
                <DriverCard key={d.driver} d={d} />
            ))}
            <Card
                title="Prices per message (from the practice wallet)"
                className="mb-3"
            >
                <div className="flex flex-wrap items-center gap-3 text-sm">
                    {Object.keys(p).map((k) => (
                        <label key={k} className="flex items-center gap-1">
                            {k} R
                            <input
                                aria-label={`${k} price`}
                                className={`${input} w-20`}
                                value={String(p[k])}
                                onChange={(e) =>
                                    setP({ ...p, [k]: Number(e.target.value) })
                                }
                            />
                        </label>
                    ))}
                    <Button
                        size="sm"
                        onClick={() =>
                            router.put("/admin/whatsapp/prices", p, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Save prices
                    </Button>
                </div>
            </Card>
            <Card
                title="Templates"
                aside={
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() =>
                            router.post(
                                "/admin/whatsapp/sync",
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Sync status from Meta
                    </Button>
                }
            >
                <table className="w-full text-sm">
                    <tbody>
                        {messages.map((m) => {
                            const t = templates.find(
                                (x) => x.message_key === m.key,
                            );
                            return (
                                <tr
                                    key={m.key}
                                    className="border-t border-[#EBF0EE]"
                                >
                                    <td className="py-1.5">{m.label}</td>
                                    <td className="text-xs text-muted">
                                        {t?.template_name ?? "—"}
                                    </td>
                                    <td>
                                        <Badge>
                                            {t?.category ?? m.category}
                                        </Badge>
                                    </td>
                                    <td>
                                        <Badge
                                            tone={
                                                t?.status === "approved"
                                                    ? "success"
                                                    : t?.status === "rejected"
                                                      ? "danger"
                                                      : "neutral"
                                            }
                                        >
                                            {t?.status ?? "not submitted"}
                                        </Badge>
                                    </td>
                                    <td>
                                        <button
                                            className="text-xs text-teal-deep"
                                            onClick={() =>
                                                router.post(
                                                    "/admin/whatsapp/templates",
                                                    {
                                                        message_key: m.key,
                                                        template_name: ask(
                                                            "Template name or ID at the supplier",
                                                            t?.template_name ??
                                                                m.key.replace(
                                                                    ".",
                                                                    "_",
                                                                ),
                                                        ),
                                                        language: ask(
                                                            "Language code",
                                                            t?.language ?? "en",
                                                        ),
                                                        category:
                                                            t?.category ??
                                                            m.category,
                                                        status: ask(
                                                            "Status (pending / approved / rejected)",
                                                            t?.status ??
                                                                "pending",
                                                        ),
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            edit
                                        </button>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </Card>
        </AdminShell>
    );
}
