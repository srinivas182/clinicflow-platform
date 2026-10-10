import { Head, router, useForm } from "@inertiajs/react";
import { Network as NetworkIcon, ShieldCheck } from "lucide-react";
import { Flash } from "@/components/Flash";
import { Field } from "@/components/form/Field";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

export default function Network({
    query,
    match,
    requestId,
}: {
    query: { cell: string; sa_id: string };
    match: { id: string; masked: string; linked: boolean } | null;
    requestId: number | null;
}) {
    const search = useForm({ cell: query.cell, sa_id: query.sa_id });
    const confirm = useForm({ request_id: requestId ?? 0, code: "" });

    return (
        <AppShell active="Patients">
            <Head title="Network search" />
            <h1 className="mb-1 text-2xl font-semibold">Network search</h1>
            <p className="mb-5 text-sm text-muted">
                Find a patient already on Dr Business Flow at another practice. You
                see a masked match only; their records link after the patient
                approves with a code on their own phone.
            </p>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                <Card title="Search">
                    <form
                        className="flex flex-col gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get("/network", search.data, {
                                preserveState: true,
                            });
                        }}
                    >
                        <Field
                            label="Cell number"
                            name="cell"
                            maxLength={10}
                            value={search.data.cell}
                            onChange={(e) =>
                                search.setData(
                                    "cell",
                                    e.target.value.replace(/\D/g, ""),
                                )
                            }
                        />
                        <Field
                            label="or SA ID number"
                            name="sa_id"
                            maxLength={13}
                            value={search.data.sa_id}
                            onChange={(e) =>
                                search.setData(
                                    "sa_id",
                                    e.target.value.replace(/\D/g, ""),
                                )
                            }
                        />
                        <Button
                            type="submit"
                            variant="secondary"
                            icon={<NetworkIcon className="size-4" />}
                        >
                            Search the network
                        </Button>
                    </form>
                </Card>
                <Card title="Result">
                    {!match && (
                        <p className="text-sm text-muted">
                            {query.cell || query.sa_id
                                ? "No one on the network matches. Register the patient as new."
                                : "Search by cell or SA ID."}
                        </p>
                    )}
                    {match && (
                        <div className="text-sm">
                            <p className="font-medium">{match.masked}</p>
                            {match.linked ? (
                                <Badge
                                    tone="success"
                                    icon={<ShieldCheck className="size-3" />}
                                >
                                    Already linked to your practice
                                </Badge>
                            ) : (
                                <Button
                                    className="mt-3"
                                    onClick={() =>
                                        router.post(
                                            `/network/identities/${match.id}/request`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Send approval code to the patient
                                </Button>
                            )}
                        </div>
                    )}
                    {requestId && (
                        <form
                            className="mt-4 flex items-end gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                confirm.post("/network/confirm");
                            }}
                        >
                            <Field
                                label="Code the patient reads to you"
                                name="code"
                                inputMode="numeric"
                                maxLength={6}
                                value={confirm.data.code}
                                onChange={(e) =>
                                    confirm.setData(
                                        "code",
                                        e.target.value.replace(/\D/g, ""),
                                    )
                                }
                                error={confirm.errors.code}
                                className="flex-1"
                            />
                            <Button
                                type="submit"
                                disabled={confirm.data.code.length !== 6}
                            >
                                Link records
                            </Button>
                        </form>
                    )}
                </Card>
            </div>
        </AppShell>
    );
}
