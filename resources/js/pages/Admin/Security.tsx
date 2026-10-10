import { Head, router } from "@inertiajs/react";
import { ArrowDown, ArrowUp } from "lucide-react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

type Method = "authenticator" | "email" | "sms";
const LABEL: Record<Method, string> = {
    authenticator: "Authenticator app",
    email: "Email code",
    sms: "SMS code",
};
const ALL: Method[] = ["authenticator", "email", "sms"];

interface Props {
    enabled: boolean;
    methods: Method[];
    suppliers: { email: boolean; sms: boolean };
    me: { authenticator: boolean; email: boolean; sms: boolean };
}

/** Admin → Security: two-step sign-in on or off, and the order of methods. */
export default function SecuritySettings({
    enabled: initialEnabled,
    methods: initialMethods,
    suppliers,
    me,
}: Props) {
    const [enabled, setEnabled] = useState(initialEnabled);
    const [order, setOrder] = useState<Method[]>([
        ...initialMethods,
        ...ALL.filter((m) => !initialMethods.includes(m)),
    ]);
    const [included, setIncluded] = useState<Method[]>(initialMethods);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const move = (i: number, d: -1 | 1) =>
        setOrder((o) => {
            const n = [...o];
            [n[i], n[i + d]] = [n[i + d] as Method, n[i] as Method];
            return n;
        });
    const note = (m: Method): string | null =>
        m === "authenticator"
            ? null
            : !suppliers[m]
              ? `No ${m === "email" ? "email" : "SMS"} supplier set up yet (Messaging) — codes would not be delivered.`
              : null;
    const save = () =>
        router.post(
            "/admin/security",
            { enabled, methods: order.filter((m) => included.includes(m)) },
            { preserveScroll: true, onError: (e) => setErrors(e) },
        );

    return (
        <AdminShell active="Security">
            <Head title="Security" />
            <h1 className="mb-1 text-2xl font-semibold">Security</h1>
            <p className="mb-5 text-sm text-muted">
                Two-step sign-in asks staff for a second check after their
                password.
            </p>
            <Flash />
            <Card
                title="Two-step sign-in"
                aside={
                    <Badge tone={enabled ? "success" : "warning"}>
                        {enabled ? "on" : "off"}
                    </Badge>
                }
            >
                <label className="mb-3 flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="accent-teal"
                        checked={enabled}
                        onChange={(e) => setEnabled(e.target.checked)}
                    />
                    Require a second step when staff sign in
                </label>
                {!enabled && (
                    <p
                        role="status"
                        className="mb-3 text-sm text-status-warning"
                    >
                        Off: staff sign in with a password only. Fine for a demo
                        — switch it on before real patient data.
                    </p>
                )}
                <p className="mb-2 text-sm text-muted">
                    Order of methods: each person gets the first one that works
                    for them.
                </p>
                <ol className="space-y-2">
                    {order.map((m, i) => (
                        <li
                            key={m}
                            className="flex flex-wrap items-center gap-2 rounded-md border border-line-soft px-3 py-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                className="accent-teal"
                                aria-label={`Use ${LABEL[m]}`}
                                checked={included.includes(m)}
                                onChange={(e) =>
                                    setIncluded((list) =>
                                        e.target.checked
                                            ? [...list, m]
                                            : list.filter((x) => x !== m),
                                    )
                                }
                            />
                            <span className="w-6 text-muted">{i + 1}.</span>
                            <span className="flex-1 font-medium">
                                {LABEL[m]}
                            </span>
                            {note(m) && (
                                <span className="w-full text-xs text-status-warning sm:w-auto">
                                    {note(m)}
                                </span>
                            )}
                            <Button
                                size="sm"
                                variant="ghost"
                                aria-label={`Move ${LABEL[m]} up`}
                                disabled={i === 0}
                                onClick={() => move(i, -1)}
                            >
                                <ArrowUp size={14} aria-hidden="true" />
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                aria-label={`Move ${LABEL[m]} down`}
                                disabled={i === order.length - 1}
                                onClick={() => move(i, 1)}
                            >
                                <ArrowDown size={14} aria-hidden="true" />
                            </Button>
                        </li>
                    ))}
                </ol>
                {!me.authenticator && (
                    <p className="mt-3 text-xs text-muted">
                        Your own authenticator app is not set up (Account →
                        Security).
                    </p>
                )}
                {errors.methods && (
                    <p role="alert" className="mt-3 text-sm text-status-danger">
                        {errors.methods}
                    </p>
                )}
                <div className="mt-4">
                    <Button onClick={save}>Save</Button>
                </div>
            </Card>
        </AdminShell>
    );
}
