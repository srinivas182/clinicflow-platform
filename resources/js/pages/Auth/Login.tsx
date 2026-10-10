import { HumanCheck } from "@/components/HumanCheck";
import { Head, Link, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import { Field } from "@/components/form/Field";
import { Button } from "@/components/ui";
import { AuthLayout } from "@/layouts/AuthLayout";

export default function Login({ practice }: { practice?: string | null }) {
    const form = useForm({
        "cf-turnstile-response": "",
        login: "",
        password: "",
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post("/login");
    };

    return (
        <AuthLayout>
            <Head title="Sign in" />
            <h1 className="text-2xl font-semibold">Sign in</h1>
            {practice && (
                <p className="mt-1 text-sm text-muted">to {practice}</p>
            )}
            <p className="mt-1 text-sm text-muted">
                We'll send a one-time code to your phone after your password.
            </p>
            <form
                onSubmit={submit}
                className="mt-8 flex flex-col gap-4"
                noValidate
            >
                <Field
                    label="Email or cell number"
                    name="login"
                    autoComplete="username"
                    value={form.data.login}
                    onChange={(e) => form.setData("login", e.target.value)}
                    error={form.errors.login}
                />
                <Field
                    label="Password"
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    value={form.data.password}
                    onChange={(e) => form.setData("password", e.target.value)}
                    error={form.errors.password}
                />
                <HumanCheck
                    onToken={(t) => form.setData("cf-turnstile-response", t)}
                    error={(form.errors as Record<string, string>).human}
                />
                <Button type="submit" size="lg" disabled={form.processing}>
                    Continue
                </Button>
            </form>
            <p className="mt-3 text-sm">
                <Link
                    href="/forgot-password"
                    className="text-teal-deep underline"
                >
                    Forgot your password?
                </Link>
            </p>
            <p className="mt-3 text-sm text-muted">
                New practice?{" "}
                <Link href="/start" className="text-teal-deep underline">
                    Start free
                </Link>
            </p>
        </AuthLayout>
    );
}
