import { DeveloperCredit } from "@/components/DeveloperCredit";
import type { ReactNode } from "react";
import { Logo } from "@/components/Logo";

/**
 * Central sign-in pages: brand panel on the left, form on the right.
 */
export function AuthLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-screen bg-surface">
            <aside className="hidden w-[480px] flex-col bg-chrome p-12 text-white lg:flex">
                <Logo tone="light" />
                <div className="mt-auto">
                    <p className="text-3xl leading-tight font-semibold">
                        One login for every place you work.
                    </p>
                    <p className="mt-4 text-[#A9BCC2]">
                        Each workspace keeps its own patients, records and
                        money. Hosted in South Africa.
                    </p>
                </div>
            </aside>
            <main className="flex flex-1 items-center justify-center p-8">
                <div className="w-full max-w-md">{children}</div>
            </main>
            <DeveloperCredit className="pb-4" />
        </div>
    );
}
