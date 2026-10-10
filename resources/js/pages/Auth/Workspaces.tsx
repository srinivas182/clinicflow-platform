import { useEffect } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { ChevronRight } from "lucide-react";
import { Badge } from "@/components/ui";
import { AuthLayout } from "@/layouts/AuthLayout";

interface Workspace {
    providerId: string;
    name: string;
    type: string;
    role: string;
    expiresAt: string | null;
}

export default function Workspaces({
    userName,
    workspaces,
    isPlatformAdmin,
    autoOpen,
}: {
    userName: string;
    workspaces: Workspace[];
    isPlatformAdmin: boolean;
    autoOpen: string | null;
}) {
    // Arriving from a practice's "Staff sign in": go straight into that practice.
    useEffect(() => {
        if (autoOpen) router.post(`/workspaces/${autoOpen}/open`);
    }, [autoOpen]);
    return (
        <AuthLayout>
            <Head title="Choose workspace" />
            <h1 className="text-2xl font-semibold">Welcome back, {userName}</h1>
            <p className="mt-1 text-sm text-muted">
                Choose where you're working today.
            </p>
            <div className="mt-8 flex flex-col gap-3">
                {isPlatformAdmin && (
                    <Link
                        href="/admin"
                        className="mb-3 block rounded-xl border border-line bg-surface p-4 hover:border-teal"
                    >
                        <span className="font-semibold">Platform admin</span>
                        <span className="block text-sm text-muted">
                            Practices, packages, messaging and security for the
                            whole platform
                        </span>
                    </Link>
                )}
                {workspaces.length === 0 && !isPlatformAdmin && (
                    <p className="rounded-lg border border-line bg-paper p-4 text-sm text-muted">
                        You don't have access to any workspace yet. Ask your
                        practice admin to invite you.
                    </p>
                )}
                {workspaces.map((w) => (
                    <button
                        key={w.providerId}
                        type="button"
                        onClick={() =>
                            router.post(`/workspaces/${w.providerId}/open`)
                        }
                        className="flex items-center gap-4 rounded-xl border border-line bg-surface p-4 text-left hover:border-teal"
                    >
                        <span className="grid size-11 place-items-center rounded-xl bg-teal font-bold text-white">
                            {w.name
                                .split(" ")
                                .map((s) => s[0])
                                .slice(0, 2)
                                .join("")}
                        </span>
                        <span className="flex-1">
                            <span className="block font-semibold">
                                {w.name}
                            </span>
                            <span className="block text-sm text-muted">
                                {w.role} · {w.type}
                            </span>
                        </span>
                        {w.expiresAt && (
                            <Badge tone="network">Until {w.expiresAt}</Badge>
                        )}
                        <ChevronRight
                            className="size-4 text-muted"
                            aria-hidden="true"
                        />
                    </button>
                ))}
            </div>
            <button
                type="button"
                onClick={() => router.post("/logout")}
                className="mt-8 text-sm text-teal-deep"
            >
                Sign out
            </button>
        </AuthLayout>
    );
}
