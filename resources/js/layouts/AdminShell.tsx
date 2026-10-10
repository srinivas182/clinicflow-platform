import { useEffect, useState } from "react";
import { ThemeToggle } from "@/components/ThemeToggle";
import { Link, router } from "@inertiajs/react";
import {
    Activity,
    BadgeCheck,
    BookText,
    Building2,
    CalendarDays,
    CreditCard,
    Globe,
    Handshake,
    HardDrive,
    Layers,
    LifeBuoy,
    Menu,
    MessageCircle,
    MessageSquare,
    MessageSquareWarning,
    Network,
    Palette,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    Stethoscope,
    Truck,
    Video,
    Wallet,
    X,
} from "lucide-react";
import type { ReactNode } from "react";
import { Logo } from "@/components/Logo";

const nav = [
    { label: "Providers", icon: Building2, href: "/admin/providers" },
    { label: "Verification", icon: BadgeCheck, href: "/admin/providers" },
    { label: "Packages", icon: Layers, href: "/admin/packages" },
    { label: "Auto-debit", icon: RefreshCw, href: "/admin/auto-debits" },
    { label: "Payments", icon: CreditCard, href: "/admin/payments" },
    { label: "Wallet", icon: Wallet, href: "/admin/wallet" },
    { label: "Telemedicine", icon: Video, href: "/admin/telemedicine" },
    { label: "Accounting", icon: BookText, href: "/admin/accounting" },
    { label: "Groups", icon: Network, href: "/admin/groups" },
    { label: "Calendars", icon: CalendarDays, href: "/admin/calendars" },
    { label: "Reviews", icon: MessageSquareWarning, href: "/admin/reviews" },
    { label: "Resellers", icon: Handshake, href: "/admin/resellers" },
    { label: "Locums", icon: Stethoscope, href: "/admin/locums" },
    { label: "AI scribe", icon: Sparkles, href: "/admin/ai-scribe" },
    { label: "Brands", icon: Palette, href: "/admin/brands" },
    { label: "Storage", icon: HardDrive, href: "/admin/storage" },
    { label: "Security", icon: ShieldCheck, href: "/admin/security" },
    { label: "Support", icon: LifeBuoy, href: "/admin/support" },
    { label: "Status page", icon: Activity, href: "/admin/status" },
    { label: "WhatsApp", icon: MessageCircle, href: "/admin/whatsapp" },
    { label: "Couriers", icon: Truck, href: "/admin/couriers" },
    { label: "Messaging", icon: MessageSquare, href: "/admin/messaging" },
    { label: "Website", icon: Globe, href: "/admin/pages" },
];

/**
 * Platform console shell (super admin), on the central domain.
 */
export function AdminShell({
    children,
    active,
}: {
    children: ReactNode;
    active: string;
}) {
    const [menuOpen, setMenuOpen] = useState(false);
    // Close the mobile menu after moving to another page.
    useEffect(() => router.on("navigate", () => setMenuOpen(false)), []);

    return (
        <div className="flex min-h-screen">
            <button
                type="button"
                className="fixed right-3 top-3 z-30 rounded-md bg-chrome p-2 text-white shadow md:hidden"
                aria-label="Open menu"
                aria-expanded={menuOpen}
                aria-controls="app-menu"
                onClick={() => setMenuOpen(true)}
            >
                <Menu size={18} aria-hidden="true" />
            </button>
            {menuOpen && (
                <div
                    className="fixed inset-0 z-30 bg-black/40 md:hidden"
                    aria-hidden="true"
                    onClick={() => setMenuOpen(false)}
                />
            )}
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-md focus:bg-surface focus:px-3 focus:py-2 focus:text-ink focus:shadow"
            >
                Skip to main content
            </a>
            <aside
                id="app-menu"
                className={`fixed inset-y-0 left-0 z-40 flex w-60 flex-none flex-col overflow-y-auto bg-[#1B1640] px-3.5 py-5 text-chrome-muted transition-transform md:static md:translate-x-0 ${menuOpen ? "translate-x-0" : "-translate-x-full"}`}
            >
                <button
                    type="button"
                    className="mb-2 self-end rounded p-1 text-chrome-muted hover:text-white md:hidden"
                    aria-label="Close menu"
                    onClick={() => setMenuOpen(false)}
                >
                    <X size={18} aria-hidden="true" />
                </button>
                <div className="px-1.5 pb-5">
                    <Logo tone="light" />
                    <div className="mt-1 text-xs text-[#B9AEF0]">
                        Platform admin
                    </div>
                </div>
                <nav aria-label="Admin">
                    {nav.map(({ label, icon: Icon, href }) => (
                        <Link
                            key={label}
                            href={href}
                            aria-current={label === active ? "page" : undefined}
                            className={`mb-0.5 flex items-center gap-3 rounded-md px-2.5 py-2 text-sm ${label === active ? "bg-white/10 font-medium text-white" : "hover:bg-white/5"}`}
                        >
                            <Icon className="size-4" aria-hidden="true" />
                            {label}
                        </Link>
                    ))}
                </nav>
                <ThemeToggle className="mt-3 self-start" />
            </aside>
            <main
                id="main-content"
                tabIndex={-1}
                className="max-md:pt-16 flex-1 bg-paper px-8 py-7"
            >
                {children}
            </main>
        </div>
    );
}
