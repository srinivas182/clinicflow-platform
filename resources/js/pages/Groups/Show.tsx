import { Head, router } from "@inertiajs/react";
import { Card } from "@/components/ui";
import { rand } from "@/lib/money";

interface Props {
    group: { id: number; name: string; billing: string };
    period: { from: string; to: string };
    practices: {
        id: string;
        name: string;
        visits: number;
        appointments: number;
        new_patients: number;
        takings: number;
        owed: number;
    }[];
}

export default function GroupShow({ group, period, practices }: Props) {
    const total = (
        k: "visits" | "appointments" | "new_patients" | "takings" | "owed",
    ) => practices.reduce((s, p) => s + p[k], 0);

    return (
        <div className="mx-auto max-w-5xl px-6 py-8">
            <Head title={group.name} />
            <h1 className="mb-1 text-2xl font-semibold">{group.name}</h1>
            <p className="mb-4 text-sm text-muted">
                Totals only — patient records stay inside each practice.
            </p>
            <div className="mb-4 flex gap-2 text-sm">
                <input
                    aria-label="From"
                    type="date"
                    defaultValue={period.from}
                    id="gf"
                    className="rounded-md border border-line px-2 py-1"
                />
                <input
                    aria-label="To"
                    type="date"
                    defaultValue={period.to}
                    id="gt"
                    className="rounded-md border border-line px-2 py-1"
                />
                <button
                    className="text-teal-deep"
                    onClick={() =>
                        router.get(`/groups/${group.id}`, {
                            from: (
                                document.getElementById(
                                    "gf",
                                ) as HTMLInputElement
                            ).value,
                            to: (
                                document.getElementById(
                                    "gt",
                                ) as HTMLInputElement
                            ).value,
                        })
                    }
                >
                    Show
                </button>
            </div>
            <Card>
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs text-muted">
                            <th>Practice</th>
                            <th>Visits</th>
                            <th>Appointments</th>
                            <th>New patients</th>
                            <th className="text-right">Takings</th>
                            <th className="text-right">Owed</th>
                        </tr>
                    </thead>
                    <tbody>
                        {practices.map((p) => (
                            <tr
                                key={p.id}
                                className="border-t border-[#EBF0EE]"
                            >
                                <td className="py-1.5">{p.name}</td>
                                <td>{p.visits}</td>
                                <td>{p.appointments}</td>
                                <td>{p.new_patients}</td>
                                <td className="text-right">
                                    {rand(p.takings, 2)}
                                </td>
                                <td className="text-right">
                                    {rand(p.owed, 2)}
                                </td>
                            </tr>
                        ))}
                        <tr className="border-t-2 border-line font-semibold">
                            <td className="py-1.5">Group</td>
                            <td>{total("visits")}</td>
                            <td>{total("appointments")}</td>
                            <td>{total("new_patients")}</td>
                            <td className="text-right">
                                {rand(total("takings"), 2)}
                            </td>
                            <td className="text-right">
                                {rand(total("owed"), 2)}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>
    );
}
