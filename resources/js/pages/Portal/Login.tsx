import { usePortalText } from "@/lib/portalText";
import { HumanCheck } from "@/components/HumanCheck";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import type { FormEvent } from "react";
import { Field } from "@/components/form/Field";
import { Button, Card } from "@/components/ui";
import { PortalLayout } from "@/layouts/PortalLayout";

export default function PortalLogin({ provider }: { provider: string }) {
    const t = usePortalText();
    const { languages = [], portalLanguage = "en" } = usePage<{
        languages?: { code: string; name: string }[];
        portalLanguage?: string;
    }>().props;
    const form = useForm({ "cf-turnstile-response": "", cell: "" });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post("/my/login");
    };

    return (
        <PortalLayout provider={provider}>
            {languages.length > 1 && (
                <label className="mb-4 flex flex-wrap items-center gap-2 text-sm">
                    <span aria-hidden="true">🌐</span>
                    <select
                        aria-label="Language"
                        className="min-h-10 rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm"
                        value={portalLanguage}
                        onChange={(e) =>
                            router.post(
                                "/my/display-language",
                                { language: e.target.value },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {languages.map((l) => (
                            <option key={l.code} value={l.code}>
                                {l.name}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <Head title={t("portal.login.sign_in")} />
            <Card title={t("portal.login.your_health_records")}>
                <form onSubmit={submit} className="flex flex-col gap-3">
                    <p className="text-sm text-muted">
                        {t(
                            "portal.login.enter_the_cell_number_the_practice_has_f",
                        )}
                    </p>
                    <Field
                        label={t("portal.login.cell_number")}
                        name="cell"
                        inputMode="tel"
                        maxLength={10}
                        value={form.data.cell}
                        onChange={(e) =>
                            form.setData(
                                "cell",
                                e.target.value.replace(/\D/g, ""),
                            )
                        }
                        error={form.errors.cell}
                    />
                    <HumanCheck
                        onToken={(t) =>
                            form.setData("cf-turnstile-response", t)
                        }
                        error={(form.errors as Record<string, string>).human}
                    />
                    <Button
                        type="submit"
                        size="lg"
                        disabled={
                            form.processing || form.data.cell.length !== 10
                        }
                    >
                        {t("portal.login.send_code")}
                    </Button>
                </form>
            </Card>
        </PortalLayout>
    );
}
