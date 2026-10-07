import { Head, Link, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import { Field } from "@/components/form/Field";
import { Button, Card } from "@/components/ui";
import { PortalLayout } from "@/layouts/PortalLayout";

export default function PortalVerify({ provider }: { provider: string }) {
    const form = useForm({ code: "" });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post("/my/verify");
    };

    return (
        <PortalLayout provider={provider}>
            <Head title="Enter code" />
            <Card title="Enter your code">
                <form onSubmit={submit} className="flex flex-col gap-3">
                    <p className="text-sm text-muted">
                        If this number is registered with the practice, a
                        6-digit code is on its way.
                    </p>
                    <Field
                        label="Code"
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
                        Continue
                    </Button>
                    <Link
                        href="/my/login"
                        className="text-center text-sm text-teal-deep"
                    >
                        Use another number
                    </Link>
                </form>
            </Card>
        </PortalLayout>
    );
}
