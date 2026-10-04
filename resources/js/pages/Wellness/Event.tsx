import { Head, router } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Props {
    event: {
        id: number;
        title: string;
        location: string;
        starts: string;
        services: string[];
    };
    registrations: {
        id: number;
        slot: string;
        name: string;
        status: string;
        flags: string[];
    }[];
}
const ask = (l: string) => {
    const v = window.prompt(l);
    return v === null || v === "" ? null : v;
};

export default function WellnessEvent({ event, registrations }: Props) {
    const has = (s: string) => event.services.includes(s);
    const capture = (id: number) =>
        router.post(
            `/corporate-wellness/registrations/${id}/screen`,
            {
                bp_systolic: has("bp") ? ask("BP systolic") : null,
                bp_diastolic: has("bp") ? ask("BP diastolic") : null,
                glucose: has("glucose") ? ask("Glucose (mmol/L)") : null,
                cholesterol: has("cholesterol")
                    ? ask("Total cholesterol (mmol/L)")
                    : null,
                height_cm: has("bmi") ? ask("Height (cm)") : null,
                weight_kg: has("bmi") ? ask("Weight (kg)") : null,
                flu_vaccinated: has("flu")
                    ? window.confirm("Flu vaccine given?")
                    : false,
            },
            { preserveScroll: true },
        );

    return (
        <AppShell active="Wellness">
            <Head title={event.title} />
            <h1 className="mb-1 text-2xl font-semibold">{event.title}</h1>
            <p className="mb-5 text-sm text-muted">
                {event.location} · {event.starts.slice(0, 16)} · results are
                sent to each employee only.
            </p>
            <Flash />
            <Card>
                {registrations.map((r) => (
                    <div
                        key={r.id}
                        className="flex items-center gap-2 border-t border-[#EBF0EE] py-2 text-sm first:border-0"
                    >
                        <span className="w-14 font-mono">{r.slot}</span>
                        <span className="flex-1">{r.name}</span>
                        {r.flags.map((f) => (
                            <Badge key={f} tone="warning">
                                {f.replace("_", " ")}
                            </Badge>
                        ))}
                        <Badge
                            tone={
                                r.status === "screened" ? "success" : "neutral"
                            }
                        >
                            {r.status}
                        </Badge>
                        <Button
                            size="sm"
                            variant={
                                r.status === "screened" ? "ghost" : "primary"
                            }
                            onClick={() => capture(r.id)}
                        >
                            {r.status === "screened" ? "Edit" : "Record"}
                        </Button>
                    </div>
                ))}
            </Card>
        </AppShell>
    );
}
