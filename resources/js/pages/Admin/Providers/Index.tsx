import { Head, Link } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, type BadgeTone } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface Row {
    id: string;
    name: string;
    type: string;
    status: string;
    package: string | null;
    address: string | null;
    pendingChecks: number;
}

export const statusTone: Record<string, BadgeTone> = {
    pending_verification: "warning",
    trial: "teal",
    active: "success",
    read_only: "danger",
    suspended: "danger",
};

export default function ProvidersIndex({ providers }: { providers: Row[] }) {
    return (
        <AdminShell active="Providers">
            <Head title="Providers" />
            <h1 className="mb-1 text-2xl font-semibold">Providers</h1>
            <p className="mb-5 text-sm text-muted">
                Every clinic, doctor, pharmacy and lab on the platform.
            </p>
            <Flash />
            <div className="overflow-hidden rounded-xl border border-line bg-surface">
                <table className="w-full text-sm">
                    <thead className="bg-paper text-left text-xs text-muted">
                        <tr>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Provider
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Package
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Address
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Status
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Checks pending
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {providers.map((p) => (
                            <tr
                                key={p.id}
                                className="border-t border-line-soft"
                            >
                                <td className="px-4 py-3">
                                    <Link
                                        href={`/admin/providers/${p.id}`}
                                        className="font-medium text-teal-deep"
                                    >
                                        {p.name}
                                    </Link>
                                    <div className="text-xs text-muted">
                                        {p.type}
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    {p.package ?? "—"}
                                </td>
                                <td className="px-4 py-3 text-muted">
                                    {p.address}
                                </td>
                                <td className="px-4 py-3">
                                    <Badge
                                        tone={statusTone[p.status] ?? "neutral"}
                                    >
                                        {p.status.replace("_", " ")}
                                    </Badge>
                                </td>
                                <td className="px-4 py-3">{p.pendingChecks}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AdminShell>
    );
}
