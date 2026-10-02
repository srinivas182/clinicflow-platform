import { Head, Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { Logo } from '@/components/Logo';
import { Button } from '@/components/ui';
import { rand } from '@/lib/money';

export interface PackageOption {
    id: number;
    code: string;
    name: string;
    providerType: string;
    summary: string | null;
    priceMonthly: number;
    priceAnnual: number;
    trialDays: number;
    features: string[];
}

const types = [
    { value: 'clinic', label: 'Clinic' },
    { value: 'independent_doctor', label: 'Independent doctor' },
    { value: 'pharmacy', label: 'Pharmacy' },
    { value: 'lab', label: 'Lab' },
];

export const featureLabel = (f: string) => f.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

export default function Pricing({ packages }: { packages: PackageOption[] }) {
    const [type, setType] = useState('clinic');
    const [annual, setAnnual] = useState(false);
    const shown = packages.filter((p) => p.providerType === type);

    return (
        <div className="min-h-screen bg-white">
            <Head title="Pricing" />
            <header className="flex items-center border-b border-[#EBF0EE] px-10 py-4">
                <Link href="/">
                    <Logo />
                </Link>
                <Link href="/login" className="ml-auto text-sm">
                    Sign in
                </Link>
            </header>
            <main className="mx-auto max-w-6xl px-6 py-12">
                <h1 className="text-4xl font-semibold tracking-tight">Pricing that grows with your practice</h1>
                <p className="mt-2 text-lg text-muted">Patients pay you directly. You pay one subscription, plus add-ons you switch on.</p>
                <div className="mt-6 flex gap-3">
                    <div role="tablist" className="inline-flex rounded-lg bg-[#EBF0EE] p-1">
                        {types.map((t) => (
                            <button
                                key={t.value}
                                type="button"
                                role="tab"
                                aria-selected={type === t.value}
                                onClick={() => setType(t.value)}
                                className={`rounded-md px-3 py-1.5 text-sm ${type === t.value ? 'bg-white font-medium shadow-sm' : 'text-muted'}`}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={annual} onChange={(e) => setAnnual(e.target.checked)} className="accent-teal" />
                        Annual billing (save 15%)
                    </label>
                </div>
                <div className="mt-8 grid grid-cols-3 gap-5">
                    {shown.map((p) => (
                        <section key={p.id} className="rounded-2xl border border-line p-6">
                            <h2 className="text-lg font-semibold">{p.name}</h2>
                            <p className="text-sm text-muted">{p.summary}</p>
                            <p className="mt-4">
                                <span className="text-3xl font-semibold">{rand(annual ? p.priceAnnual / 12 : p.priceMonthly)}</span>
                                <span className="text-muted"> / month</span>
                            </p>
                            <p className="text-xs text-muted">Excl. VAT · {p.trialDays}-day free trial</p>
                            <Link href={`/start?package=${p.id}`} className="mt-4 inline-block">
                                <Button>Start free trial</Button>
                            </Link>
                            <ul className="mt-5 space-y-1.5 text-sm">
                                {p.features.map((f) => (
                                    <li key={f} className="flex gap-2">
                                        <Check className="size-4 text-teal" aria-hidden="true" />
                                        {featureLabel(f)}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            </main>
        </div>
    );
}
