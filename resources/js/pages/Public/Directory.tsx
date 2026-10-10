import { router } from "@inertiajs/react";
import { useState } from "react";
import { SiteLayout } from "@/components/site/SiteLayout";
import type { SiteInfo } from "@/components/site/types";
import { Badge, Button } from "@/components/ui";

export default function Directory({
    providers,
    q,
    type,
    site,
}: {
    providers: { name: string; type: string; address: string | null }[];
    q: string;
    type: string;
    site: SiteInfo;
}) {
    const [term, setTerm] = useState(q);
    const [kind, setKind] = useState(type);

    return (
        <SiteLayout
            site={site}
            title="Find Care"
            description="Verified clinics, doctors, pharmacies and labs on Dr Business Flow."
        >
            <div className="mx-auto max-w-4xl px-6 py-10">
                <h1 className="text-3xl font-semibold">Find Care</h1>
                <p className="mt-1 text-muted">
                    Verified clinics, doctors, pharmacies and labs on Clinic
                    Flow.
                </p>
                <div className="mt-6 flex gap-2">
                    <input
                        aria-label="Search"
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        placeholder="Practice name"
                        className="flex-1 rounded-lg border border-line px-3 py-2"
                    />
                    <select
                        aria-label="Type"
                        value={kind}
                        onChange={(e) => setKind(e.target.value)}
                        className="rounded-lg border border-line px-3 py-2"
                    >
                        <option value="">All</option>
                        <option value="clinic">Clinics</option>
                        <option value="independent_doctor">Doctors</option>
                        <option value="pharmacy">Pharmacies</option>
                        <option value="lab">Labs</option>
                    </select>
                    <Button
                        onClick={() =>
                            router.get("/find-care", { q: term, type: kind })
                        }
                    >
                        Search
                    </Button>
                </div>
                <ul className="mt-6 divide-y divide-line-soft">
                    {providers.map((p) => (
                        <li
                            key={p.name}
                            className="flex items-center gap-3 py-3"
                        >
                            <span className="flex-1 font-medium">{p.name}</span>
                            <Badge tone="teal">{p.type}</Badge>
                            {p.address && (
                                <a
                                    href={`https://${p.address}/my/login`}
                                    className="text-sm text-teal-deep"
                                >
                                    Patient sign-in
                                </a>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </SiteLayout>
    );
}
