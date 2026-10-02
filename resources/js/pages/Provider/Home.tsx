import { Head, usePage } from '@inertiajs/react';
import { Card, Kpi } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';
import type { SharedProps } from '@/types';

/**
 * Provider workspace home, served on the provider's own address.
 */
export default function ProviderHome() {
    const { provider } = usePage<SharedProps>().props;

    return (
        <AppShell>
            <Head title={provider?.name ?? 'Provider'} />
            <h1 className="text-2xl font-semibold">Welcome to {provider?.name}</h1>
            <p className="mt-1 text-sm text-muted">{provider?.typeLabel} workspace · your data lives in its own database</p>
            <div className="mt-6 flex gap-4">
                <Kpi label="Patients today" value="—" hint="Front desk arrives in Sprint 3" />
                <Kpi label="Takings today" value="—" hint="Billing arrives in Sprint 3" />
                <Kpi label="Waiting now" value="—" hint="Queue arrives in Sprint 3" />
            </div>
            <Card title="Getting started" className="mt-6">
                <p className="text-sm text-muted">Staff, roles and settings are set up in Sprint 1.</p>
            </Card>
        </AppShell>
    );
}
