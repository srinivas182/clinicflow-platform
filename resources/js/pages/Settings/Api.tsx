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
}

export default function ApiSettings({
    enabled,
    scopes,
    keys,
    requests,
    docs,
    base,
}: Props) {
    const { flash } = usePage<{ flash: { newApiKey?: string | null } }>().props;
    const form = useForm({
        name: "",
        scopes: [] as string[],
        allowed_ips: "",
        expires_at: "",
    });
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
                <Card title="Your new API key — copy it now" className="mb-4">
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
                    <p className="mt-2 text-xs text-status-danger">
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
                        className="flex flex-wrap items-center gap-2 border-t border-[#EBF0EE] py-2 text-sm first:border-0"
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
                            <tr key={i} className="border-t border-[#EBF0EE]">
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
        </AppShell>
    );
}
