import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { PortalLayout } from "@/layouts/PortalLayout";

interface Props {
    providerName: string;
    log: { kind: string; summary: string; created_at: string }[];
    referrals: {
        to: string;
        specialty: string;
        status: string;
        appointment: string | null;
        outcome: string | null;
    }[];
    messages: { from: string; at: string; body: string }[];
    immunisations: {
        due: { vaccine: string; dose: string; due: string; overdue: boolean }[];
        given: { vaccine: string; dose: string; given_on: string }[];
    };
    pregnancy: {
        edd: string;
        weeks: number;
        contacts: { week: number; date: string; done: boolean }[];
        risks: string[];
    } | null;
    sharing: string[];
    categories: string[];
}

export default function PortalCare({
    providerName,
    log,
    referrals,
    messages,
    immunisations,
    pregnancy,
    sharing,
    categories,
}: Props) {
    const [chosen, setChosen] = useState<string[]>(sharing);

    return (
        <PortalLayout provider={providerName}>
            <Head title="My care" />
            <h1 className="mb-5 text-2xl font-semibold">My care</h1>
            <Flash />
            <Card
                title="What this practice may see from your network history"
                className="mb-4"
            >
                <div className="flex flex-wrap gap-3 text-sm">
                    {categories.map((c) => (
                        <label key={c} className="flex items-center gap-1">
                            <input
                                type="checkbox"
                                className="accent-teal"
                                checked={chosen.includes(c)}
                                onChange={(e) =>
                                    setChosen(
                                        e.target.checked
                                            ? [...chosen, c]
                                            : chosen.filter((x) => x !== c),
                                    )
                                }
                            />{" "}
                            {c}
                        </label>
                    ))}
                </div>
                <Button
                    className="mt-2"
                    size="sm"
                    onClick={() =>
                        router.post(
                            "/my/care/sharing",
                            { categories: chosen },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save
                </Button>
            </Card>
            <Card
                title="Who discussed your care and what was shared"
                className="mb-4"
            >
                <ul className="text-sm">
                    {log.length === 0 && (
                        <li className="text-muted">Nothing yet.</li>
                    )}
                    {log.map((l, i) => (
                        <li key={i} className="py-1">
                            <span className="text-xs text-muted">
                                {l.created_at.slice(0, 16)}
                            </span>{" "}
                            {l.summary}
                        </li>
                    ))}
                </ul>
            </Card>
            {referrals.length > 0 && (
                <Card title="Referrals" className="mb-4">
                    {referrals.map((r, i) => (
                        <p key={i} className="text-sm">
                            {r.to} ({r.specialty}) — <Badge>{r.status}</Badge>{" "}
                            {r.appointment}{" "}
                            {r.outcome && (
                                <span className="block text-xs">
                                    Outcome: {r.outcome}
                                </span>
                            )}
                        </p>
                    ))}
                </Card>
            )}
            {messages.length > 0 && (
                <Card title="Notes from your clinicians" className="mb-4">
                    {messages.map((m, i) => (
                        <p key={i} className="mb-2 text-sm">
                            <span className="text-xs text-muted">
                                {m.from} · {m.at}
                            </span>
                            <br />
                            {m.body}
                        </p>
                    ))}
                </Card>
            )}
            {(immunisations.due.length > 0 ||
                immunisations.given.length > 0) && (
                <Card title="Immunisations" className="mb-4">
                    {immunisations.due.map((d, i) => (
                        <p key={i} className="text-sm">
                            {d.vaccine} ({d.dose}) —{" "}
                            <Badge tone={d.overdue ? "danger" : "warning"}>
                                {d.overdue ? "overdue" : `due ${d.due}`}
                            </Badge>
                        </p>
                    ))}
                    {immunisations.given.map((g, i) => (
                        <p key={`g${i}`} className="text-xs text-muted">
                            Given {g.vaccine} ({g.dose}) on {g.given_on}
                        </p>
                    ))}
                </Card>
            )}
            {pregnancy && (
                <Card title={`Pregnancy · due ${pregnancy.edd}`}>
                    <div className="flex flex-wrap gap-1 text-xs">
                        {pregnancy.contacts.map((c) => (
                            <Badge
                                key={c.week}
                                tone={c.done ? "success" : "neutral"}
                            >
                                {c.week} weeks · {c.date}
                            </Badge>
                        ))}
                    </div>
                </Card>
            )}
        </PortalLayout>
    );
}
