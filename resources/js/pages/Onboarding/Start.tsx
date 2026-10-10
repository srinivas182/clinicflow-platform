import { HumanCheck } from "@/components/HumanCheck";
import { Head, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import { Checkbox, Field } from "@/components/form/Field";
import { Logo } from "@/components/Logo";
import { Button, Card } from "@/components/ui";
import { rand } from "@/lib/money";
import type { PackageOption } from "@/pages/Public/Pricing";

type Checks = Record<string, { type: string; label: string }[]>;

export default function Start({
    packages,
    providerDomain,
    checks,
}: {
    packages: PackageOption[];
    providerDomain: string;
    checks: Checks;
}) {
    const preselected = Number(
        new URLSearchParams(
            typeof window === "undefined" ? "" : window.location.search,
        ).get("package"),
    );
    const initial = packages.find((p) => p.id === preselected) ?? packages[0];

    const form = useForm({
        "cf-turnstile-response": "",
        name: "",
        type: initial?.providerType ?? "clinic",
        subdomain: "",
        package_id: initial?.id ?? 0,
        owner_name: "",
        owner_email: "",
        owner_phone: "",
        password: "",
        password_confirmation: "",
        references: {} as Record<string, string>,
        accept_terms: false,
    });

    const e = form.errors as Record<string, string | undefined>;
    const options = packages.filter((p) => p.providerType === form.data.type);
    const select =
        "min-h-10 rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm";

    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        form.post("/start");
    };

    return (
        <div className="min-h-screen bg-paper">
            <Head title="Start your free trial" />
            <header className="flex items-center border-b border-line bg-surface px-10 py-4">
                <Logo />
                <span className="ml-auto text-sm text-muted">
                    30-day free trial · no card needed
                </span>
            </header>
            <form
                onSubmit={submit}
                className="mx-auto grid max-w-5xl grid-cols-3 gap-5 px-6 py-10"
                noValidate
            >
                <div className="col-span-3">
                    <h1 className="text-2xl font-semibold">
                        Set up your practice
                    </h1>
                    <p className="text-sm text-muted">
                        You get your own database, hosted in South Africa. We
                        verify your registrations before you appear in search.
                    </p>
                </div>
                <Card title="Practice" className="col-span-2">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="flex flex-col gap-1.5">
                            <label
                                htmlFor="type"
                                className="text-xs font-medium"
                            >
                                Type
                            </label>
                            <select
                                id="type"
                                className={select}
                                value={form.data.type}
                                onChange={(ev) => {
                                    const first = packages.find(
                                        (p) =>
                                            p.providerType === ev.target.value,
                                    );
                                    form.setData((d) => ({
                                        ...d,
                                        type: ev.target.value,
                                        package_id: first?.id ?? 0,
                                        references: {},
                                    }));
                                }}
                            >
                                <option value="clinic">Clinic</option>
                                <option value="independent_doctor">
                                    Independent doctor
                                </option>
                                <option value="pharmacy">Pharmacy</option>
                                <option value="lab">Lab</option>
                            </select>
                        </div>
                        <Field
                            label="Practice name"
                            name="name"
                            value={form.data.name}
                            onChange={(ev) =>
                                form.setData("name", ev.target.value)
                            }
                            error={e.name}
                        />
                        <Field
                            label="Your address"
                            name="subdomain"
                            value={form.data.subdomain}
                            onChange={(ev) =>
                                form.setData(
                                    "subdomain",
                                    ev.target.value.toLowerCase(),
                                )
                            }
                            error={e.subdomain}
                            hint={`${form.data.subdomain || "your-practice"}.${providerDomain}`}
                        />
                        <div className="flex flex-col gap-1.5">
                            <label
                                htmlFor="package_id"
                                className="text-xs font-medium"
                            >
                                Package
                            </label>
                            <select
                                id="package_id"
                                className={select}
                                value={form.data.package_id}
                                onChange={(ev) =>
                                    form.setData(
                                        "package_id",
                                        Number(ev.target.value),
                                    )
                                }
                            >
                                {options.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name} — {rand(p.priceMonthly)}/month
                                    </option>
                                ))}
                            </select>
                            {e.package && (
                                <p className="text-xs text-status-danger">
                                    {e.package}
                                </p>
                            )}
                        </div>
                    </div>
                </Card>
                <Card title="Registrations to verify">
                    <div className="flex flex-col gap-3">
                        {(checks[form.data.type] ?? [])
                            .filter((c) => c.type !== "popia_agreement")
                            .map((c) => (
                                <Field
                                    key={c.type}
                                    label={c.label}
                                    name={`references.${c.type}`}
                                    value={form.data.references[c.type] ?? ""}
                                    onChange={(ev) =>
                                        form.setData("references", {
                                            ...form.data.references,
                                            [c.type]: ev.target.value,
                                        })
                                    }
                                />
                            ))}
                    </div>
                </Card>
                <Card title="Owner account" className="col-span-2">
                    <div className="grid grid-cols-2 gap-3">
                        <Field
                            label="Full name"
                            name="owner_name"
                            value={form.data.owner_name}
                            onChange={(ev) =>
                                form.setData("owner_name", ev.target.value)
                            }
                            error={e.owner_name}
                        />
                        <Field
                            label="Email"
                            name="owner_email"
                            type="email"
                            value={form.data.owner_email}
                            onChange={(ev) =>
                                form.setData("owner_email", ev.target.value)
                            }
                            error={e.owner_email}
                        />
                        <Field
                            label="Cell number"
                            name="owner_phone"
                            maxLength={10}
                            value={form.data.owner_phone}
                            onChange={(ev) =>
                                form.setData(
                                    "owner_phone",
                                    ev.target.value.replace(/\D/g, ""),
                                )
                            }
                            error={e.owner_phone}
                        />
                        <div />
                        <Field
                            label="Password"
                            name="password"
                            type="password"
                            value={form.data.password}
                            onChange={(ev) =>
                                form.setData("password", ev.target.value)
                            }
                            error={e.password}
                            hint="At least 10 characters with letters and numbers"
                        />
                        <Field
                            label="Confirm password"
                            name="password_confirmation"
                            type="password"
                            value={form.data.password_confirmation}
                            onChange={(ev) =>
                                form.setData(
                                    "password_confirmation",
                                    ev.target.value,
                                )
                            }
                        />
                    </div>
                </Card>
                <Card title="Agreement">
                    <Checkbox
                        name="accept_terms"
                        label="I accept the terms and the POPIA operator agreement"
                        checked={form.data.accept_terms}
                        onChange={(v) => form.setData("accept_terms", v)}
                        error={e.accept_terms}
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
                        className="mt-5 w-full justify-center"
                        disabled={form.processing}
                    >
                        Start free trial
                    </Button>
                </Card>
            </form>
        </div>
    );
}
