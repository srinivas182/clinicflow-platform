import { router, usePage } from "@inertiajs/react";
import { ChevronUp, LogOut, Repeat, ShieldCheck } from "lucide-react";
import { useState } from "react";

interface Shared {
    auth: { user: { name: string; email: string } | null };
    centralUrl: string;
    [key: string]: unknown;
}

/**
 * Profile and sign-out at the foot of the sidebar. In a practice, account links go to the central
 * site and "Sign Out" signs the person out everywhere.
 */
export function AccountMenu({ area }: { area: "admin" | "practice" }) {
    const { auth, centralUrl } = usePage<Shared>().props;
    const [open, setOpen] = useState(false);
    if (!auth.user) return null;
    const base = area === "practice" ? centralUrl : "";
    const initials = auth.user.name
        .split(" ")
        .map((w) => w[0])
        .join("")
        .slice(0, 2)
        .toUpperCase();
    const item =
        "flex items-center gap-2 rounded-md px-3 py-2 text-sm text-chrome-muted hover:bg-white/10 hover:text-white";

    return (
        <div className="mt-auto border-t border-white/10 pt-3">
            {open && (
                <div id="account-menu" className="mb-2 flex flex-col">
                    <a href={`${base}/account/security`} className={item}>
                        <ShieldCheck size={16} aria-hidden="true" /> Account
                        &amp; Security
                    </a>
                    <a href={`${base}/workspaces`} className={item}>
                        <Repeat size={16} aria-hidden="true" />{" "}
                        {area === "practice"
                            ? "Switch Practice"
                            : "My Workspaces"}
                    </a>
                    <button
                        type="button"
                        className={`${item} text-left`}
                        onClick={() =>
                            router.post(
                                area === "practice"
                                    ? "/staff/logout"
                                    : "/logout",
                            )
                        }
                    >
                        <LogOut size={16} aria-hidden="true" /> Sign Out
                    </button>
                </div>
            )}
            <button
                type="button"
                aria-expanded={open}
                aria-controls="account-menu"
                onClick={() => setOpen((o) => !o)}
                className="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left hover:bg-white/10"
            >
                <span
                    className="grid size-8 flex-none place-items-center rounded-full bg-teal text-xs font-semibold text-white"
                    aria-hidden="true"
                >
                    {initials}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium text-white">
                        {auth.user.name}
                    </span>
                    <span className="block truncate text-xs text-chrome-muted">
                        {auth.user.email}
                    </span>
                </span>
                <ChevronUp
                    size={16}
                    aria-hidden="true"
                    className={`text-chrome-muted ${open ? "" : "rotate-180"}`}
                />
                <span className="sr-only">Account menu</span>
            </button>
        </div>
    );
}
