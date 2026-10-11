import { usePortalText } from "@/lib/portalText";
import { Head, Link, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import { Field } from "@/components/form/Field";
import { Button, Card } from "@/components/ui";
import { PortalLayout } from "@/layouts/PortalLayout";

export default function PortalVerify({ provider }: { provider: string }) {
    const t = usePortalText();
    const form = useForm({ code: "" });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post("/my/verify");
    };

    return (
        <PortalLayout provider={provider}>
            <Head title={t("portal.verify.enter_code")} />
            <Card title={t("portal.verify.enter_your_code")}>
                <form onSubmit={submit} className="flex flex-col gap-3">
                    <p className="text-sm text-muted">
                        {t(
                            "portal.verify.if_this_number_is_registered_with_the_pr",
                        )}
                    </p>
                    <Field
                        label={t("portal.verify.code")}
                        name="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={6}
                        value={form.data.code}
                        onChange={(e) =>
                            form.setData(
                                "code",
                                e.target.value.replace(/\D/g, ""),
                            )
                        }
                        error={form.errors.code}
                    />
                    <Button
                        type="submit"
                        size="lg"
                        disabled={
                            form.processing || form.data.code.length !== 6
                        }
                    >
                        {t("portal.verify.continue")}
                    </Button>
                    <Link
                        href="/my/login"
                        className="text-center text-sm text-teal-deep"
                    >
                        {t("portal.verify.use_another_number")}
                    </Link>
                </form>
            </Card>
        </PortalLayout>
    );
}
