import { Head, router, useForm, usePage } from "@inertiajs/react";
import { useEffect } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface Target {
    id: number;
    name: string;
    driver: "local" | "s3";
    provider: string | null;
    bucket: string | null;
    region: string | null;
    endpoint: string | null;
    pathStyle: boolean;
    encrypt: boolean;
    root: string | null;
    active: boolean;
    verified: boolean;
    lastError: string | null;
    hasKeys: boolean;
    copy: {
        status: "queued" | "running" | "done" | "failed" | null;
        /** Copied from where the files are now (an older copy does not count). */
        current: boolean;
        at: string | null;
        counts: { copied: number; skipped: number; failed: number } | null;
    };
}

/** What the copy of existing files into a target has done so far. */
function copyText(t: Target): string {
    const c = t.copy.counts;
    const n = c ? `${c.copied} copied, ${c.skipped} already there` : "";
    switch (t.copy.status) {
        case "queued":
            return "Copy of existing files is waiting to start…";
        case "running":
            return `Copying existing files… ${n}`;
        case "done":
            return t.copy.current
                ? `Existing files copied and checked: ${n}.`
                : "Files were copied from a different storage. Copy again before activating.";
        case "failed":
            return `Copy finished with ${c?.failed ?? 0} failed file(s): ${n}. Check the details, then copy again.`;
        default:
            return "Existing files not copied yet.";
    }
}
interface Props {
    targets: Target[];
    providers: Record<string, string>;
    current: string;
}

/** Endpoint guidance per provider (the super admin confirms the exact value from the provider's console). */
const HINTS: Record<string, string> = {
    aws: "Leave the endpoint empty. Region e.g. af-south-1 (Cape Town).",
    gcs: "Endpoint https://storage.googleapis.com, region e.g. africa-south1. Use HMAC keys.",
    minio: 'Your MinIO address, e.g. https://files.example.co.za. Tick "path-style".',
    r2: "Endpoint https://<account-id>.r2.cloudflarestorage.com, region auto.",
    wasabi: "Endpoint https://s3.<region>.wasabisys.com.",
    digitalocean: "Endpoint https://<region>.digitaloceanspaces.com.",
    backblaze: "Endpoint https://s3.<region>.backblazeb2.com.",
    other: "The provider’s S3 endpoint (https only).",
};
const empty = {
    id: "",
    name: "",
    driver: "s3" as "local" | "s3",
    provider: "aws",
    bucket: "",
    region: "af-south-1",
    endpoint: "",
    path_style: false,
    encrypt: true,
    root: "",
    key: "",
    secret: "",
};

