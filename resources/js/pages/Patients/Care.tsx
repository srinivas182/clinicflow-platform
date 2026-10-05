import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { ConnectedSystems } from "@/components/ConnectedSystems";
import { AppShell } from "@/layouts/AppShell";

type Row = Record<string, string | number | boolean | null>;
interface Props {
    patient: {
        id: string;
        name: string;
        age: number;
        sex: string | null;
        medicalAid: string | null;
        whatsappOptIn?: boolean;
    };
    problems: Row[];
    monitoring: {
        problem: string;
        check: string;
        last: string | null;
        due: string;
        overdue: boolean;
    }[];
    chronicScripts: { id: string; signed_at: string }[];
    immunisationsDue: {
        vaccine: string;
        dose: string;
        due: string;
        overdue: boolean;
    }[];
    immunisations: Row[];
    pregnancy: {
        edd: string;
        weeks: number;
        contacts: { week: number; date: string; done: boolean }[];
        risks: string[];
    } | null;
    registrations: Row[];
    shared: string[];
    specialties: string[];
    referrals: Row[];
    connected?: { key: number; name: string; allowed: string[] }[];
    fhirCategories?: Record<string, string>;
}

const ask = (label: string, initial = "") =>
    window.prompt(label, initial) ?? "";

export default function Care({
    patient,
    problems,
    monitoring,
    chronicScripts,
    immunisationsDue,
    immunisations,
    pregnancy,
    registrations,
    shared,
    specialties,
    referrals,
    connected = [],
    fhirCategories = {},
}: Props) {
    const act = (action: string, data: Record<string, unknown>) =>
        router.post(`/patients/${patient.id}/care/${action}`, data as never, {
            preserveScroll: true,
        });

    return (
        <AppShell active="Patients">
            <Head title={`Care · ${patient.name}`} />
            <h1 className="mb-1 text-2xl font-semibold">{patient.name}</h1>
            <p className="mb-4 text-sm text-muted">
                {patient.age} years · {patient.sex} ·{" "}
                {patient.medicalAid ?? "Cash"} · Network sharing:{" "}
                {shared.length ? shared.join(", ") : "none"}
            </p>
            <Flash />
            <button
                className="mb-3 text-xs text-teal-deep"
                onClick={() =>
                    router.post(
                        `/patients/${patient.id}/whatsapp`,
                        { opt_in: !patient.whatsappOptIn },
                        { preserveScroll: true },
                    )
                }
            >
                {patient.whatsappOptIn
                    ? "WhatsApp: opted in (withdraw)"
                    : "Record WhatsApp opt-in"}
            </button>
            <div className="grid grid-cols-2 gap-4">
                <Card
                    title="Problem list"
                    aside={
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                                const c = ask("ICD-10 code (e.g. E11.9)");
                                if (c) act("problem", { icd10_code: c });
                            }}
                        >
                            Add
                        </Button>
                    }
                >
                    <ul className="text-sm">
                        {problems.map((p) => (
                            <li
                                key={String(p.id)}
                                className="flex items-center gap-2 py-1"
                            >
                                <span className="flex-1">
                                    {p.icd10_code} {p.description}
                                </span>
                                <Badge
                                    tone={
                                        p.status === "active"
                                            ? "warning"
                                            : "neutral"
                                    }
                                >
                                    {String(p.status)}
                                </Badge>
                                {p.status === "active" && (
                                    <button
                                        className="text-xs text-muted"
                                        onClick={() =>
                                            router.post(
                                                `/problems/${p.id}/resolve`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        resolve
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
                <Card title="Chronic monitoring due">
                    <ul className="text-sm">
                        {monitoring.length === 0 && (
                            <li className="text-muted">Nothing due.</li>
                        )}
                        {monitoring.map((m, i) => (
                            <li key={i} className="flex gap-2 py-1">
                                <span className="flex-1">
                                    {m.check}{" "}
                                    <span className="text-xs text-muted">
                                        ({m.problem})
                                    </span>
                                </span>
                                <Badge tone={m.overdue ? "danger" : "neutral"}>
                                    {m.overdue ? "due now" : m.due}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                    {chronicScripts.length > 0 && (
                        <Button
                            className="mt-2"
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.post(
                                    `/prescriptions/${chronicScripts[0]?.id ?? ""}/renew`,
                                )
                            }
                        >
                            Repeat chronic script
                        </Button>
                    )}
                </Card>
                <Card
                    title="Immunisations"
                    aside={
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                act("immunisation", {
                                    vaccine: ask("Vaccine"),
                                    dose: ask("Dose"),
                                    given_on: ask(
                                        "Date given (YYYY-MM-DD)",
                                        new Date().toISOString().slice(0, 10),
                                    ),
                                    batch: ask("Batch"),
                                })
                            }
                        >
                            Record
                        </Button>
                    }
                >
                    <ul className="text-sm">
                        {immunisationsDue.map((d, i) => (
                            <li key={i} className="flex gap-2 py-0.5">
                                <span className="flex-1">
                                    {d.vaccine} ({d.dose})
                                </span>
                                <Badge tone={d.overdue ? "danger" : "warning"}>
                                    {d.overdue ? "overdue" : `due ${d.due}`}
                                </Badge>
                            </li>
                        ))}
                        {immunisations.map((g, i) => (
                            <li key={`g${i}`} className="text-xs text-muted">
                                Given: {g.vaccine} ({g.dose}) on{" "}
                                {String(g.given_on)}
                            </li>
                        ))}
                    </ul>
                </Card>
                <Card
                    title="Pregnancy"
                    aside={
                        !pregnancy && patient.sex === "female" ? (
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => {
                                    const l = ask(
                                        "First day of last period (YYYY-MM-DD)",
                                    );
                                    if (l) act("pregnancy", { lmp: l });
                                }}
                            >
                                Start
                            </Button>
                        ) : undefined
                    }
                >
                    {pregnancy ? (
                        <div className="text-sm">
                            <p>
                                Due {pregnancy.edd} · {pregnancy.weeks} weeks
                            </p>
                            {pregnancy.risks.map((r) => (
                                <Badge key={r} tone="danger">
                                    {r}
                                </Badge>
                            ))}
                            <ul className="mt-2 flex flex-wrap gap-1 text-xs">
                                {pregnancy.contacts.map((c) => (
                                    <li key={c.week}>
                                        <Badge
                                            tone={
                                                c.done ? "success" : "neutral"
                                            }
                                        >
                                            {c.week} wk · {c.date}
                                        </Badge>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : (
                        <p className="text-sm text-muted">
                            No active pregnancy.
                        </p>
                    )}
                </Card>
                <Card
                    title="Chronic medicine registration"
                    aside={
                        patient.medicalAid ? (
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() =>
                                    act("registration", {
                                        icd10_code: ask("Condition ICD-10"),
                                        medicines: ask(
                                            "Medicines (comma separated)",
                                        ),
                                    })
                                }
                            >
                                New
                            </Button>
                        ) : undefined
                    }
                >
                    <ul className="text-sm">
                        {registrations.map((r) => (
                            <li
                                key={String(r.id)}
                                className="flex items-center gap-2 py-1"
                            >
                                <span className="flex-1">
                                    {r.icd10_code} · {r.scheme}
                                </span>
                                <Badge>{String(r.status)}</Badge>
                                {r.status === "draft" && (
                                    <button
                                        className="text-xs text-teal-deep"
                                        onClick={() =>
                                            router.post(
                                                `/chronic-registrations/${r.id}/submit`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        submitted to scheme
                                    </button>
                                )}
                                {r.status === "submitted" && (
                                    <button
                                        className="text-xs text-teal-deep"
                                        onClick={() =>
                                            router.post(
                                                `/chronic-registrations/${r.id}/approve`,
                                                {
                                                    reference:
                                                        ask("Scheme reference"),
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        approved
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
                <Card
                    title="Referrals"
                    aside={
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.post(
                                    `/patients/${patient.id}/referrals`,
                                    {
                                        to_name: ask(
                                            "Refer to (name / practice)",
                                        ),
                                        specialty: ask(
                                            `Specialty: ${specialties.join(", ")}`,
                                            "Other",
                                        ),
                                        urgency: ask(
                                            "Urgency (routine / soon / urgent)",
                                            "routine",
                                        ),
                                        reason: ask("Reason"),
                                        categories: shared,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Refer
                        </Button>
                    }
                >
                    <ul className="text-sm">
                        {referrals.map((r) => (
                            <li key={String(r.id)} className="flex gap-2 py-1">
                                <span className="flex-1">
                                    {r.direction === "out" ? "→" : "←"}{" "}
                                    {r.other_name} ({r.specialty})
                                </span>
                                <Badge>{String(r.status)}</Badge>
                                {r.direction === "out" && (
                                    <a
                                        className="text-xs text-teal-deep"
                                        href={`/referrals/${r.id}/letter`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        letter
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>
            <ConnectedSystems
                title="Connected systems (patient consent)"
                intro="Record what the patient agreed other systems may read (e.g. a signed consent form). Patients can change this themselves in their portal. Consultation notes are never shared."
                systems={connected}
                categories={fhirCategories}
                postUrl={`/patients/${patient.id}/connected`}
                staff
            />
        </AppShell>
    );
}
