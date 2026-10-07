import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface Field {
    key: string;
    label: string;
    secret: boolean;
    saved: boolean;
    value: string;
}

interface ProviderRow {
    driver: string;
    label: string;
    channel: string;
    fields: Field[];
    senderLabel: string;
    sender: string | null;
    mode: string;
    enabled: boolean;
    isDefault: boolean;
    testRecipients: string;
    lastTest: string | null;
    lastTestOk: boolean | null;
}

interface TemplateRow {
    key: string;
    label: string;
    channel: string;
    placeholders: string[];
    feature: string | null;
    editableByProviders: boolean;
    languages: { language: string; subject: string | null; body: string }[];
}

interface PackageRow {
    id: number;
    name: string;
    sms: number;
    email: number;
    smsOverage: number;
    emailOverage: number;
    messageTypes: string[];
}

const input = "w-full rounded-md border border-line px-2 py-1.5 text-sm";

function ProviderCard({ p }: { p: ProviderRow }) {
    const [state, setState] = useState({
        mode: p.mode,
        enabled: p.enabled,
        is_default: p.isDefault,
        sender: p.sender ?? "",
        test_recipients: p.testRecipients,
        credentials: Object.fromEntries(
            p.fields.map((f) => [f.key, f.value]),
        ) as Record<string, string>,
    });

    return (
        <Card
            title={p.label}
            aside={
                <Badge
                    tone={
                        p.enabled
                            ? p.mode === "live"
                                ? "success"
                                : "warning"
                            : "neutral"
                    }
                >
                    {p.enabled ? p.mode : "off"}
                </Badge>
            }
        >
            <div className="grid grid-cols-2 gap-2 text-sm">
                {p.fields.map((f) => (
                    <label key={f.key} className="text-xs">
                        {f.label}
                        <input
                            type={f.secret ? "password" : "text"}
                            className={input}
                            placeholder={
                                f.secret && f.saved
                                    ? "Saved — leave blank to keep"
                                    : ""
                            }
                            value={state.credentials[f.key] ?? ""}
                            onChange={(e) =>
                                setState({
                                    ...state,
                                    credentials: {
                                        ...state.credentials,
                                        [f.key]: e.target.value,
                                    },
                                })
                            }
                        />
                    </label>
                ))}
                <label className="text-xs">
                    {p.senderLabel}
                    <input
                        className={input}
                        value={state.sender}
                        onChange={(e) =>
                            setState({ ...state, sender: e.target.value })
                        }
                    />
                </label>
                <label className="text-xs">
                    Test recipients (test mode only delivers to these)
                    <input
                        className={input}
                        placeholder={
                            p.channel === "sms"
                                ? "0821234567, 0839876543"
                                : "qa@example.com"
                        }
                        value={state.test_recipients}
                        onChange={(e) =>
                            setState({
                                ...state,
                                test_recipients: e.target.value,
                            })
                        }
                    />
                </label>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-3 text-sm">
                <select
                    aria-label="Mode"
                    className="rounded-md border border-line px-2 py-1"
                    value={state.mode}
                    onChange={(e) =>
                        setState({ ...state, mode: e.target.value })
                    }
                >
                    <option value="test">Test</option>
                    <option value="live">Live</option>
                </select>
                <label className="flex items-center gap-1.5">
                    <input
                        type="checkbox"
                        className="accent-teal"
                        checked={state.enabled}
                        onChange={(e) =>
                            setState({
                                ...state,
                                enabled: e.target.checked,
                                is_default: e.target.checked,
                            })
                        }
                    />{" "}
                    Active for {p.channel.toUpperCase()} — switches off the
                    other {p.channel === "sms" ? "SMS" : "email"} suppliers
                </label>
                <Button
                    size="sm"
                    onClick={() =>
                        router.put(
                            `/admin/messaging/providers/${p.driver}`,
                            state,
                            { preserveScroll: true },
                        )
                    }
                >
                    Save
                </Button>
                <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => {
                        const to = window.prompt(
                            p.channel === "sms"
                                ? "Send a test SMS to which number?"
                                : "Send a test email to which address?",
                        );
                        if (to)
                            router.post(
                                `/admin/messaging/providers/${p.driver}/test`,
                                { to },
                                { preserveScroll: true },
                            );
                    }}
                >
                    Send test
                </Button>
                {p.lastTest && (
                    <span
                        className={`text-xs ${p.lastTestOk ? "text-status-success" : "text-status-danger"}`}
                    >
                        Last test {p.lastTest}: {p.lastTestOk ? "OK" : "failed"}
                    </span>
                )}
            </div>
        </Card>
    );
}

