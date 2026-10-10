import { HumanCheck } from "@/components/HumanCheck";
import { Head, Link, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Button, Card } from "@/components/ui";

/** "Forgot your password?" — sends a single-use reset link by email. */
export default function ForgotPassword() {
    const form = useForm({ "cf-turnstile-response": "", email: "" });

    return (
        <div className="mx-auto max-w-md px-6 py-16">
            <Head title="Forgot your password?" />
            <Card title="Forgot your password?">
                <p className="mb-3 text-sm text-muted">
                    Enter your email and we'll send a link to choose a new
                    password. It works once, for 60 minutes.
                </p>
                <Flash />
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post("/forgot-password");
                    }}
                    className="space-y-3"
                >
                    <label className="block text-sm">
                        Email
                        <input
                            aria-label="Email"
                            type="email"
                            autoComplete="email"
                            className="mt-1 w-full rounded-md border border-line px-3 py-2"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData("email", e.target.value)
                            }
                        />
                    </label>
                    {form.errors.email && (
                        <p role="alert" className="text-xs text-status-danger">
                            {form.errors.email}
                        </p>
                    )}
                    <HumanCheck
                        onToken={(t) =>
                            form.setData("cf-turnstile-response", t)
                        }
                        error={(form.errors as Record<string, string>).human}
                    />
                    <Button disabled={form.processing || !form.data.email}>
                        Send the link
                    </Button>
                </form>
                <p className="mt-4 text-sm">
                    <Link href="/login" className="text-teal-deep underline">
                        Back to sign in
                    </Link>
                </p>
            </Card>
        </div>
    );
}
