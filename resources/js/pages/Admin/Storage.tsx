import { Head, router, useForm } from "@inertiajs/react";
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
}
interface Props {
    targets: Target[];
    providers: Record<string, string>;
    current: string;
}

const HINTS: Record<string, string> = {
    aws: "Region e.g. af-south-1 (Cape Town). Leave the endpoint empty.",
    gcs: "Endpoint https://storage.googleapis.com, region auto, HMAC keys. Bucket in africa-south1 (Johannesburg) keeps files in South Africa.",
    minio: 'Endpoint of your MinIO server (https://…), tick "path-style".',
    r2: "Endpoint https://<account-id>.r2.cloudflarestorage.com, region auto. Files are stored outside South Africa.",
    wasabi: "Endpoint https://s3.<region>.wasabisys.com. Files are stored outside South Africa.",
    digitalocean:
        "Endpoint https://<region>.digitaloceanspaces.com. Files are stored outside South Africa.",
    backblaze:
        "Endpoint https://s3.<region>.backblazeb2.com. Files are stored outside South Africa.",
    other: "Enter the provider\u2019s S3 endpoint (https://…).",
};
const empty = {
    id: "",
    name: "",
    driver: "s3",
    provider: "aws",
    bucket: "",
    region: "af-south-1",
    endpoint: "",
    path_style: false,
    encrypt: true,
    root: "clinicflow",
    key: "",
    secret: "",
};

export default function AdminStorage({ targets, providers, current }: Props) {
    const form = useForm({ ...empty });
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
            <Head title="Storage" />
            <h1 className="mb-1 text-2xl font-semibold">File storage</h1>
            <p className="mb-5 text-sm text-muted">
                Where uploads, documents, lab reports and website media are
                kept. Currently:{" "}
                <b>
                    {current === "local"
                        ? "this server\u2019s disk"
                        : "S3-compatible storage"}
                </b>
                . Local disk works for one server only — use S3-compatible
                storage before running several servers. Buckets stay private;
                files are always served through Clinic Flow&apos;s permission
                checks, each practice in its own folder.
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
                                form.setData("driver", e.target.value)
                            }
                        >
                            <option value="s3">S3 or S3-compatible</option>
                            <option value="local">
                                This server&apos;s disk
                            </option>
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
                                    Encrypt at rest
                                </label>
                            </>
                        )}
                        <input
                            aria-label="Folder"
                            placeholder={
                                form.data.driver === "s3"
                                    ? "Folder in the bucket, e.g. clinicflow"
                                    : "Folder on this server (empty = default)"
                            }
                            className={`${input} col-span-2`}
                            value={form.data.root}
                            onChange={(e) =>
                                form.setData("root", e.target.value)
                            }
                        />
                    </div>
                    {Object.values(form.errors)[0] && (
                        <p className="mt-2 text-xs text-status-danger">
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
                <Card title="Moving existing files">
                    <ol className="list-decimal space-y-1 pl-5 text-sm">
                        <li>
                            Add the new storage and press <b>Test</b>.
                        </li>
                        <li>
                            On the server, run{" "}
                            <code className="font-mono text-xs">
                                php artisan storage:copy-to-active &lt;id&gt;
                                --dry-run
                            </code>
                            , then without{" "}
                            <code className="font-mono text-xs">--dry-run</code>
                            . Every file is copied into its practice&apos;s
                            folder and verified (size and SHA-256). Nothing is
                            deleted.
                        </li>
                        <li>
                            Press <b>Activate</b> — new files go to the new
                            storage.
                        </li>
                        <li>
                            Run the copy once more to catch files uploaded in
                            between.
                        </li>
                    </ol>
                </Card>
            </div>
            {targets.map((t) => (
                <Card
                    key={t.id}
                    title={`${t.name} (id ${t.id})`}
                    aside={
                        <span className="flex gap-1">
                            {t.active && <Badge tone="success">active</Badge>}
                            <Badge tone={t.verified ? "success" : "warning"}>
                                {t.verified ? "tested" : "not tested"}
                            </Badge>
                        </span>
                    }
                    className="mt-4"
                >
                    <p className="text-sm text-muted">
                        {t.driver === "local"
                            ? "This server\u2019s disk"
                            : `${providers[t.provider ?? "other"] ?? "S3"} · ${t.bucket} · ${t.region ?? ""}${t.endpoint ? ` · ${t.endpoint}` : ""}`}
                        {t.root ? ` · folder ${t.root}` : ""}
                        {t.encrypt && t.driver === "s3" ? " · encrypted" : ""}
                    </p>
                    {t.lastError && (
                        <p className="mt-1 text-xs text-status-danger">
                            Last test: {t.lastError}
                        </p>
                    )}
                    <div className="mt-2 flex gap-2">
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
                        {!t.active && (
                            <Button
                                size="sm"
                                disabled={!t.verified}
                                onClick={() =>
                                    window.confirm(
                                        "New files will be stored here for every practice. Activate?",
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
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => edit(t)}
                        >
                            Edit
                        </Button>
                    </div>
                </Card>
            ))}
        </AdminShell>
    );
}
