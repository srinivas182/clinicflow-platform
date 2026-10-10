import { Head, useForm } from "@inertiajs/react";
import { Button, Card } from "@/components/ui";

/** Choose a new password from an emailed reset link. */
export default function ResetPassword({
    token,
    email,
}: {
    token: string;
    email: string;
}) {
    const form = useForm({
        token,
        email,
        password: "",
        password_confirmation: "",
    });
    const input = "mt-1 w-full rounded-md border border-line px-3 py-2";

    return (
        <div className="mx-auto max-w-md px-6 py-16">
            <Head title="Choose a new password" />
            <Card title="Choose a new password">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post("/reset-password");
                    }}
                    className="space-y-3"
                >
                    <label className="block text-sm">
                        Email
                        <input
                            aria-label="Email"
                            type="email"
                            className={input}
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData("email", e.target.value)
                            }
                        />
                    </label>
                    <label className="block text-sm">
                        New password (10+ characters, letters and numbers)
                        <input
                            aria-label="New password"
                            type="password"
                            autoComplete="new-password"
                            className={input}
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData("password", e.target.value)
                            }
                        />
                    </label>
                    <label className="block text-sm">
                        Repeat the new password
                        <input
                            aria-label="Repeat the new password"
                            type="password"
                            autoComplete="new-password"
                            className={input}
                            value={form.data.password_confirmation}
                            onChange={(e) =>
                                form.setData(
                                    "password_confirmation",
                                    e.target.value,
                                )
                            }
                        />
                    </label>
                    {Object.values(form.errors)[0] && (
                        <p role="alert" className="text-xs text-status-danger">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                    <Button disabled={form.processing}>
                        Save the new password
                    </Button>
                </form>
            </Card>
        </div>
    );
}
