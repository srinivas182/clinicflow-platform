import { DeveloperCredit } from "@/components/DeveloperCredit";
import { Head } from "@inertiajs/react";
import { Activity, ChevronDown, Menu } from "lucide-react";
import { useState, type ReactNode } from "react";
import type { SiteInfo, SiteLink } from "./types";

export function SiteLayout({
    site,
    title,
    description,
    children,
}: {
    site: SiteInfo;
    title: string;
    description: string;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div className="min-h-screen bg-surface">
            <Head title={title}>
                <meta name="description" content={description} />
                <meta property="og:title" content={title} />
                <meta property="og:description" content={description} />
                {site.ogImage && (
                    <meta property="og:image" content={site.ogImage} />
                )}
            </Head>
            <header className="sticky top-0 z-20 border-b border-line-soft bg-surface/95 backdrop-blur">
                <div className="mx-auto flex max-w-6xl items-center gap-6 px-6 py-4">
                    <a
                        href="/"
                        className="flex items-center gap-2 text-lg font-semibold"
                    >
                        <span
                            className="grid size-8 place-items-center rounded-lg font-bold text-white"
                            style={{ background: site.colour }}
                        >
                            {site.platform ? (
                                <Activity
                                    className="size-5"
                                    aria-hidden="true"
                                />
                            ) : (
                                site.name.charAt(0)
                            )}
                        </span>
                        {site.name}
                    </a>
                    <nav
                        aria-label="Main"
                        className={`${open ? "flex" : "hidden"} absolute top-16 right-0 left-0 flex-col gap-1 border-b border-line bg-surface p-4 md:static md:flex md:flex-1 md:flex-row md:items-center md:border-0 md:p-0`}
                    >
                        {site.menu.map((m) => (
                            <NavItem key={m.label} item={m} />
                        ))}
                        {site.signIn && (
                            <a
                                href={site.signIn.href}
                                className="rounded-md px-3 py-2 text-sm font-medium text-muted hover:text-ink md:hidden"
                            >
                                {site.signIn.label}
                            </a>
                        )}
                        <a
                            href={site.cta.href}
                            className="mt-2 rounded-lg px-4 py-2 text-center text-sm font-semibold text-white md:hidden"
                            style={{ background: site.colour }}
                        >
                            {site.cta.label}
                        </a>
                    </nav>
                    <div className="ml-auto hidden items-center gap-2 md:flex">
                        {site.signIn && (
                            <a
                                href={site.signIn.href}
                                className="rounded-md px-3 py-2 text-sm font-medium text-muted hover:text-ink"
                            >
                                {site.signIn.label}
                            </a>
                        )}
                        <a
                            href={site.cta.href}
                            className="rounded-lg px-4 py-2 text-sm font-semibold text-white"
                            style={{ background: site.colour }}
                        >
                            {site.cta.label}
                        </a>
                    </div>
                    <button
                        type="button"
                        aria-label="Menu"
                        aria-expanded={open}
                        className="ml-auto md:hidden"
                        onClick={() => setOpen(!open)}
                    >
                        <Menu className="size-6" />
                    </button>
                </div>
            </header>
            <main>{children}</main>
            <footer className="border-t border-line bg-paper">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-6 py-8 text-sm text-muted">
                    <span>
                        © {new Date().getFullYear()} {site.name}
                    </span>
                    {site.footer.map((f) => (
                        <a
                            key={f.href}
                            href={f.href}
                            className="hover:text-ink"
                        >
                            {f.label}
                        </a>
                    ))}
                    {site.poweredBy && (
                        <span className="ml-auto text-xs">
                            Powered by Dr Business Flow
                        </span>
                    )}
                </div>
                <DeveloperCredit className="pb-6" />
            </footer>
        </div>
    );
}

/** A menu entry; with children it opens a dropdown (hover or click on desktop, tap on phones). */
function NavItem({ item }: { item: SiteLink }) {
    const [open, setOpen] = useState(false);
    const link =
        "rounded-md px-3 py-2 text-sm font-medium text-muted hover:text-ink";
    if (!item.children?.length) {
        return (
            <a href={item.href} className={link}>
                {item.label}
            </a>
        );
    }

    return (
        <div
            className="relative"
            onMouseEnter={() => setOpen(true)}
            onMouseLeave={() => setOpen(false)}
            onKeyDown={(e) => e.key === "Escape" && setOpen(false)}
        >
            <button
                type="button"
                aria-expanded={open}
                aria-haspopup="true"
                onClick={() => setOpen((o) => !o)}
                className={`flex w-full items-center gap-1 ${link}`}
            >
                {item.label}
                <ChevronDown
                    className={`size-4 transition-transform ${open ? "rotate-180" : ""}`}
                    aria-hidden="true"
                />
            </button>
            <div
                className={`${open ? "block" : "hidden"} pl-3 md:absolute md:top-full md:left-0 md:z-30 md:pt-2 md:pl-0`}
            >
                <div className="md:min-w-56 md:rounded-xl md:border md:border-line md:bg-surface md:p-2 md:shadow-lg">
                    {item.children.map((c) => (
                        <a
                            key={c.href}
                            href={c.href}
                            className="block rounded-md px-3 py-2 text-sm text-muted hover:bg-line-soft hover:text-ink"
                        >
                            {c.label}
                        </a>
                    ))}
                </div>
            </div>
        </div>
    );
}
