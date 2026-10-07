import { Head, router, useForm, usePage } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Props {
    enabled: boolean;
    scopes: Record<string, string>;
    keys: {
        id: number;
        name: string;
        prefix: string;
        scopes: string[];
        ips: string[];
        expires: string | null;
        lastUsed: string | null;
        revoked: boolean;
    }[];
    requests: {
        key: string;
        method: string;
        path: string;
        status: number;
        ip: string | null;
        at: string;
    }[];
    docs: string;
    base: string;
    events: Record<string, string>;
    endpoints: {
        id: number;
        url: string;
        events: string[];
        active: boolean;
        failures: number;
        disabledAt: string | null;
    }[];
    deliveries: {
        id: number;
        event: string;
        status: string;
        attempts: number;
        response: number | null;
        error: string | null;
        at: string;
        url: string;
    }[];
    lab?: {
        outgoingKey: number | null;
        systems: { id: number; name: string; orders: boolean }[];
        maps: {
            id: number;
            external_code: string;
            test_code: string;
            system: string;
        }[];
    };
}

export default function ApiSettings({
    enabled,
    scopes,
    keys,
    requests,
    docs,
    base,
    events,
    endpoints,
    deliveries,
    lab,
}: Props) {
    const { flash } = usePage<{ flash: { newApiKey?: string | null } }>().props;
    const form = useForm({
        name: "",
        scopes: [] as string[],
        allowed_ips: "",
        expires_at: "",
    });
    const hook = useForm({ url: "", events: [] as string[] });
    const input = "rounded-md border border-line px-2 py-1 text-sm";

    return (
        <AppShell active="Settings">
            <Head title="API" />
            <h1 className="mb-1 text-2xl font-semibold">API</h1>
            <p className="mb-5 text-sm text-muted">
                Connect other software to this practice. Keys only reach this
                practice&apos;s data, only with the permissions you choose, and
                never clinical information. Base address:{" "}
                <span className="font-mono">{base}</span> ·{" "}
                <a
                    className="text-teal-deep"
                    href={docs}
                    target="_blank"
                    rel="noreferrer"
                >
                    API description (OpenAPI)
                </a>
            </p>
            <Flash />
            {!enabled && (
                <p className="mb-4 rounded-md bg-status-warning/10 p-3 text-sm">
                    API access is not included in your package. Keys can be
                    created, but requests are refused until it is enabled.
                </p>
            )}
            {flash.newApiKey && (
                <Card
                    title="Copy this now — it will not be shown again"
                    className="mb-4"
                >
                    <p className="mb-2 break-all font-mono text-sm">
                        {flash.newApiKey}
                    </p>
                    <p className="text-xs text-muted">
                        It will not be shown again. Store it like a password;
                        revoke it here if it is ever exposed.
                    </p>
                </Card>
            )}
            <Card title="New key" className="mb-4">
                <div className="grid grid-cols-3 gap-2">
                    <input
                        aria-label="Key name"
                        placeholder="Name, e.g. Website booking"
                        className={input}
                        value={form.data.name}
                        onChange={(e) => form.setData("name", e.target.value)}
                    />
                    <input
                        aria-label="Allowed IPs"
                        placeholder="Allowed IPs (optional, comma separated)"
                        className={input}
                        value={form.data.allowed_ips}
                        onChange={(e) =>
                            form.setData("allowed_ips", e.target.value)
                        }
                    />
                    <input
                        aria-label="Expires"
                        type="date"
                        className={input}
                        value={form.data.expires_at}
                        onChange={(e) =>
                            form.setData("expires_at", e.target.value)
                        }
                    />
                </div>
                <div className="mt-2 flex flex-col gap-1 text-sm">
                    {Object.entries(scopes).map(([scope, label]) => (
                        <label key={scope} className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="accent-teal"
                                checked={form.data.scopes.includes(scope)}
                                onChange={(e) =>
                                    form.setData(
                                        "scopes",
                                        e.target.checked
                                            ? [...form.data.scopes, scope]
                                            : form.data.scopes.filter(
                                                  (x) => x !== scope,
                                              ),
                                    )
                                }
                            />
                            <span className="font-mono text-xs">{scope}</span>{" "}
                            {label}
                        </label>
                    ))}
                </div>
                {Object.values(form.errors)[0] && (
                    <p role="alert" className="mt-2 text-xs text-status-danger">
                        {Object.values(form.errors)[0]}
                    </p>
                )}
                <Button
                    className="mt-2"
                    size="sm"
                    onClick={() =>
                        form.post("/settings/api/keys", {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        })
                    }
                >
                    Create key
                </Button>
            </Card>
            <Card title="Keys" className="mb-4">
                {keys.length === 0 && (
                    <p className="text-sm text-muted">No keys yet.</p>
                )}
                {keys.map((k) => (
                    <div
                        key={k.id}
                        className="flex flex-wrap items-center gap-2 border-t border-line-soft py-2 text-sm first:border-0"
                    >
                        <span className="flex-1">
                            <b>{k.name}</b>{" "}
                            <span className="font-mono text-xs">
                                {k.prefix}…
                            </span>{" "}
                            · {k.scopes.join(", ")}
                            <span className="block text-xs text-muted">
                                {k.ips.length > 0
                                    ? `IPs: ${k.ips.join(", ")} · `
                                    : ""}
                                {k.expires
                                    ? `expires ${k.expires.slice(0, 10)} · `
                                    : ""}
                                last used{" "}
                                {k.lastUsed ? k.lastUsed.slice(0, 16) : "never"}
                            </span>
                        </span>
                        {k.revoked ? (
                            <Badge>revoked</Badge>
                        ) : (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    window.confirm(
                                        "Revoke this key? Software using it stops working.",
                                    ) &&
                                    router.post(
                                        `/settings/api/keys/${k.id}/revoke`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Revoke
                            </Button>
                        )}
                    </div>
                ))}
            </Card>
            <Card title="Recent requests">
                <table className="w-full text-xs">
                    <tbody>
                        {requests.map((r, i) => (
                            <tr key={i} className="border-t border-line-soft">
                                <td className="py-1">{r.at.slice(0, 19)}</td>
                                <td>{r.key}</td>
                                <td className="font-mono">
                                    {r.method} {r.path}
                                </td>
                                <td>
                                    <Badge
                                        tone={
                                            r.status < 300
                                                ? "success"
                                                : r.status === 429
                                                  ? "warning"
                                                  : "danger"
                                        }
                                    >
                                        {r.status}
                                    </Badge>
                                </td>
                                <td>{r.ip}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
            <Card title="Webhooks" className="mt-4">
                <p className="mb-2 text-xs text-muted">
                    Clinic Flow sends signed messages (IDs and status only) to
                    your https:// address. Header X-ClinicFlow-Signature:
                    t=time,v1=HMAC-SHA256 of &quot;time.body&quot;. Failed
                    deliveries are retried for about a day; an address that
                    fails 5 deliveries in a row is switched off.
                </p>
                <div className="flex gap-2">
                    <input
                        aria-label="Webhook URL"
                        placeholder="https://your-system.example/webhooks/clinicflow"
                        className="flex-1 rounded-md border border-line px-2 py-1 text-sm"
                        value={hook.data.url}
                        onChange={(e) => hook.setData("url", e.target.value)}
                    />
                    <Button
                        size="sm"
                        onClick={() =>
                            hook.post("/settings/api/webhooks/add", {
                                preserveScroll: true,
                                onSuccess: () => hook.reset(),
                            })
                        }
                    >
                        Add webhook
                    </Button>
                </div>
                <div className="mt-2 flex flex-wrap gap-3 text-sm">
                    {Object.entries(events).map(([ev, label]) => (
                        <label key={ev} className="flex items-center gap-1">
                            <input
                                type="checkbox"
                                className="accent-teal"
                                checked={hook.data.events.includes(ev)}
                                onChange={(e) =>
                                    hook.setData(
                                        "events",
                                        e.target.checked
                                            ? [...hook.data.events, ev]
                                            : hook.data.events.filter(
                                                  (x) => x !== ev,
                                              ),
                                    )
                                }
                            />
                            {label}
                        </label>
                    ))}
                </div>
                {Object.values(hook.errors)[0] && (
                    <p role="alert" className="mt-1 text-xs text-status-danger">
                        {Object.values(hook.errors)[0]}
                    </p>
                )}
                {endpoints.map((e) => (
                    <div
                        key={e.id}
                        className="mt-2 flex flex-wrap items-center gap-2 border-t border-line-soft pt-2 text-sm"
                    >
                        <span className="flex-1">
                            <span className="font-mono text-xs">{e.url}</span>
                            <span className="block text-xs text-muted">
                                {e.events.join(", ")}
                                {e.failures > 0
                                    ? ` · ${e.failures} failed in a row`
                                    : ""}
                            </span>
                        </span>
                        <Badge tone={e.active ? "success" : "neutral"}>
                            {e.active ? "on" : "off"}
                        </Badge>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.post(
                                    `/settings/api/webhooks/${e.active ? "off" : "on"}`,
                                    { endpoint_id: e.id },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {e.active ? "Switch off" : "Switch on"}
                        </Button>
                        {e.active && (
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() =>
                                    router.post(
                                        "/settings/api/webhooks/test",
                                        { endpoint_id: e.id },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Send test
                            </Button>
                        )}
                    </div>
                ))}
                {deliveries.length > 0 && (
                    <table className="mt-3 w-full text-xs">
                        <tbody>
                            {deliveries.map((d) => (
                                <tr
                                    key={d.id}
                                    className="border-t border-line-soft"
                                >
                                    <td className="py-1">
                                        {d.at.slice(0, 19)}
                                    </td>
                                    <td>{d.event}</td>
                                    <td>
                                        <Badge
                                            tone={
                                                d.status === "delivered"
                                                    ? "success"
                                                    : d.status === "pending"
                                                      ? "warning"
                                                      : "danger"
                                            }
                                        >
                                            {d.status}
                                        </Badge>
                                    </td>
                                    <td>
                                        {d.attempts} tries{" "}
                                        {d.response ? `· ${d.response}` : ""}
                                    </td>
                                    <td className="text-muted">{d.error}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>
            {lab && lab.systems.length > 0 && (
                <Card title="Lab systems" className="mt-4">
                    <label className="mb-3 flex items-center gap-2 text-sm">
                        Send new lab orders to:
                        <select
                            aria-label="Lab system for orders"
                            className="rounded-md border border-line px-2 py-1 text-sm"
                            value={lab.outgoingKey ?? ""}
                            onChange={(e) =>
                                router.post(
                                    "/settings/api/lab/outgoing",
                                    { key_id: e.target.value || null },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <option value="">
                                No lab system (orders stay in Clinic Flow)
                            </option>
                            {lab.systems
                                .filter((x) => x.orders)
                                .map((x) => (
                                    <option key={x.id} value={x.id}>
                                        {x.name}
                                    </option>
                                ))}
                        </select>
                    </label>
                    <p className="mb-1 text-sm font-medium">
                        Test-code mapping
                    </p>
                    <p className="mb-2 text-xs text-muted">
                        Match each lab system&apos;s own test codes to your
                        catalogue once; incoming results and outgoing orders are
                        then translated automatically.
                    </p>
                    {lab.maps.map((m) => (
                        <p
                            key={m.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            {m.system}:{" "}
                            <span className="font-mono">{m.external_code}</span>{" "}
                            → <span className="font-mono">{m.test_code}</span>
                            <button
                                className="text-xs text-status-danger"
                                onClick={() =>
                                    router.post(
                                        "/settings/api/lab/unmap",
                                        { map_id: m.id },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                remove
                            </button>
                        </p>
                    ))}
                    <Button
                        className="mt-2"
                        size="sm"
                        variant="secondary"
                        onClick={() => {
                            const names = lab.systems
                                .map((x, i) => `${i + 1}. ${x.name}`)
                                .join("\n");
                            const pick =
                                Number(
                                    window.prompt(
                                        `Which lab system?\n${names}`,
                                        "1",
                                    ),
                                ) - 1;
                            const system = lab.systems[pick];
                            const external = window.prompt(
                                "Lab system test code",
                            );
                            const code = window.prompt(
                                "Your catalogue test code",
                            );
                            if (system && external && code)
                                router.post(
                                    "/settings/api/lab/map",
                                    {
                                        key_id: system.id,
                                        external_code: external,
                                        test_code: code,
                                    },
                                    { preserveScroll: true },
                                );
                        }}
                    >
                        Add mapping
                    </Button>
                </Card>
            )}
        </AppShell>
    );
}
