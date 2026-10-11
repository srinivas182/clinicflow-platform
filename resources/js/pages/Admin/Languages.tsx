import { Head, router, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Checkbox, Field } from "@/components/form/Field";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface Reviewer {
    id: number;
    name: string;
    clinical: boolean;
}
interface Language {
    code: string;
    name: string;
    nativeName: string;
    enabled: boolean;
    live: number;
    total: number;
    reviewers: Reviewer[];
}
interface Invitation {
    name: string;
    contact: string;
    languages: string[];
    clinical: boolean;
    expires: string;
}

/** Admin → Languages: switch languages on or off, see review progress, and invite native-speaker reviewers. */
export default function Languages({
    languages,
    invitations,
}: {
    languages: Language[];
    invitations: Invitation[];
}) {
    const form = useForm({
        name: "",
        email: "",
        phone: "",
        languages: [] as string[],
        clinical: false,
    });
    const others = languages.filter((l) => l.code !== "en");
    const toggleLanguage = (code: string) =>
        form.setData(
            "languages",
            form.data.languages.includes(code)
                ? form.data.languages.filter((c) => c !== code)
                : [...form.data.languages, code],
        );

    return (
        <AdminShell active="Languages">
            <Head title="Languages" />
            <h1 className="mb-1 text-2xl font-semibold">Languages</h1>
            <p className="mb-5 text-sm text-muted">
                English is always on. Patients see a translation only after a
                native speaker approves it — and, for medical wording, a
                clinical reviewer too. Anything not yet approved shows in
                English.
            </p>
            <Flash />
            <div className="grid gap-4 sm:grid-cols-2">
                {languages.map((l) => (
                    <Card
                        key={l.code}
                        title={l.name}
                        aside={
                            <Badge tone={l.enabled ? "success" : "neutral"}>
                                {l.enabled ? "on" : "off"}
                            </Badge>
                        }
                    >
                        <p className="text-sm text-muted">
                            Live: {l.live} of {l.total} texts
                        </p>
                        <div
                            className="mt-2 h-2 rounded-full bg-line"
                            aria-hidden="true"
                        >
                            <div
                                className="h-2 rounded-full bg-teal"
                                style={{
                                    width: `${Math.round((l.live / Math.max(1, l.total)) * 100)}%`,
                                }}
                            />
                        </div>
                        {l.code !== "en" && (
                            <>
                                <div className="mt-3">
                                    <Button
                                        size="sm"
                                        variant={
                                            l.enabled ? "secondary" : "primary"
                                        }
                                        onClick={() =>
                                            router.post(
                                                `/admin/languages/${l.code}`,
                                                { enabled: !l.enabled },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {l.enabled ? "Switch off" : "Switch on"}
                                    </Button>
                                </div>
                                <p className="mt-3 text-xs font-medium text-muted">
                                    Reviewers
                                </p>
                                {l.reviewers.length === 0 && (
                                    <p className="text-sm text-muted">
                                        None yet — invite a native speaker
                                        below.
                                    </p>
                                )}
                                <ul className="mt-1 space-y-1">
                                    {l.reviewers.map((r) => (
                                        <li
                                            key={r.id}
                                            className="flex flex-wrap items-center gap-2 text-sm"
                                        >
                                            <span className="min-w-0 flex-1">
                                                {r.name}
                                            </span>
                                            {r.clinical && (
                                                <Badge tone="neutral">
                                                    clinical
                                                </Badge>
                                            )}
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.delete(
                                                        `/admin/languages/reviewers/${r.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Remove
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </Card>
                ))}
            </div>

            <Card title="Invite a reviewer" className="mt-6">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post("/admin/languages/invite", {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                    className="grid gap-3 sm:grid-cols-3"
                >
                    <Field
                        label="Name"
                        name="name"
                        value={form.data.name}
                        onChange={(e) => form.setData("name", e.target.value)}
                        error={form.errors.name}
                    />
                    <Field
                        label="Email"
                        name="email"
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData("email", e.target.value)}
                        error={form.errors.email}
                    />
                    <Field
                        label="Or mobile"
                        name="phone"
                        value={form.data.phone}
                        onChange={(e) => form.setData("phone", e.target.value)}
                        error={form.errors.phone}
                    />
                    <fieldset className="sm:col-span-3">
                        <legend className="mb-1 text-xs font-medium">
                            Languages they will review
                        </legend>
                        <div className="flex flex-wrap gap-x-4 gap-y-2">
                            {others.map((l) => (
                                <Checkbox
                                    key={l.code}
                                    name={`language_${l.code}`}
                                    label={l.name}
                                    checked={form.data.languages.includes(
                                        l.code,
                                    )}
                                    onChange={() => toggleLanguage(l.code)}
                                />
                            ))}
                        </div>
                        {form.errors.languages && (
                            <p
                                role="alert"
                                className="mt-1 text-xs text-status-danger"
                            >
                                {form.errors.languages}
                            </p>
                        )}
                    </fieldset>
                    <div className="sm:col-span-3">
                        <Checkbox
                            name="clinical"
                            label="Can also approve medical wording (clinical reviewer)"
                            checked={form.data.clinical}
                            onChange={(v) => form.setData("clinical", v)}
                        />
                    </div>
                    <div className="sm:col-span-3">
                        <Button type="submit" disabled={form.processing}>
                            Send invitation
                        </Button>
                    </div>
                </form>
                {invitations.length > 0 && (
                    <div className="mt-5">
                        <p className="text-xs font-medium text-muted">
                            Waiting to accept
                        </p>
                        <ul className="mt-1 space-y-1 text-sm">
                            {invitations.map((i, k) => (
                                <li key={k}>
                                    {i.name} ({i.contact}) —{" "}
                                    {i.languages.join(", ")}
                                    {i.clinical ? ", clinical" : ""} · link
                                    valid until {i.expires}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </Card>
        </AdminShell>
    );
}
