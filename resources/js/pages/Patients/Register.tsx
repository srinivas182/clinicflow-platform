import { Head, useForm, usePage } from "@inertiajs/react";
import { type FormEvent, useEffect, useState } from "react";
import { Checkbox, Field } from "@/components/form/Field";
import { Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

type IdType = "sa_id" | "passport" | "permit" | "none";

/** Registration in four short steps; every field stays in the form, so nothing typed is lost. */
const STEPS = ["Identity", "Contact", "Medical aid", "Consent"] as const;

/** Which step a field belongs to (used to open the step with a problem). */
function stepOf(field: string): number {
    if (/^(cell|no_cell|email|preferred_|address|guardian_)/.test(field))
        return 1;
    if (field.startsWith("medical_aid")) return 2;
    if (/^(popia_|treatment_|consent_|maturity_)/.test(field)) return 3;
    return 0;
}

/**
 * Register patient. Server-side rules (RegisterPatient action) are authoritative;
 * this form mirrors them so reception sees problems before saving.
 */
export default function RegisterPatient() {
    const form = useForm({
        first_names: "",
        surname: "",
        id_type: "sa_id" as IdType,
        id_number: "",
        passport_country: "",
        date_of_birth: "",
        cell: "",
        no_cell: false,
        email: "",
        preferred_language: "en",
        preferred_channel: "sms",
        address: "",
        guardian_name: "",
        guardian_relationship: "",
        guardian_cell: "",
        popia_consent: false,
        treatment_consent: false,
        consent_given_by: "patient",
        maturity_confirmed: false,
        medical_aid_scheme: "",
        medical_aid_plan: "",
        medical_aid_number: "",
        medical_aid_dependant_code: "",
    });

    const [step, setStep] = useState(0);
    const last = STEPS.length - 1;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        // Enter on an earlier step moves on, rather than saving half a registration.
        if (step < last) return setStep(step + 1);
        form.post("/patients");
    };

    const e = form.errors;
    const languages =
        usePage<{ languages: { code: string; name: string }[] }>().props
            .languages ?? [];
    // If the server finds a problem, open the step that has it.
    useEffect(() => {
        const first = Object.keys(form.errors)[0];
        if (first) setStep(stepOf(first));
    }, [form.errors]);
    useEffect(() => window.scrollTo({ top: 0, behavior: "smooth" }), [step]);
    const show = (n: number) => (step === n ? "sm:col-span-3" : "hidden");
    const select =
        "min-h-10 rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm";

    return (
        <AppShell active="Patients">
            <Head title="Register patient" />
            <h1 className="text-2xl font-semibold">Register patient</h1>
            <p className="mt-1 text-sm text-muted">
                Identity, consent and contact must be settled before the patient
                can join the queue.
            </p>
            <form
                onSubmit={submit}
                className="mt-6 grid sm:grid-cols-3 gap-4"
                noValidate
            >
                <div className="sm:col-span-3">
                    <p className="text-sm font-medium" aria-live="polite">
                        Step {step + 1} of {STEPS.length}: {STEPS[step]}
                    </p>
                    <ol className="mt-2 flex gap-1.5" aria-hidden="true">
                        {STEPS.map((s, i) => (
                            <li
                                key={s}
                                className={`h-1.5 flex-1 rounded-full ${i <= step ? "bg-teal" : "bg-line"}`}
                            />
                        ))}
                    </ol>
                </div>
                <Card title="Identity" className={show(0)}>
                    <div className="grid sm:grid-cols-2 gap-3">
                        <Field
                            label="First names"
                            name="first_names"
                            value={form.data.first_names}
                            onChange={(ev) =>
                                form.setData("first_names", ev.target.value)
                            }
                            error={e.first_names}
                        />
                        <Field
                            label="Surname"
                            name="surname"
                            value={form.data.surname}
                            onChange={(ev) =>
                                form.setData("surname", ev.target.value)
                            }
                            error={e.surname}
                        />
                        <div className="flex flex-col gap-1.5">
                            <label
                                htmlFor="id_type"
                                className="text-xs font-medium"
                            >
                                Identity document
                            </label>
                            <select
                                id="id_type"
                                className={select}
                                value={form.data.id_type}
                                onChange={(ev) =>
                                    form.setData(
                                        "id_type",
                                        ev.target.value as IdType,
                                    )
                                }
                            >
                                <option value="sa_id">SA ID</option>
                                <option value="passport">Passport</option>
                                <option value="permit">Permit</option>
                                <option value="none">None yet</option>
                            </select>
                        </div>
                        {form.data.id_type !== "none" && (
                            <Field
                                label={
                                    form.data.id_type === "sa_id"
                                        ? "SA ID number"
                                        : "Document number"
                                }
                                name="id_number"
                                inputMode={
                                    form.data.id_type === "sa_id"
                                        ? "numeric"
                                        : "text"
                                }
                                value={form.data.id_number}
                                onChange={(ev) =>
                                    form.setData("id_number", ev.target.value)
                                }
                                error={e.id_number}
                                hint={
                                    form.data.id_type === "sa_id"
                                        ? "Fills date of birth and sex automatically"
                                        : undefined
                                }
                            />
                        )}
                        {(form.data.id_type === "passport" ||
                            form.data.id_type === "permit") && (
                            <Field
                                label="Issuing country (2 letters)"
                                name="passport_country"
                                maxLength={2}
                                value={form.data.passport_country}
                                onChange={(ev) =>
                                    form.setData(
                                        "passport_country",
                                        ev.target.value,
                                    )
                                }
                                error={e.passport_country}
                            />
                        )}
                        {form.data.id_type !== "sa_id" && (
                            <Field
                                label="Date of birth"
                                name="date_of_birth"
                                type="date"
                                value={form.data.date_of_birth}
                                onChange={(ev) =>
                                    form.setData(
                                        "date_of_birth",
                                        ev.target.value,
                                    )
                                }
                                error={e.date_of_birth}
                            />
                        )}
                    </div>
                </Card>
                <Card title="Medical aid (optional)" className={show(2)}>
                    <div className="flex flex-col gap-3">
                        <Field
                            label="Scheme"
                            name="medical_aid_scheme"
                            value={form.data.medical_aid_scheme}
                            onChange={(ev) =>
                                form.setData(
                                    "medical_aid_scheme",
                                    ev.target.value,
                                )
                            }
                            hint="Leave empty for cash patients"
                        />
                        <Field
                            label="Member number"
                            name="medical_aid_number"
                            value={form.data.medical_aid_number}
                            onChange={(ev) =>
                                form.setData(
                                    "medical_aid_number",
                                    ev.target.value,
                                )
                            }
                        />
                    </div>
                </Card>
                <Card title="Contact" className={show(1)}>
                    <div className="grid sm:grid-cols-2 gap-3">
                        {languages.length > 1 && (
                            <label className="flex flex-col gap-1.5 text-xs font-medium">
                                Language for messages
                                <select
                                    className={select}
                                    value={form.data.preferred_language}
                                    onChange={(ev) =>
                                        form.setData(
                                            "preferred_language",
                                            ev.target.value,
                                        )
                                    }
                                >
                                    {languages.map((l) => (
                                        <option key={l.code} value={l.code}>
                                            {l.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <Field
                            label="Cell number"
                            name="cell"
                            inputMode="tel"
                            maxLength={10}
                            disabled={form.data.no_cell}
                            value={form.data.cell}
                            onChange={(ev) =>
                                form.setData(
                                    "cell",
                                    ev.target.value.replace(/\D/g, ""),
                                )
                            }
                            error={e.cell}
                        />
                        <div className="flex flex-col justify-end pb-2">
                            <Checkbox
                                name="no_cell"
                                label="No cellphone — messages go to the guardian or are given in person"
                                checked={form.data.no_cell}
                                onChange={(v) => form.setData("no_cell", v)}
                            />
                        </div>
                        <Field
                            label="Guardian name"
                            name="guardian_name"
                            value={form.data.guardian_name}
                            onChange={(ev) =>
                                form.setData("guardian_name", ev.target.value)
                            }
                            error={e.guardian_name}
                            hint="Required for children under 12"
                        />
                        <div className="grid sm:grid-cols-2 gap-3">
                            <Field
                                label="Relationship"
                                name="guardian_relationship"
                                value={form.data.guardian_relationship}
                                onChange={(ev) =>
                                    form.setData(
                                        "guardian_relationship",
                                        ev.target.value,
                                    )
                                }
                            />
                            <Field
                                label="Guardian cell"
                                name="guardian_cell"
                                maxLength={10}
                                value={form.data.guardian_cell}
                                onChange={(ev) =>
                                    form.setData(
                                        "guardian_cell",
                                        ev.target.value.replace(/\D/g, ""),
                                    )
                                }
                            />
                        </div>
                    </div>
                </Card>
                <Card title="Consent" className={show(3)}>
                    <div className="flex flex-col gap-3">
                        <div className="flex flex-col gap-1.5">
                            <label
                                htmlFor="consent_given_by"
                                className="text-xs font-medium"
                            >
                                Consent given by
                            </label>
                            <select
                                id="consent_given_by"
                                className={select}
                                value={form.data.consent_given_by}
                                onChange={(ev) =>
                                    form.setData(
                                        "consent_given_by",
                                        ev.target.value,
                                    )
                                }
                            >
                                <option value="patient">Patient</option>
                                <option value="guardian">Guardian</option>
                            </select>
                            {e.consent_given_by && (
                                <p
                                    role="alert"
                                    className="text-xs text-status-danger"
                                >
                                    {e.consent_given_by}
                                </p>
                            )}
                        </div>
                        <Checkbox
                            name="popia_consent"
                            label="POPIA consent"
                            checked={form.data.popia_consent}
                            onChange={(v) => form.setData("popia_consent", v)}
                            error={e.popia_consent}
                        />
                        <Checkbox
                            name="treatment_consent"
                            label="Treatment consent"
                            checked={form.data.treatment_consent}
                            onChange={(v) =>
                                form.setData("treatment_consent", v)
                            }
                            error={e.treatment_consent}
                        />
                        <Checkbox
                            name="maturity_confirmed"
                            label="12–17: patient is mature enough to consent"
                            checked={form.data.maturity_confirmed}
                            onChange={(v) =>
                                form.setData("maturity_confirmed", v)
                            }
                            error={e.maturity_confirmed}
                        />
                    </div>
                </Card>
                <div className="sm:col-span-3 flex flex-wrap justify-between gap-2">
                    {step > 0 ? (
                        <Button
                            type="button"
                            variant="secondary"
                            size="lg"
                            onClick={() => setStep(step - 1)}
                        >
                            Back
                        </Button>
                    ) : (
                        <span />
                    )}
                    {step < last ? (
                        <Button
                            type="button"
                            size="lg"
                            onClick={() => setStep(step + 1)}
                        >
                            Next: {STEPS[step + 1]}
                        </Button>
                    ) : (
                        <Button
                            type="submit"
                            size="lg"
                            disabled={form.processing}
                        >
                            Register patient
                        </Button>
                    )}
                </div>
            </form>
        </AppShell>
    );
}
