import { Head } from "@inertiajs/react";
import { Badge, Card } from "@/components/ui";
import { rand } from "@/lib/money";
import type { Period } from "../Admin/Resellers";

interface Props {
    reseller: {
        name: string;
        code: string;
        percent: number;
        months: number;
        link: string;
    };
    referrals: { practice: string; referred: string }[];
    periods: Period[];
    brands?: {
        name: string;
        signupLink: string;
        signupsThisMonth: number;
        aiMinutesThisMonth: number;
        practices: { name: string; status: string; since: string }[];
    }[];
}

export default function ResellerPortal({
    reseller,
    referrals,
    periods,
    brands = [],
}: Props) {
    return (
        <div className="mx-auto max-w-3xl px-6 py-8">
            <Head title="Reseller portal" />
            <h1 className="mb-1 text-2xl font-semibold">{reseller.name}</h1>
            <p className="mb-5 text-sm text-muted">
                You earn {reseller.percent}% of each practice's subscription
                (excl. VAT) for {reseller.months} months after they sign up with
                your link. Paid monthly by EFT.
            </p>
            <Card title="Your sign-up link" className="mb-4">
                <p className="font-mono text-sm break-all">{reseller.link}</p>
            </Card>
            <Card
                title={`Practices you referred (${referrals.length})`}
                className="mb-4"
            >
                {referrals.map((r, i) => (
                    <p key={i} className="text-sm">
                        {r.practice} · {r.referred}
                    </p>
                ))}
            </Card>
            <Card title="Monthly statements">
                {periods.length === 0 && (
                    <p className="text-sm text-muted">No commission yet.</p>
                )}
                {periods.map((m) => (
                    <p
                        key={m.period}
                        className="flex items-center gap-2 text-sm"
                    >
                        {m.period} · {rand(m.amount / 100, 2)}{" "}
                        <Badge tone={m.paid ? "success" : "warning"}>
                            {m.paid ? `paid · ${m.reference ?? ""}` : "due"}
                        </Badge>
                    </p>
                ))}
            </Card>
            {brands.map((b) => (
                <Card
                    key={b.name}
                    title={`Your brand: ${b.name}`}
                    className="mt-4"
                >
                    <p className="mb-2 text-sm">
                        Sign-up link:{" "}
                        <span className="font-mono text-xs">
                            {b.signupLink}
                        </span>
                    </p>
                    <p className="mb-2 text-sm">
                        {b.practices.length} practices · {b.signupsThisMonth}{" "}
                        signed up this month · {b.aiMinutesThisMonth} AI minutes
                        this month
                    </p>
                    {b.practices.map((p, i) => (
                        <p key={i} className="text-sm">
                            {p.name} · {p.status} · since {p.since}
                        </p>
                    ))}
                </Card>
            ))}
        </div>
    );
}
