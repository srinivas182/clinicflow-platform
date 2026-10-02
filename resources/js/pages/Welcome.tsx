import { Head, usePage } from '@inertiajs/react';
import { Database, MapPin, ShieldCheck, Smartphone } from 'lucide-react';
import { Logo } from '@/components/Logo';
import { Badge, Button, Card, Kpi, Ticket, TriageDot } from '@/components/ui';
import type { SharedProps } from '@/types';

interface WelcomeProps extends SharedProps {
    region: string;
}

/**
 * Sprint 0 platform home: confirms the foundations and shows the design system.
 * Replaced by the public website in Sprint 8.
 */
export default function Welcome() {
    const { app, region } = usePage<WelcomeProps>().props;

    return (
        <>
            <Head title="Platform" />
            <div className="mx-auto max-w-6xl px-6 py-10">
                <header className="flex items-center">
                    <Logo />
                    <span className="ml-auto text-sm text-muted">v{app.version}</span>
                </header>

                <section className="mt-12 max-w-2xl">
                    <Badge tone="teal" icon={<MapPin className="size-3.5" aria-hidden="true" />}>
                        Hosted in South Africa · {region}
                    </Badge>
                    <h1 className="mt-5 text-4xl leading-tight font-semibold tracking-tight">
                        Your clinic, the pharmacy and the lab — finally on one line.
                    </h1>
                    <p className="mt-4 text-lg text-muted">
                        Platform foundations are in place: one database per provider, a versioned API for the mobile apps and the shared design
                        system.
                    </p>
                </section>

                <div className="mt-10 flex gap-4">
                    <Kpi label="Tenancy" value="Database per provider" hint="Isolated by design" />
                    <Kpi label="API" value="/api/v1" hint="Health check live" trend="up" />
                    <Kpi label="Web and mobile" value="One back end" hint="React web · Flutter apps" />
                </div>

                <div className="mt-6 grid grid-cols-2 gap-4">
                    <Card title="Design system" aside="IBM Plex Sans">
                        <div className="flex flex-wrap gap-2">
                            <Button>Primary</Button>
                            <Button variant="secondary">Secondary</Button>
                            <Button variant="network">Network</Button>
                            <Button variant="danger">Stop</Button>
                        </div>
                        <div className="mt-4 flex flex-wrap gap-1.5">
                            <Badge tone="success">Ready</Badge>
                            <Badge tone="teal">Waiting</Badge>
                            <Badge tone="network">Video</Badge>
                            <Badge tone="warning">Owing</Badge>
                            <Badge tone="danger">Allergy</Badge>
                        </div>
                        <div className="mt-5 flex items-center gap-5">
                            <Ticket number="A023" />
                            <TriageDot colour="red" showLabel />
                            <TriageDot colour="green" showLabel />
                        </div>
                    </Card>
                    <Card title="Foundations" aside="Sprint 0">
                        <ul className="space-y-3 text-sm">
                            <li className="flex gap-2.5">
                                <Database className="size-4 flex-none text-teal" aria-hidden="true" />
                                Platform, Network Hub and per-provider databases
                            </li>
                            <li className="flex gap-2.5">
                                <ShieldCheck className="size-4 flex-none text-teal" aria-hidden="true" />
                                Quality gates: Pint, PHPStan level 8, Pest, Vitest
                            </li>
                            <li className="flex gap-2.5">
                                <Smartphone className="size-4 flex-none text-teal" aria-hidden="true" />
                                REST API v1 ready for the Flutter apps
                            </li>
                        </ul>
                    </Card>
                </div>
            </div>
        </>
    );
}