function TemplateEditor({ t }: { t: TemplateRow }) {
    const [lang, setLang] = useState("en");
    const current = t.languages.find((l) => l.language === lang);
    const [subject, setSubject] = useState(current?.subject ?? "");
    const [body, setBody] = useState(current?.body ?? "");

    const pick = (l: string) => {
        const row = t.languages.find((x) => x.language === l);
        setLang(l);
        setSubject(row?.subject ?? "");
        setBody(row?.body ?? "");
    };

    return (
        <li className="rounded-lg border border-line p-3 text-sm">
            <div className="flex items-center gap-2">
                <b className="flex-1">{t.label}</b>
                <Badge>{t.channel}</Badge>
                {t.editableByProviders ? (
                    <Badge tone="teal">Providers can edit</Badge>
                ) : (
                    <Badge>Platform only</Badge>
                )}
            </div>
            <div className="mt-2 flex gap-1">
                {t.languages.map((l) => (
                    <button
                        key={l.language}
                        type="button"
                        onClick={() => pick(l.language)}
                        className={`rounded px-2 py-0.5 text-xs ${lang === l.language ? "bg-teal text-white" : "bg-paper"}`}
                    >
                        {l.language.toUpperCase()}
                    </button>
                ))}
            </div>
            {t.channel === "email" && (
                <input
                    aria-label="Subject"
                    className={`${input} mt-2`}
                    value={subject}
                    onChange={(e) => setSubject(e.target.value)}
                />
            )}
            <textarea
                aria-label="Message"
                className={`${input} mt-2 min-h-16`}
                value={body}
                onChange={(e) => setBody(e.target.value)}
            />
            <div className="mt-1 flex items-center gap-2">
                <span className="flex-1 text-xs text-muted">
                    Placeholders:{" "}
                    {t.placeholders.map((p) => `{{ ${p} }}`).join(" ")}
                </span>
                <Button
                    size="sm"
                    variant="secondary"
                    onClick={() =>
                        router.put(
                            "/admin/messaging/templates",
                            {
                                key: t.key,
                                channel: t.channel,
                                language: lang,
                                subject,
                                body,
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save {lang.toUpperCase()}
                </Button>
            </div>
        </li>
    );
}

function PackageRowEditor({
    p,
    types,
}: {
    p: PackageRow;
    types: Record<string, string>;
}) {
    const [s, setS] = useState({
        sms: p.sms,
        email: p.email,
        sms_overage: p.smsOverage,
        email_overage: p.emailOverage,
        message_types: p.messageTypes,
    });

    return (
        <tr className="border-t border-line-soft align-top">
            <td className="py-2 font-medium">{p.name}</td>
            <td className="py-2">
                <input
                    aria-label="SMS allowance"
                    type="number"
                    className="w-20 rounded border border-line px-1"
                    value={s.sms}
                    onChange={(e) =>
                        setS({ ...s, sms: Number(e.target.value) })
                    }
                />
            </td>
            <td className="py-2">
                <input
                    aria-label="Email allowance"
                    type="number"
                    className="w-20 rounded border border-line px-1"
                    value={s.email}
                    onChange={(e) =>
                        setS({ ...s, email: Number(e.target.value) })
                    }
                />
            </td>
            <td className="py-2 text-xs">
                SMS R
                <input
                    aria-label="SMS overage"
                    className="w-12 rounded border border-line px-1"
                    value={s.sms_overage}
                    onChange={(e) =>
                        setS({ ...s, sms_overage: Number(e.target.value) })
                    }
                />{" "}
                · Email R
                <input
                    aria-label="Email overage"
                    className="w-12 rounded border border-line px-1"
                    value={s.email_overage}
                    onChange={(e) =>
                        setS({ ...s, email_overage: Number(e.target.value) })
                    }
                />
            </td>
            <td className="py-2 text-xs">
                {Object.entries(types).map(([key, label]) => (
                    <label
                        key={key}
                        className="mr-2 inline-flex items-center gap-1"
                    >
                        <input
                            type="checkbox"
                            className="accent-teal"
                            checked={s.message_types.includes(key)}
                            onChange={(e) =>
                                setS({
                                    ...s,
                                    message_types: e.target.checked
                                        ? [...s.message_types, key]
                                        : s.message_types.filter(
                                              (k) => k !== key,
                                          ),
                                })
                            }
                        />
                        {label}
                    </label>
                ))}
            </td>
            <td className="py-2">
                <Button
                    size="sm"
                    onClick={() =>
                        router.put(`/admin/messaging/packages/${p.id}`, s, {
                            preserveScroll: true,
                        })
                    }
                >
                    Save
                </Button>
            </td>
        </tr>
    );
}

export default function Messaging({
    providers,
    templates,
    packages,
    messageTypes,
}: {
    providers: ProviderRow[];
    templates: TemplateRow[];
    packages: PackageRow[];
    messageTypes: Record<string, string>;
}) {
    const [tab, setTab] = useState<"suppliers" | "packages" | "templates">(
        "suppliers",
    );

    return (
        <AdminShell active="Messaging">
            <Head title="Messaging" />
            <h1 className="mb-1 text-2xl font-semibold">SMS and email</h1>
            <p className="mb-4 text-sm text-muted">
                All messages leave from the platform's accounts. Providers only
                set their email from-name and reply-to, and edit wording their
                package includes.
            </p>
            <Flash />
            <div className="mb-4 flex gap-1">
                {(["suppliers", "packages", "templates"] as const).map((t) => (
                    <Button
                        key={t}
                        size="sm"
                        variant={tab === t ? "primary" : "secondary"}
                        onClick={() => setTab(t)}
                    >
                        {t === "suppliers"
                            ? "Suppliers"
                            : t === "packages"
                              ? "Package allowances"
                              : "Default wording"}
                    </Button>
                ))}
            </div>
            {tab === "suppliers" && (
                <div className="grid grid-cols-2 gap-4">
                    {providers.map((p) => (
                        <ProviderCard key={p.driver} p={p} />
                    ))}
                </div>
            )}
            {tab === "packages" && (
                <Card>
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs text-muted">
                            <tr>
                                <th scope="col" className="py-2 font-medium">
                                    Package
                                </th>
                                <th scope="col" className="py-2 font-medium">
                                    SMS / month
                                </th>
                                <th scope="col" className="py-2 font-medium">
                                    Emails / month
                                </th>
                                <th scope="col" className="py-2 font-medium">
                                    Over allowance (per message)
                                </th>
                                <th scope="col" className="py-2 font-medium">
                                    Messages included
                                </th>
                                <th scope="col" />
                            </tr>
                        </thead>
                        <tbody>
                            {packages.map((p) => (
                                <PackageRowEditor
                                    key={p.id}
                                    p={p}
                                    types={messageTypes}
                                />
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}
            {tab === "templates" && (
                <ul className="flex flex-col gap-3">
                    {templates.map((t) => (
                        <TemplateEditor key={`${t.key}-${t.channel}`} t={t} />
                    ))}
                </ul>
            )}
        </AdminShell>
    );
}
