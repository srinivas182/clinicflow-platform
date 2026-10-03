import { Link, usePage } from '@inertiajs/react';
import { Activity, Bell, BookOpen, CalendarDays, ChartColumn, CircleHelp, ClipboardList, Clock, FileCheck, FileSignature, FileText, FlaskConical, Forward, Globe, Inbox, LayoutDashboard, Mail, MessagesSquare, Pill, Search, Settings, Share2, ShieldAlert, ShieldCheck, Stethoscope, Users, Video, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/Logo';
import type { SharedProps } from '@/types';

const nav = [
    { label: 'Overview', icon: LayoutDashboard, href: '/workspace' },
    { label: 'Front desk', icon: ClipboardList, href: '/front-desk' },
    { label: 'Triage', icon: Activity, href: '/triage' },
    { label: 'My queue', icon: Stethoscope, href: '/doctor' },
    { label: 'Patients', icon: Users, href: '/patients' },
    { label: 'Appointments', icon: CalendarDays, href: '/appointments' },
    { label: 'Rosters', icon: Clock, href: '/rosters' },
    { label: 'Templates', icon: FileText, href: '/settings/templates' },
    { label: 'Pharmacy', icon: Pill, href: '/pharmacy' },
    { label: 'Lab', icon: FlaskConical, href: '/lab' },
    { label: 'Lab catalogue', icon: BookOpen, href: '/lab/catalogue' },
    { label: 'Results', icon: Inbox, href: '/results' },
    { label: 'Finance', icon: ChartColumn, href: '/finance' },
    { label: 'Network', icon: Share2, href: '/network' },
    { label: 'Online consults', icon: Video, href: '/telemedicine' },
    { label: 'Chats', icon: MessagesSquare, href: '/chats' },
    { label: 'Messages', icon: Mail, href: '/messages' },
    { label: 'Referrals', icon: Forward, href: '/referrals' },
    { label: 'Compliance', icon: ShieldAlert, href: '/compliance/break-glass' },
    { label: 'E-scripts', icon: FileSignature, href: '/escripts' },
    { label: 'Wallet', icon: Wallet, href: '/settings/wallet' },
    { label: 'Claims', icon: FileCheck, href: '/claims' },
    { label: 'Audit', icon: ShieldCheck, href: '/compliance/audit' },
    { label: 'Website', icon: Globe, href: '/settings/website' },
    { label: 'Settings', icon: Settings, href: '/settings/billing' },
];

/**
 * Provider workspace shell: dark sidebar with workspace switcher, top bar, content.
 * Navigation items become role-based from Sprint 1.
 */
export function AppShell({ children, active = 'Overview' }: { children: ReactNode; active?: string }) {
    const { provider } = usePage<SharedProps>().props;
    const initials = (provider?.name ?? 'CF')
        .split(' ')
        .map((word) => word[0])
        .slice(0, 2)
        .join('');

    return (
        <div className="flex min-h-screen">
            <aside className="flex w-60 flex-none flex-col bg-ink px-3.5 py-5 text-[#C8D3D7]">
                <div className="px-1.5 pb-4">
                    <Logo tone="light" />
                </div>
                <div className="mb-4 flex items-center gap-2.5 rounded-lg bg-ink-2 px-3 py-2.5">
                    <span className="grid size-8 place-items-center rounded-md bg-[#2D5161] text-xs font-semibold text-white">{initials}</span>
                    <div className="leading-tight">
                        <div className="text-sm font-medium text-white">{provider?.name ?? 'Clinic Flow'}</div>
                        <div className="text-xs text-[#9FB2B9]">{provider?.typeLabel ?? 'Platform'}</div>
                    </div>
                </div>
                <nav aria-label="Main">
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
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-15 items-center gap-3 border-b border-line bg-white px-6">
                    <label className="flex w-96 items-center gap-2 rounded-lg border border-line bg-paper px-3 py-2 text-sm text-muted">
                        <Search className="size-4" aria-hidden="true" />
                        <span className="sr-only">Search</span>
                        <input className="w-full bg-transparent outline-none" placeholder="Search patients, scripts, invoices" />
                    </label>
                    <div className="ml-auto flex gap-1 text-muted">
                        <button type="button" aria-label="Help" className="grid size-9 place-items-center rounded-lg hover:bg-paper">
                            <CircleHelp className="size-4.5" />
                        </button>
                        <button type="button" aria-label="Notifications" className="grid size-9 place-items-center rounded-lg hover:bg-paper">
                            <Bell className="size-4.5" />
                        </button>
                    </div>
                </header>
                <main className="flex-1 px-6 py-6">{children}</main>
            </div>
        </div>
    );
}
