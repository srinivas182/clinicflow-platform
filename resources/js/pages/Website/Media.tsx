import { Head, router, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Item {
    id: string;
    url: string;
    thumb: string;
    alt: string;
    filename: string;
    size: string;
    usedOn: string[];
}

export default function Media({
    media,
    ogImage,
}: {
    media: Item[];
    ogImage: string | null;
}) {
    const form = useForm<{ file: File | null; alt: string }>({
        file: null,
        alt: "",
    });

    return (
        <AppShell active="Settings">
            <Head title="Website images" />
            <h1 className="mb-1 text-2xl font-semibold">Website images</h1>
            <p className="mb-5 text-sm text-muted">
                Images are resized for the web and location data is removed.
                Copy an image's address into a page section.
            </p>
            <Flash />
            <Card title="Upload" className="mb-4">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <input
                        aria-label="Image file"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        onChange={(e) =>
                            form.setData("file", e.target.files?.[0] ?? null)
                        }
                    />
                    <input
                        aria-label="Description"
                        placeholder="Describe the image"
                        className="flex-1 rounded-md border border-line px-2 py-1"
                        value={form.data.alt}
                        onChange={(e) => form.setData("alt", e.target.value)}
                    />
                    <Button
                        size="sm"
                        disabled={!form.data.file}
                        onClick={() =>
                            form.post("/settings/website/media", {
                                forceFormData: true,
                                onSuccess: () => form.reset(),
                            })
                        }
                    >
                        Upload
                    </Button>
                </div>
                {(form.errors.file || form.errors.alt) && (
                    <p className="mt-1 text-xs text-status-danger">
                        {form.errors.file ?? form.errors.alt}
                    </p>
                )}
            </Card>
            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                {media.map((m) => (
                    <Card key={m.id}>
                        <img
                            src={m.thumb}
                            alt={m.alt}
                            className="mb-2 aspect-[4/3] w-full rounded-md object-cover"
                        />
                        <p className="truncate text-xs" title={m.filename}>
                            {m.filename} · {m.size}
                        </p>
                        <p className="mb-1 font-mono text-[11px] break-all">
                            {m.url}
                        </p>
                        {ogImage === m.url && (
                            <Badge tone="teal">share image</Badge>
                        )}
                        {m.usedOn.length > 0 && (
                            <p className="text-xs text-muted">
                                Used on: {m.usedOn.join(", ")}
                            </p>
                        )}
                        <div className="mt-2 flex flex-wrap gap-1">
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.put(
                                        `/settings/website/media/${m.id}`,
                                        {
                                            alt:
                                                window.prompt(
                                                    "Description",
                                                    m.alt,
                                                ) ?? m.alt,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Describe
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.put(
                                        `/settings/website/media/${m.id}`,
                                        { alt: m.alt, og: true },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Use for sharing
                            </Button>
                            {m.usedOn.length === 0 && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        window.confirm("Delete this image?") &&
                                        router.delete(
                                            `/settings/website/media/${m.id}`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Delete
                                </Button>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
        </AppShell>
    );
}
