import { ResponsiveTables } from "@/components/ResponsiveTables";
import { DeveloperCredit } from "@/components/DeveloperCredit";
import type { ReactNode } from "react";
import { Logo } from "@/components/Logo";

/**
 * Patient-facing layout on the provider's own address. Mobile-first.
 */
export function PortalLayout({
    provider,
    children,
}: {
    provider: string;
    children: ReactNode;
}) {
    return (
        <div className="min-h-screen bg-paper">
            <header className="flex items-center gap-3 border-b border-line bg-surface px-5 py-3">
                <span className="font-semibold">{provider}</span>
                <span className="ml-auto opacity-70">
                    <Logo />
                </span>
            </header>
            <main id="portal-content" className="mx-auto max-w-3xl px-4 py-6">
                {children}
            </main>
            <ResponsiveTables rootId="portal-content" />
            <DeveloperCredit className="pb-4" />
        </div>
    );
}
