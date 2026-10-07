import { Head, Link, router, useForm } from "@inertiajs/react";
import { Button, Card } from "@/components/ui";

interface Props {
    valid: boolean;
    token: string;
    practice: string | null;
    role: string | null;
    name: string | null;
    signedIn: boolean;
    existingAccount: boolean;
}

/** Accepting an invitation to join a practice. */
export default function Invitation({
    valid,
    token,
    practice,
    role,
    name,
    signedIn,
    existingAccount,
}: Props) {
    const form = useForm({
        name: name ?? "",
        password: "",
        password_confirmation: "",
    });
    const input = "w-full rounded-md border border-line px-3 py-2";

    return (
        <div className="mx-auto max-w-md px-6 py-16">
            <Head title="Invitation" />
            <Card title={valid ? `Join ${practice}` : "Invitation not valid"}>
                {!valid && (
                    <p className="text-sm">
                        This invitation has expired, was withdrawn or was
                        already used. Ask the practice to send a new one.
                    </p>
                )}
                {valid && (
                    <>
                        <p className="mb-4 text-sm">
                            {practice} has invited you to join them on Clinic
                            Flow as <b>{role}</b>.
                        </p>
                        {signedIn ? (
                            <Button
                                onClick={() =>
                                    router.post(`/invitations/${token}/accept`)
                                }
                            >
                                Accept invitation
                            </Button>
                        ) : existingAccount ? (
                            <p className="text-sm">
                                You already have a Clinic Flow account.{" "}
                                <Link
                                    className="text-teal-deep underline"
                                    href="/login"
                                >
                                    Sign in
                                </Link>
                                , then open this invitation link again to
                                accept.
                            </p>
                        ) : (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.post(`/invitations/${token}/register`);
                                }}
                                className="space-y-2"
                            >
                                <label className="block text-sm">
                                    Your name
                                    <input
                                        aria-label="Your name"
                                        className={input}
                                        value={form.data.name}
                                        onChange={(e) =>
                                            form.setData("name", e.target.value)
                                        }
                                    />
                                </label>
                                <label className="block text-sm">
                                    Choose a password (10+ characters, letters
                                    and numbers)
                                    <input
                                        aria-label="Password"
                                        type="password"
                                        autoComplete="new-password"
                                        className={input}
                                        value={form.data.password}
                                        onChange={(e) =>
                                            form.setData(
                                                "password",
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="block text-sm">
                                    Repeat the password
                                    <input
                                        aria-label="Repeat the password"
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
                                    <p className="text-xs text-status-danger">
                                        {Object.values(form.errors)[0]}
                                    </p>
                                )}
                                <Button disabled={form.processing}>
                                    Create account and join
                                </Button>
                            </form>
                        )}
                    </>
                )}
            </Card>
        </div>
    );
}
