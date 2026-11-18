import { ResponsiveTables } from "@/components/ResponsiveTables";
import { DeveloperCredit } from "@/components/DeveloperCredit";
import { AccountMenu } from "@/components/AccountMenu";
import { useEffect, useState } from "react";
import { ThemeToggle } from "@/components/ThemeToggle";
import { Link, router, usePage } from "@inertiajs/react";
import {
    Activity,
    BarChart3,
    Bell,
    BookOpen,
    BookText,
    Building2,
    CalendarDays,
    ChartColumn,
    CircleHelp,
    ClipboardList,
    Clock,
    FileCheck,
    FileQuestion,
    FileSignature,
    FileText,
    FlaskConical,
    Forward,
    Globe,
    HandCoins,
    HeartPulse,
    Image,
    Inbox,
    KeyRound,
    LayoutDashboard,
    LifeBuoy,
    Mail,
    Menu,
    MessageCircle,
    MessagesSquare,
    PackageSearch,
    Percent,
    Pill,
    Search,
    Settings,
    Share2,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    Star,
    Stethoscope,
    Ticket,
    TrendingUp,
    Truck,
    UserCog,
    UserPlus,
    Users,
    Video,
    Wallet,
    X,
} from "lucide-react";
import type { ReactNode } from "react";
import { Logo } from "@/components/Logo";
import type { SharedProps } from "@/types";