export default function AdminStorage({ targets, providers, current }: Props) {
    const form = useForm({ ...empty });
    const pageErrors = usePage<{ errors: Record<string, string> }>().props
        .errors;
    const editingActive = targets.some(
        (t) => String(t.id) === form.data.id && t.active,
    );
    const copying = targets.some(
        (t) => t.copy.status === "queued" || t.copy.status === "running",
    );
    // While files are being copied, refresh the progress every few seconds.
    useEffect(() => {
        if (!copying) return;
        const timer = window.setInterval(
            () => router.reload({ only: ["targets"] }),
            4000,
        );
        return () => window.clearInterval(timer);
    }, [copying]);
    const input = "w-full rounded-md border border-line px-2 py-1 text-sm";
    const edit = (t: Target) =>
        form.setData({
            id: String(t.id),
            name: t.name,
            driver: t.driver,
            provider: t.provider ?? "aws",
            bucket: t.bucket ?? "",
            region: t.region ?? "",
            endpoint: t.endpoint ?? "",
            path_style: t.pathStyle,
            encrypt: t.encrypt,
            root: t.root ?? "",
            key: "",
            secret: "",
        });

    return (
        <AdminShell active="Storage">
            <Head title="File storage" />
            <h1 className="mb-1 text-2xl font-semibold">File storage</h1>
            <p className="mb-5 text-sm text-muted">
                Where uploads, documents, lab reports and website images are
                kept, for every practice (each in its own folder). Currently:{" "}
                <b>
                    {current === "s3"
                        ? "S3-compatible storage"
                        : "this server’s disk"}
                </b>
                . Use S3 or S3-compatible storage before running more than one
                server.
            </p>
            <Flash />
            <div className="grid gap-4 md:grid-cols-2">
                <Card title={form.data.id ? "Edit storage" : "Add storage"}>
                    <div className="grid grid-cols-2 gap-2 text-sm">
                        <input
                            aria-label="Name"
                            placeholder="Name, e.g. AWS Cape Town"
                            className={`${input} col-span-2`}
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData("name", e.target.value)
                            }
                        />
                        <select
                            aria-label="Type"
                            className={input}
                            value={form.data.driver}
                            onChange={(e) =>
                                form.setData(
                                    "driver",
                                    e.target.value as "local" | "s3",
                                )
                            }
                        >
                            <option value="s3">S3 or S3-compatible</option>
                            <option value="local">This server’s disk</option>
                        </select>
                        {form.data.driver === "s3" && (
                            <select
                                aria-label="Provider"
                                className={input}
                                value={form.data.provider}
                                onChange={(e) =>
                                    form.setData("provider", e.target.value)
                                }
                            >
                                {Object.entries(providers).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        )}
                        {form.data.driver === "s3" && (
                            <>
                                <p className="col-span-2 text-xs text-muted">
                                    {HINTS[form.data.provider] ?? ""}
                                </p>
                                <input
                                    aria-label="Bucket"
                                    placeholder="Bucket"
                                    className={input}
                                    value={form.data.bucket}
                                    onChange={(e) =>
                                        form.setData("bucket", e.target.value)
                                    }
                                />
                                <input
                                    aria-label="Region"
                                    placeholder="Region"
                                    className={input}
                                    value={form.data.region}
                                    onChange={(e) =>
                                        form.setData("region", e.target.value)
                                    }
                                />
                                <input
                                    aria-label="Endpoint"
                                    placeholder="Endpoint (https://…), empty for AWS"
                                    className={`${input} col-span-2`}
                                    value={form.data.endpoint}
                                    onChange={(e) =>
                                        form.setData("endpoint", e.target.value)
                                    }
                                />
                                <input
                                    aria-label="Access key"
                                    placeholder={
                                        form.data.id
                                            ? "Access key (leave empty to keep)"
                                            : "Access key"
                                    }
                                    className={input}
                                    value={form.data.key}
                                    onChange={(e) =>
                                        form.setData("key", e.target.value)
                                    }
                                />
                                <input
                                    aria-label="Secret"
                                    type="password"
                                    placeholder={
                                        form.data.id
                                            ? "Secret (leave empty to keep)"
                                            : "Secret"
                                    }
                                    className={input}
                                    value={form.data.secret}
                                    onChange={(e) =>
                                        form.setData("secret", e.target.value)
                                    }
                                />
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        className="accent-teal"
                                        checked={form.data.path_style}
                                        onChange={(e) =>
                                            form.setData(
                                                "path_style",
                                                e.target.checked,
                                            )
                                        }
                                    />{" "}
                                    Path-style addresses
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        className="accent-teal"
                                        checked={form.data.encrypt}
                                        onChange={(e) =>
                                            form.setData(
                                                "encrypt",
                                                e.target.checked,
                                            )
                                        }
                                    />{" "}
                                    Encrypt files at rest
                                </label>
                            </>
                        )}
                        <input
                            aria-label="Folder"
                            placeholder={
                                form.data.driver === "s3"
                                    ? "Top folder (default clinicflow)"
                                    : "Folder on this server (default storage/app/private)"
                            }
                            className={`${input} col-span-2`}
                            value={form.data.root}
                            onChange={(e) =>
                                form.setData("root", e.target.value)
                            }
                        />
                    </div>
                    {editingActive && (
                        <p className="mt-2 text-xs text-muted">
                            This storage is in use: only its name and keys can
                            change. New keys are tested before they are used. To
                            move files elsewhere, add a new storage.
                        </p>
                    )}
                    {Object.values(form.errors)[0] && (
                        <p
                            role="alert"
                            className="mt-2 text-xs text-status-danger"
                        >
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                    <div className="mt-3 flex gap-2">
                        <Button
                            size="sm"
                            onClick={() =>
                                form.post("/admin/storage", {
                                    preserveScroll: true,
                                    onSuccess: () => form.setData({ ...empty }),
                                })
                            }
                        >
                            Save
                        </Button>
                        {form.data.id && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => form.setData({ ...empty })}
                            >
                                New
                            </Button>
                        )}
                    </div>
                </Card>
                <Card title="Switching storage safely">
                    <ol className="list-decimal space-y-1 pl-5 text-sm">
                        <li>
                            Add the storage and press <b>Test connection</b>.
                        </li>
                        <li>
                            Press <b>Copy files</b>. Existing files are copied
                            in the background and every file is checked; nothing
                            is deleted.
                        </li>
                        <li>
                            When the copy has no failures, press <b>Activate</b>
                            . New files go to the new storage straight away, and
                            files uploaded since the copy are copied
                            automatically.
                        </li>
                    </ol>
                </Card>
            </div>
            {pageErrors.target && (
                <p role="alert" className="mt-4 text-sm text-status-danger">
                    {pageErrors.target}
                </p>
            )}
            {targets.map((t) => (
                <Card
                    key={t.id}
                    title={t.name}
                    aside={
                        <Badge
                            tone={
                                t.active
                                    ? "success"
                                    : t.verified
                                      ? "neutral"
                                      : "warning"
                            }
                        >
                            {t.active
                                ? "active"
                                : t.verified
                                  ? "tested"
                                  : "not tested"}
                        </Badge>
                    }
                    className="mt-4"
                >
                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <span className="flex-1">
                            {t.driver === "s3"
                                ? `${providers[t.provider ?? "other"] ?? "S3"} · ${t.bucket} · ${t.region ?? ""}${t.endpoint ? ` · ${t.endpoint}` : ""}${t.encrypt ? " · encrypted" : ""}`
                                : "This server’s disk"}
                            {t.lastError && (
                                <span className="block text-xs text-status-danger">
                                    {t.lastError}
                                </span>
                            )}
                            {!t.active && (
                                <span
                                    className={`block text-xs ${t.copy.status === "failed" ? "text-status-danger" : "text-muted"}`}
                                    aria-live="polite"
                                >
                                    {copyText(t)}
                                </span>
                            )}
                        </span>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => edit(t)}
                        >
                            Edit
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.post(
                                    `/admin/storage/${t.id}/test`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Test connection
                        </Button>
                        {!t.active && t.verified && (
                            <Button
                                size="sm"
                                variant="secondary"
                                disabled={
                                    t.copy.status === "queued" ||
                                    t.copy.status === "running"
                                }
                                onClick={() =>
                                    router.post(
                                        `/admin/storage/${t.id}/copy`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t.copy.status ? "Copy again" : "Copy files"}
                            </Button>
                        )}
                        {!t.active &&
                            t.verified &&
                            t.copy.status === "done" &&
                            t.copy.current && (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        window.confirm(
                                            "Activate this storage for all practices? New files will be kept here.",
                                        ) &&
                                        router.post(
                                            `/admin/storage/${t.id}/activate`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Activate
                                </Button>
                            )}
                    </div>
                </Card>
            ))}
        </AdminShell>
    );
}
