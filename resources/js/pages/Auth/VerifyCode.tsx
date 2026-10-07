import { Head, Link, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import { Field } from "@/components/form/Field";
import { Button } from "@/components/ui";
import { AuthLayout } from "@/layouts/AuthLayout";

export default function VerifyCode({
    method = "message",
}: {
    method?: string;
}) {
    const form = useForm({ code: "", trust_device: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post("/login/verify");
    };

    return (
        <AuthLayout>
            <Head title="Enter code" />
            <h1 className="text-2xl font-semibold">Enter your code</h1>
            <p className="mt-1 text-sm text-muted">
                {method === "authenticator"
                    ? "Enter the 6-digit code from your authenticator app, or one of your recovery codes."
                    : "We sent a 6-digit code to your phone. It expires in 5 minutes."}
            </p>
            <form
                onSubmit={submit}
                className="mt-8 flex flex-col gap-4"
                noValidate
            >
                <Field
                    label="One-time code"
                    name="code"
                    inputMode={method === "authenticator" ? "text" : "numeric"}
                    autoComplete="one-time-code"
                    maxLength={method === "authenticator" ? 11 : 6}
                    value={form.data.code}
                    onChange={(e) =>
                        form.setData(
                            "code",
                            method === "authenticator"
                                ? e.target.value.trim().toLowerCase()
                                : e.target.value.replace(/\D/g, ""),
                        )
                    }
                    error={form.errors.code}
                />
                <label className="flex items-center gap-2 text-sm text-muted">
                    <input
                        type="checkbox"
                        className="accent-teal"
                        checked={form.data.trust_device}
                        onChange={(e) =>
                            form.setData("trust_device", e.target.checked)
                        }
                    />
                    Trust this device for 30 days (don’t use on shared
                    computers)
                </label>
                <Button
                    type="submit"
                    size="lg"
                    disabled={form.processing || form.data.code.length !== 6}
                >
                    Sign in
                </Button>
                <Link
                    href="/login"
                    className="text-center text-sm text-teal-deep"
                >
                    Start again
                </Link>
            </form>
        </AuthLayout>
    );
}
