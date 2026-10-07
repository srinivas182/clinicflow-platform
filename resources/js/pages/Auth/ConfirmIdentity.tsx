import { Head, useForm } from "@inertiajs/react";
import { Button, Card } from "@/components/ui";

/** "Confirm it's you" before a sensitive action (exports, API keys, break-glass, support access). */
export default function ConfirmIdentity({
    authenticator,
    minutes,
}: {
    authenticator: boolean;
    minutes: number;
}) {
    const form = useForm({ secret: "" });

    return (
        <div className="mx-auto max-w-md px-6 py-16">
            <Head title="Confirm it's you" />
            <Card title="Confirm it’s you">
                <p className="mb-3 text-sm text-muted">
                    This action is protected.{" "}
                    {authenticator
                        ? "Enter a code from your authenticator app."
                        : "Enter your password."}{" "}
                    You won’t be asked again for {minutes} minutes.
                </p>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post("/confirm-identity");
                    }}
                    className="flex items-center gap-2"
                >
                    <input
                        aria-label={
                            authenticator ? "Authenticator code" : "Password"
                        }
                        type={authenticator ? "text" : "password"}
                        inputMode={authenticator ? "numeric" : undefined}
                        autoComplete={
                            authenticator ? "one-time-code" : "current-password"
                        }
                        autoFocus
                        className="flex-1 rounded-md border border-line px-3 py-2"
                        value={form.data.secret}
                        onChange={(e) => form.setData("secret", e.target.value)}
                    />
                    <Button disabled={form.processing || !form.data.secret}>
                        Confirm
                    </Button>
                </form>
                {form.errors.secret && (
                    <p role="alert" className="mt-2 text-xs text-status-danger">
                        {form.errors.secret}
                    </p>
                )}
            </Card>
        </div>
    );
}
