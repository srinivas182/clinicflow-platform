import { Head, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";
import { Flash } from "@/components/Flash";
import { Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface ImportRow {
    id: number;
    file: string;
    total: number;
    imported: number;
    skipped: number;
    problems: { row: number; reason: string }[];
    at: string;
}

export default function ImportPatients({
    columns,
    imports,
}: {
    columns: string[];
    imports: ImportRow[];
}) {
    const form = useForm<{ file: File | null }>({ file: null });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post("/patients/import", { forceFormData: true });
    };

    return (
        <AppShell active="Patients">
            <Head title="Import patients" />
            <h1 className="mb-1 text-2xl font-semibold">Import patients</h1>
            <p className="mb-5 text-sm text-muted">
                Upload a CSV export from your previous system. Imported patients
                give consent at their next check-in.
            </p>
            <Flash />
            <Card title="Upload" className="mb-4">
                <p className="mb-3 text-xs text-muted">
                    Columns: {columns.join(", ")}. First names, surname and an
                    SA ID or date of birth are required.
                </p>
                <form onSubmit={submit} className="flex items-center gap-3">
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        onChange={(e) =>
                            form.setData("file", e.target.files?.[0] ?? null)
                        }
                    />
                    <Button
                        type="submit"
                        disabled={!form.data.file || form.processing}
                    >
                        Import
                    </Button>
                </form>
                {form.errors.file && (
                    <p role="alert" className="mt-2 text-xs text-status-danger">
                        {form.errors.file}
                    </p>
                )}
            </Card>
            {imports.map((i) => (
                <Card key={i.id} title={i.file} aside={i.at} className="mb-3">
                    <p className="text-sm">
                        {i.imported} imported · {i.skipped} skipped of {i.total}
                    </p>
                    <ul className="mt-2 text-xs text-muted">
                        {i.problems.map((p, k) => (
                            <li key={k}>
                                Row {p.row}: {p.reason}
                            </li>
                        ))}
                    </ul>
                </Card>
            ))}
        </AppShell>
    );
}
