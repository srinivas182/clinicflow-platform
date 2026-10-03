import { Link } from '@inertiajs/react';
import { BadgeCheck, Building2, CreditCard, Globe, Layers, MessageSquare, RefreshCw, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/Logo';

const nav = [
    { label: 'Providers', icon: Building2, href: '/admin/providers' },
    { label: 'Verification', icon: BadgeCheck, href: '/admin/providers' },
    { label: 'Packages', icon: Layers, href: '/admin/packages' },
    { label: 'Auto-debit', icon: RefreshCw, href: '/admin/auto-debits' },
    { label: 'Payments', icon: CreditCard, href: '/admin/payments' },
    { label: 'Wallet', icon: Wallet, href: '/admin/wallet' },
    { label: 'Messaging', icon: MessageSquare, href: '/admin/messaging' },
    { label: 'Website', icon: Globe, href: '/admin/pages' },
];

/**
 * Platform console shell (super admin), on the central domain.
 */
export function AdminShell({ children, active }: { children: ReactNode; active: string }) {
    return (
        <div className="flex min-h-screen">
            <aside className="w-60 flex-none bg-[#1B1640] px-3.5 py-5 text-[#C8D3D7]">
                <div className="px-1.5 pb-5">
                    <Logo tone="light" />
                    <div className="mt-1 text-xs text-[#B9AEF0]">Platform admin</div>
                </div>
                <nav aria-label="Admin">
                    {nav.map(({ label, icon: Icon, href }) => (
                        <Link
                            key={label}
                            href={href}
                            aria-current={label === active ? 'page' : undefined}
                            className={`mb-0.5 flex items-center gap-3 rounded-md px-2.5 py-2 text-sm ${label === active ? 'bg-white/10 font-medium text-white' : 'hover:bg-white/5'}`}
                        >
                            <Icon className="size-4" aria-hidden="true" />
                            {label}
                        </Link>
                    ))}
                </nav>
            </aside>
            <main className="flex-1 bg-paper px-8 py-7">{children}</main>
        </div>
    );
}