/** Menu items; `needs` lists the permissions that open the page (any one is enough). */
const nav: {
    label: string;
    icon: typeof LayoutDashboard;
    href: string;
    needs?: string[];
}[] = [
    { label: "Overview", icon: LayoutDashboard, href: "/workspace" },
    {
        label: "Front desk",
        icon: ClipboardList,
        href: "/front-desk",
        needs: ["visits.manage"],
    },
    {
        label: "Triage",
        icon: Activity,
        href: "/triage",
        needs: ["triage.record"],
    },
    {
        label: "My queue",
        icon: Stethoscope,
        href: "/doctor",
        needs: ["consults.write"],
    },
    {
        label: "Patients",
        icon: Users,
        href: "/patients",
        needs: ["patients.view"],
    },
    {
        label: "Appointments",
        icon: CalendarDays,
        href: "/appointments",
        needs: ["appointments.view"],
    },
    {
        label: "Rosters",
        icon: Clock,
        href: "/rosters",
        needs: ["rosters.manage"],
    },
    {
        label: "Templates",
        icon: FileText,
        href: "/settings/templates",
        needs: ["settings.manage"],
    },
    {
        label: "Pharmacy",
        icon: Pill,
        href: "/pharmacy",
        needs: ["pharmacy.dispense"],
    },
    { label: "Lab", icon: FlaskConical, href: "/lab", needs: ["lab.process"] },
    {
        label: "Unmatched results",
        icon: FileQuestion,
        href: "/lab/unmatched",
        needs: ["lab.manage"],
    },
    {
        label: "Lab catalogue",
        icon: BookOpen,
        href: "/lab/catalogue",
        needs: ["lab.manage"],
    },
    {
        label: "Results",
        icon: Inbox,
        href: "/results",
        needs: ["consults.write"],
    },
    {
        label: "Finance",
        icon: ChartColumn,
        href: "/finance",
        needs: ["finance.view"],
    },
    {
        label: "Network",
        icon: Share2,
        href: "/network",
        needs: ["patients.register"],
    },
    {
        label: "Online consults",
        icon: Video,
        href: "/telemedicine",
        needs: ["consults.write"],
    },
    {
        label: "Chats",
        icon: MessagesSquare,
        href: "/chats",
        needs: ["consults.write"],
    },
    {
        label: "Messages",
        icon: Mail,
        href: "/messages",
        needs: ["consults.write"],
    },
    {
        label: "Referrals",
        icon: Forward,
        href: "/referrals",
        needs: ["consults.write"],
    },
    {
        label: "Debtors",
        icon: HandCoins,
        href: "/finance/debtors",
        needs: ["billing.collect"],
    },
    {
        label: "Prepaid packages",
        icon: Ticket,
        href: "/packages",
        needs: ["billing.collect"],
    },
    {
        label: "Locums",
        icon: UserPlus,
        href: "/locums",
        needs: ["staff.manage"],
    },
    {
        label: "Corporate wellness",
        icon: HeartPulse,
        href: "/corporate-wellness",
    },
    { label: "Staff", icon: UserCog, href: "/staff", needs: ["staff.view"] },
    {
        label: "Reports",
        icon: BarChart3,
        href: "/reports",
        needs: [
            "appointments.view",
            "audit.view",
            "finance.view",
            "lab.manage",
        ],
    },
    {
        label: "Analytics",
        icon: TrendingUp,
        href: "/analytics",
        needs: ["finance.view"],
    },
    {
        label: "API",
        icon: KeyRound,
        href: "/settings/api",
        needs: ["settings.manage"],
    },
    {
        label: "AI scribe",
        icon: Sparkles,
        href: "/settings/ai-scribe",
        needs: ["settings.manage"],
    },
    {
        label: "Support",
        icon: LifeBuoy,
        href: "/support",
        needs: ["settings.manage"],
    },
    {
        label: "VAT",
        icon: Percent,
        href: "/finance/vat",
        needs: ["finance.view"],
    },
    {
        label: "Stock and ordering",
        icon: PackageSearch,
        href: "/procurement",
        needs: ["pharmacy.dispense"],
    },
    {
        label: "Deliveries",
        icon: Truck,
        href: "/deliveries",
        needs: ["pharmacy.dispense"],
    },
    {
        label: "WhatsApp",
        icon: MessageCircle,
        href: "/settings/whatsapp",
        needs: ["settings.manage"],
    },
    {
        label: "Accounting",
        icon: BookText,
        href: "/settings/accounting",
        needs: ["settings.manage"],
    },
    {
        label: "Branches",
        icon: Building2,
        href: "/settings/branches",
        needs: ["settings.manage"],
    },
    {
        label: "Your domain",
        icon: Globe,
        href: "/settings/domains",
        needs: ["settings.manage"],
    },
    {
        label: "Website images",
        icon: Image,
        href: "/settings/website/media",
        needs: ["settings.manage"],
    },
    {
        label: "Patient feedback",
        icon: Star,
        href: "/settings/website/feedback",
    },
    { label: "My calendar", icon: CalendarDays, href: "/me/calendar" },
    {
        label: "Compliance",
        icon: ShieldAlert,
        href: "/compliance/break-glass",
        needs: ["audit.view"],
    },
    {
        label: "E-scripts",
        icon: FileSignature,
        href: "/escripts",
        needs: ["pharmacy.dispense"],
    },
    {
        label: "Wallet",
        icon: Wallet,
        href: "/settings/wallet",
        needs: ["payments.configure"],
    },
    {
        label: "Claims",
        icon: FileCheck,
        href: "/claims",
        needs: ["claims.manage"],
    },
    {
        label: "Audit",
        icon: ShieldCheck,
        href: "/compliance/audit",
        needs: ["audit.view"],
    },
    {
        label: "Website",
        icon: Globe,
        href: "/settings/website",
        needs: ["settings.manage"],
    },
    {
        label: "Settings",
        icon: Settings,
        href: "/settings/billing",
        needs: ["settings.manage"],
    },
];

/**
 * Provider workspace shell: dark sidebar with workspace switcher, top bar, content.
 * Navigation items become role-based from Sprint 1.
 */
