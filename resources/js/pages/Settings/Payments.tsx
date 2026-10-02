import { Head, Link } from '@inertiajs/react';
import { Flash } from '@/components/Flash';
import { GatewayCard, type GatewayRow } from '@/components/payments/GatewayCard';
import { AppShell } from '@/layouts/AppShell';

/**
 * The practice's own merchant accounts. Patient money goes straight to the practice.
 */
export default function PaymentSettings({ gateways, action }: { gateways: GatewayRow[]; action: string }) {
    return (
        <AppShell active="Settings">
            <Head title="Payments" />
            <div className="mb-1 flex items-end gap-3">
                <h1 className="text-2xl font-semibold">Payments</h1>
                <nav className="ml-auto flex gap-4 text-sm text-teal-deep">
                    <Link href="/settings/billing">Billing rules</Link>
                    <Link href="/settings/subscription">Subscription</Link>
                    <Link href="/settings/templates">Templates</Link>
                </nav>
            </div>
            <p className="mb-5 text-sm text-muted">
                Connect your own PayFast, Paystack, Peach Payments or Yoco account. Patients pay straight into your account — Clinic Flow never holds patient money. Start in test
                mode, then switch to live.
            </p>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                {gateways.map((g) => (
                    <GatewayCard key={g.gateway} row={g} base={action} />
                ))}
            </div>
        </AppShell>
    );
}
