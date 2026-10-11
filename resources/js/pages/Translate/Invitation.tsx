import { Head, Link, router, useForm } from "@inertiajs/react";
import { Field } from "@/components/form/Field";
import { Button, Card } from "@/components/ui";
import { AuthLayout } from "@/layouts/AuthLayout";

interface Props {
    token: string;
    name: string | null;
    contact: string | null;
    valid: boolean;
    signedIn: boolean;
}

/** A translation reviewer's invitation: create an account, or accept with the one you are signed in to. */
export default function Invitation({
    token,
    name,
    contact,
    valid,
    signedIn,
}: Props) {
    const form = useForm({
        email: contact?.includes("@") ? contact : "",
        phone: contact && !contact.includes("@") ? contact : "",
        password: "",
        password_confirmation: "",
    });

    return (
        <AuthLayout>
            <Head title="Review translations" />
            {!valid ? (
                <Card title="This link has expired">
                    <p className="text-sm text-muted">
                        Invitation links work once, for 7 days. Please ask for a
                        new invitation.
                    </p>
                </Card>
            ) : (
                <Card title={`Welcome${name ? `, ${name}` : ""}`}>
                    <p className="mb-4 text-sm text-muted">
                        You are invited to review Dr Business Flow translations.
                        You will see only the texts to translate — never any
                        patient information.
                    </p>
                    {signedIn ? (
                        <Button
                            onClick={() =>
                                router.post(`/translate/invite/${token}/accept`)
                            }
                        >
                            Accept and start reviewing
                        </Button>
                    ) : (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(
                                    `/translate/invite/${token}/register`,
                                );
                            }}
                            className="space-y-3"
                        >
                            <Field
                                label="Email"
                                name="email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData("email", e.target.value)
                                }
                                error={form.errors.email}
                            />
                            <Field
                                label="Mobile number"
                                name="phone"
                                value={form.data.phone}
                                onChange={(e) =>
                                    form.setData("phone", e.target.value)
                                }
                                error={form.errors.phone}
                            />
                            <Field
                                label="Choose a password (10+ characters, letters and numbers)"
                                name="password"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password}
                                onChange={(e) =>
                                    form.setData("password", e.target.value)
                                }
                                error={form.errors.password}
                            />
                            <Field
                                label="Repeat the password"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password_confirmation}
                                onChange={(e) =>
                                    form.setData(
                                        "password_confirmation",
                                        e.target.value,
                                    )
                                }
                            />
                            <Button type="submit" disabled={form.processing}>
                                Create my account
                            </Button>
                            <p className="text-sm text-muted">
                                Already have an account?{" "}
                                <Link
                                    href={`/login`}
                                    className="text-teal-deep underline"
                                >
                                    Sign in
                                </Link>{" "}
                                and open this link again.
                            </p>
                        </form>
                    )}
                </Card>
            )}
        </AuthLayout>
    );
}
