import { Head, router, useForm } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

type Range = {
    sex: string | null;
    age_min_months: number;
    age_max_months: number;
    ref_low: string | null;
    ref_high: string | null;
    critical_low: string | null;
    critical_high: string | null;
};
interface Test {
    id: number;
    code: string;
    name: string;
    loinc: string | null;
    sample_type: string;
    result_type: string;
    choices: string[] | null;
    unit: string | null;
    decimals: number;
    plausible_min: string | null;
    plausible_max: string | null;
    turnaround_hours: number;
    home_collection: boolean;
    active: boolean;
    version: number;
    price: number;
    ranges: Range[];
}
interface Props {
    tests: Test[];
    pending: {
        id: number;
        test: string;
        ranges: Range[];
        proposedBy: number;
    }[];
    master: { code: string; name: string }[];
    panels: {
        id: number;
        code: string;
        name: string;
        price_cents: number;
        test_codes: string[];
    }[];
    settings: Record<string, string | number | boolean>;
}
const input = "rounded-md border border-line px-2 py-1 text-sm";
const age = (m: number) =>
    m >= 1500 ? "any" : m >= 12 ? `${Math.floor(m / 12)}y` : `${m}m`;

function TestCard({ t }: { t: Test }) {
    const form = useForm({
        ...t,
        choices: t.choices ?? [],
        plausible_min: t.plausible_min ?? "",
        plausible_max: t.plausible_max ?? "",
        unit: t.unit ?? "",
    });
    const [ranges, setRanges] = useState<Range[]>(t.ranges);
    const set = (i: number, k: keyof Range, v: string) =>
        setRanges(
            ranges.map((r, j) =>
                j === i
                    ? {
                          ...r,
                          [k]:
                              k === "sex"
                                  ? v || null
                                  : k.startsWith("age")
                                    ? Number(v)
                                    : v || null,
                      }
                    : r,
            ),
        );

    return (
        <Card
            title={`${t.name} (${t.code})`}
            aside={<Badge>{`v${t.version}`}</Badge>}
            className="mb-3"
        >
            <div className="grid grid-cols-4 gap-2 text-sm">
                <input
                    aria-label="Name"
                    className={input}
                    value={form.data.name}
                    onChange={(e) => form.setData("name", e.target.value)}
                />
                <input
                    aria-label="Sample type"
                    className={input}
                    value={form.data.sample_type}
                    onChange={(e) =>
                        form.setData("sample_type", e.target.value)
                    }
                />
                <input
                    aria-label="Unit"
                    placeholder="Unit"
                    className={input}
                    value={form.data.unit}
                    onChange={(e) => form.setData("unit", e.target.value)}
                />
                <select
                    aria-label="Result type"
                    className={input}
                    value={form.data.result_type}
                    onChange={(e) =>
                        form.setData("result_type", e.target.value)
                    }
                >
                    <option value="numeric">Number</option>
                    <option value="choice">Choice list</option>
                    <option value="text">Text</option>
                </select>
                <input
                    aria-label="Price"
                    className={input}
                    value={String(form.data.price)}
                    onChange={(e) =>
                        form.setData("price", Number(e.target.value))
                    }
                />
                <input
                    aria-label="Turnaround hours"
                    className={input}
                    value={String(form.data.turnaround_hours)}
                    onChange={(e) =>
                        form.setData("turnaround_hours", Number(e.target.value))
                    }
                />
                <input
                    aria-label="Lowest possible value"
                    placeholder="Lowest possible"
                    className={input}
                    value={form.data.plausible_min}
                    onChange={(e) =>
                        form.setData("plausible_min", e.target.value)
                    }
                />
                <input
                    aria-label="Highest possible value"
                    placeholder="Highest possible"
                    className={input}
                    value={form.data.plausible_max}
                    onChange={(e) =>
                        form.setData("plausible_max", e.target.value)
                    }
                />
            </div>
            <Button
                className="mt-2"
                size="sm"
                variant="secondary"
                onClick={() =>
                    form.put(`/lab/catalogue/${t.id}`, { preserveScroll: true })
                }
            >
                Save test
            </Button>
            {t.result_type === "numeric" && (
                <div className="mt-3">
                    <div className="mb-1 text-xs font-medium">
                        Reference ranges (changes need a second person's
                        approval)
                    </div>
                    {ranges.map((r, i) => (
                        <div
                            key={i}
                            className="mb-1 flex flex-wrap gap-1 text-xs"
                        >
                            <select
                                aria-label="Sex"
                                className={input}
                                value={r.sex ?? ""}
                                onChange={(e) => set(i, "sex", e.target.value)}
                            >
                                <option value="">Any sex</option>
                                <option value="female">Female</option>
                                <option value="male">Male</option>
                            </select>
                            <input
                                aria-label="From age (months)"
                                title={`From ${age(r.age_min_months)}`}
                                className={`${input} w-16`}
                                value={r.age_min_months}
                                onChange={(e) =>
                                    set(i, "age_min_months", e.target.value)
                                }
                            />
                            <input
                                aria-label="To age (months)"
                                title={`To ${age(r.age_max_months)}`}
                                className={`${input} w-16`}
                                value={r.age_max_months}
                                onChange={(e) =>
                                    set(i, "age_max_months", e.target.value)
                                }
                            />
                            {(
                                [
                                    "ref_low",
                                    "ref_high",
                                    "critical_low",
                                    "critical_high",
                                ] as const
                            ).map((k) => (
                                <input
                                    key={k}
                                    aria-label={k.replace("_", " ")}
                                    placeholder={k.replace("_", " ")}
                                    className={`${input} w-20`}
                                    value={r[k] ?? ""}
                                    onChange={(e) => set(i, k, e.target.value)}
                                />
                            ))}
                        </div>
                    ))}
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                setRanges([
                                    ...ranges,
                                    {
                                        sex: null,
                                        age_min_months: 216,
                                        age_max_months: 1500,
                                        ref_low: null,
                                        ref_high: null,
                                        critical_low: null,
                                        critical_high: null,
                                    },
                                ])
                            }
                        >
                            Add range
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.post(
                                    `/lab/catalogue/${t.id}/ranges`,
                                    { ranges },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Propose range change
                        </Button>
                    </div>
                </div>
            )}
        </Card>
    );
}

