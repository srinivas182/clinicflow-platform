import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";
import { Logo } from "@/components/Logo";
import { Badge, Button } from "@/components/ui";

export default function Directory({
    providers,
    q,
    type,
}: {
    providers: { name: string; type: string; address: string | null }[];
    q: string;
    type: string;
}) {
    const [term, setTerm] = useState(q);
    const [kind, setKind] = useState(type);

    return (
        <div className="min-h-screen bg-surface">
            <Head title="Find care" />
            <header className="flex items-center gap-6 border-b border-line-soft px-10 py-4 text-sm">
                <Link href="/">
                    <Logo />
                </Link>
                <Link href="/pricing">Pricing</Link>
            </header>
            <main className="mx-auto max-w-4xl px-6 py-10">
                <h1 className="text-3xl font-semibold">Find care</h1>
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
            </main>
        </div>
    );
}
