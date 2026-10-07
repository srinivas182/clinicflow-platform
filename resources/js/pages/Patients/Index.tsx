import { Head, Link, router, usePage } from "@inertiajs/react";
import { Search, UserPlus } from "lucide-react";
import { useState, type FormEvent } from "react";
import { Badge, Button } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";
import type { SharedProps } from "@/types";

interface PatientRow {
    id: string;
    name: string;
    age: number;
    idNumber: string | null;
    cell: string | null;
    medicalAid: string | null;
}

export default function PatientsIndex({
    search,
    patients,
    canRegister,
}: {
    search: string;
    patients: PatientRow[];
    canRegister: boolean;
}) {
    const { flash } = usePage<SharedProps>().props;
    const [term, setTerm] = useState(search);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get("/patients", { search: term }, { preserveState: true });
    };

    return (
        <AppShell active="Patients">
            <Head title="Patients" />
            <div className="flex items-end gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">Patients</h1>
                    <p className="mt-1 text-sm text-muted">
                        Search by name, SA ID or cell number before registering.
                    </p>
                </div>
                {canRegister && (
                    <Link href="/patients/register" className="ml-auto">
                        <Button
                            icon={
                                <UserPlus
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            }
                        >
                            Register patient
                        </Button>
                    </Link>
                )}
            </div>
            {flash.success && (
                <div
                    role="status"
                    className="mt-4 rounded-lg border border-mint-2 bg-mint px-4 py-3 text-sm text-teal-deep"
                >
                    {flash.success}
                </div>
            )}
            <form onSubmit={submit} className="mt-6 flex gap-2">
                <label className="flex flex-1 items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2">
                    <Search className="size-4 text-muted" aria-hidden="true" />
                    <span className="sr-only">Search patients</span>
                    <input
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        placeholder="Name, SA ID or cell"
                        className="w-full text-sm outline-none"
                    />
                </label>
                <Button type="submit" variant="secondary">
                    Search
                </Button>
            </form>
            <div className="mt-4 overflow-hidden rounded-xl border border-line bg-surface">
                <table className="w-full text-sm">
                    <thead className="bg-paper text-left text-xs text-muted">
                        <tr>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Patient
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                SA ID
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Cell
                            </th>
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Medical aid
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {patients.length === 0 && (
                            <tr>
                                <td
                                    colSpan={4}
                                    className="px-4 py-8 text-center text-muted"
                                >
                                    No patients found.{" "}
                                    {canRegister &&
                                        "Register them as a new patient."}
                                </td>
                            </tr>
                        )}
                        {patients.map((p) => (
                            <tr
                                key={p.id}
                                className="border-t border-line-soft"
                            >
                                <td className="px-4 py-3">
                                    <span className="font-medium">
                                        {p.name}
                                    </span>
                                    <span className="ml-2 text-xs text-muted">
                                        {p.age} yrs
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-muted">
                                    {p.idNumber ?? "—"}
                                </td>
                                <td className="px-4 py-3">
                                    {p.cell ?? <Badge>No cell</Badge>}
                                </td>
                                <td className="px-4 py-3">
                                    {p.medicalAid ?? <Badge>Cash</Badge>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppShell>
    );
}