export default function Catalogue({
    tests,
    pending,
    master,
    panels,
    settings,
}: Props) {
    const [pick, setPick] = useState<string[]>([]);
    const s = useForm({ ...settings });

    return (
        <AppShell active="Lab">
            <Head title="Lab catalogue" />
            <h1 className="mb-1 text-2xl font-semibold">Lab catalogue</h1>
            <p className="mb-5 text-sm text-muted">
                Your test templates: technicians only type values. Ranges are
                demo values until your lab confirms them.
            </p>
            <Flash />
            {pending.length > 0 && (
                <Card
                    title="Range changes waiting for approval"
                    className="mb-4"
                >
                    {pending.map((c) => (
                        <div
                            key={c.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <span className="flex-1">
                                {c.test}: {c.ranges.length} range(s)
                            </span>
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/lab/range-changes/${c.id}/approve`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Approve
                            </Button>
                        </div>
                    ))}
                </Card>
            )}
            <div className="grid grid-cols-3 gap-4">
                <div className="col-span-2">
                    {tests.map((t) => (
                        <TestCard key={t.id} t={t} />
                    ))}
                </div>
                <div>
                    <Card title="Add from the master list" className="mb-4">
                        <div className="max-h-64 overflow-auto text-sm">
                            {master.map((m) => (
                                <label
                                    key={m.code}
                                    className="flex items-center gap-2"
                                >
                                    <input
                                        type="checkbox"
                                        className="accent-teal"
                                        checked={pick.includes(m.code)}
                                        onChange={(e) =>
                                            setPick(
                                                e.target.checked
                                                    ? [...pick, m.code]
                                                    : pick.filter(
                                                          (c) => c !== m.code,
                                                      ),
                                            )
                                        }
                                    />
                                    {m.name}
                                </label>
                            ))}
                        </div>
                        <Button
                            className="mt-2"
                            size="sm"
                            disabled={pick.length === 0}
                            onClick={() =>
                                router.post(
                                    "/lab/catalogue/import",
                                    { codes: pick },
                                    { onSuccess: () => setPick([]) },
                                )
                            }
                        >
                            Copy {pick.length || ""} test(s)
                        </Button>
                    </Card>
                    <Card title="Panels" className="mb-4">
                        <ul className="mb-2 text-sm">
                            {panels.map((p) => (
                                <li key={p.id}>
                                    {p.name} — {p.test_codes.join(", ")}
                                </li>
                            ))}
                        </ul>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                                const name = window.prompt(
                                    "Panel name (e.g. Lipid profile)",
                                );
                                const codes = window.prompt(
                                    "Test codes, comma separated",
                                );
                                const price = window.prompt("Panel price (R)");
                                if (name && codes && price)
                                    router.post("/lab/panels", {
                                        code: name
                                            .slice(0, 12)
                                            .toUpperCase()
                                            .replace(/\W/g, ""),
                                        name,
                                        price,
                                        test_codes: codes
                                            .split(",")
                                            .map((c) => c.trim().toUpperCase()),
                                    });
                            }}
                        >
                            New panel
                        </Button>
                    </Card>
                    <Card title="Collection and release rules">
                        <label className="mb-2 flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="accent-teal"
                                checked={Boolean(s.data.home_collection)}
                                onChange={(e) =>
                                    s.setData(
                                        "home_collection",
                                        e.target.checked,
                                    )
                                }
                            />{" "}
                            Offer home collection
                        </label>
                        {(
                            [
                                ["home_fee", "Home collection fee (R)"],
                                [
                                    "request_after_hours",
                                    "Patient can ask after (hours)",
                                ],
                                [
                                    "auto_release_hours",
                                    "Normal results auto-release after (hours)",
                                ],
                                [
                                    "escalate_hours",
                                    "Escalate abnormal after (hours)",
                                ],
                            ] as [string, string][]
                        ).map(([k, label]) => (
                            <label
                                key={k}
                                className="mb-2 flex items-center gap-2 text-sm"
                            >
                                <span className="flex-1">{label}</span>
                                <input
                                    aria-label={label}
                                    className={`${input} w-20`}
                                    value={String(s.data[k])}
                                    onChange={(e) =>
                                        s.setData(k, e.target.value)
                                    }
                                />
                            </label>
                        ))}
                        <label className="mb-2 flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="accent-teal"
                                checked={Boolean(s.data.auto_release_abnormal)}
                                onChange={(e) =>
                                    s.setData(
                                        "auto_release_abnormal",
                                        e.target.checked,
                                    )
                                }
                            />{" "}
                            Also auto-release abnormal (never critical)
                        </label>
                        <Button
                            size="sm"
                            onClick={() =>
                                s.put("/settings/lab", { preserveScroll: true })
                            }
                        >
                            Save rules
                        </Button>
                    </Card>
                </div>
            </div>
        </AppShell>
    );
}