export function AppShell({
    children,
    active = "Overview",
}: {
    children: ReactNode;
    active?: string;
}) {
    const {
        provider,
        branchSwitcher: branches,
        auth,
    } = usePage<
        SharedProps & {
            branchSwitcher: {
                options: { id: number; name: string }[];
                current: number | null;
            } | null;
        }
    >().props;
    const permissions = auth.permissions ?? [];
    const initials = (provider?.name ?? "CF")
        .split(" ")
        .map((word) => word[0])
        .slice(0, 2)
        .join("");

    const [menuOpen, setMenuOpen] = useState(false);
    // Close the mobile menu after moving to another page.
    useEffect(() => router.on("navigate", () => setMenuOpen(false)), []);

    return (
        <div className="flex min-h-screen max-md:flex-col">
            <header className="sticky top-0 z-20 flex items-center gap-3 px-4 py-2.5 text-white shadow md:hidden bg-chrome">
                <Logo tone="light" />

                <button
                    type="button"
                    className="ml-auto rounded-md p-2.5 text-white hover:bg-white/10"
                    aria-label="Open menu"
                    aria-expanded={menuOpen}
                    aria-controls="app-menu"
                    onClick={() => setMenuOpen(true)}
                >
                    <Menu size={18} aria-hidden="true" />
                </button>
            </header>
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
                className={`fixed inset-y-0 left-0 z-40 flex w-60 flex-none flex-col overflow-y-auto bg-chrome px-3.5 py-5 text-chrome-muted transition-transform md:static md:translate-x-0 ${menuOpen ? "translate-x-0" : "-translate-x-full"}`}
            >
                <button
                    type="button"
                    className="mb-2 self-end rounded p-1 text-chrome-muted hover:text-white md:hidden"
                    aria-label="Close menu"
                    onClick={() => setMenuOpen(false)}
                >
                    <X size={18} aria-hidden="true" />
                </button>
                <div className="px-1.5 pb-4">
                    <Logo tone="light" />
                </div>
                <div className="mb-4 flex items-center gap-2.5 rounded-lg bg-chrome-2 px-3 py-2.5">
                    <span className="grid size-8 place-items-center rounded-md bg-[#2D5161] text-xs font-semibold text-white">
                        {initials}
                    </span>
                    <div className="leading-tight">
                        <div className="text-sm font-medium text-white">
                            {provider?.name ?? "Dr Business Flow"}
                        </div>
                        <div className="text-xs text-chrome-muted">
                            {provider?.typeLabel ?? "Platform"}
                        </div>
                    </div>
                </div>
                {branches && (
                    <select
                        aria-label="Branch"
                        className="mb-4 rounded-md bg-chrome-2 px-2 py-1.5 text-sm text-white"
                        value={branches.current ?? ""}
                        onChange={(e) =>
                            router.post("/branches/switch", {
                                branch_id: Number(e.target.value),
                            })
                        }
                    >
                        {branches.options.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.name}
                            </option>
                        ))}
                    </select>
                )}
                <nav aria-label="Main">
                    {nav
                        .filter(
                            (item) =>
                                !item.needs ||
                                item.needs.some((p) => permissions.includes(p)),
                        )
                        .map(({ label, icon: Icon, href }) => (
                            <Link
                                key={label}
                                href={href}
                                aria-current={
                                    label === active ? "page" : undefined
                                }
                                className={`mb-0.5 flex items-center gap-3 rounded-md px-2.5 py-2 text-sm ${label === active ? "bg-white/10 font-medium text-white" : "hover:bg-white/5"}`}
                            >
                                <Icon className="size-4" aria-hidden="true" />
                                {label}
                            </Link>
                        ))}
                </nav>
                <ThemeToggle className="mt-3 self-start" />
                <AccountMenu area="practice" />
            </aside>
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-15 items-center gap-3 border-b border-line bg-surface px-6">
                    <label className="flex w-96 items-center gap-2 rounded-lg border border-line bg-paper px-3 py-2 text-sm text-muted">
                        <Search className="size-4" aria-hidden="true" />
                        <span className="sr-only">Search</span>
                        <input
                            className="w-full bg-transparent outline-none"
                            placeholder="Search patients, scripts, invoices"
                        />
                    </label>
                    <div className="ml-auto flex gap-1 text-muted">
                        <button
                            type="button"
                            aria-label="Help"
                            className="grid size-9 place-items-center rounded-lg hover:bg-paper"
                        >
                            <CircleHelp className="size-4.5" />
                        </button>
                        <button
                            type="button"
                            aria-label="Notifications"
                            className="grid size-9 place-items-center rounded-lg hover:bg-paper"
                        >
                            <Bell className="size-4.5" />
                        </button>
                    </div>
                </header>
                <main
                    id="main-content"
                    tabIndex={-1}
                    className="min-w-0 flex-1 px-4 py-5 md:px-6 md:py-6"
                >
                    {children}
                    <ResponsiveTables />
                    <DeveloperCredit className="mt-10 pb-2" />
                </main>
            </div>
        </div>
    );
}
