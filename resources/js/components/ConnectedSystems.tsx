import { router } from "@inertiajs/react";
import { useState } from "react";
import { Badge, Button, Card } from "@/components/ui";

interface Props {
    title: string;
    intro: string;
    systems: { key: number; name: string; allowed: string[] }[];
    categories: Record<string, string>;
    postUrl: string;
    extra?: Record<string, unknown>;
    staff?: boolean;
}

/**
 * Per-system choice of which parts of the record a connected system may read (FHIR consent).
 */
export function ConnectedSystems({
    title,
    intro,
    systems,
    categories,
    postUrl,
    extra = {},
    staff = false,
}: Props) {
    const [choice, setChoice] = useState<Record<number, string[]>>(
        Object.fromEntries(systems.map((s) => [s.key, s.allowed])),
    );
    if (systems.length === 0) {
        return null;
    }

    return (
        <Card title={title} className="mb-4">
            <p className="mb-3 text-xs text-muted">{intro}</p>
            {systems.map((s) => (
                <div
                    key={s.key}
                    className="mb-3 border-t border-line-soft pt-2 text-sm first:border-0 first:pt-0"
                >
                    <p className="mb-1 font-medium">
                        {s.name}{" "}
                        {s.allowed.length > 0 ? (
                            <Badge tone="success">sharing</Badge>
                        ) : (
                            <Badge>not sharing</Badge>
                        )}
                    </p>
                    <div className="mb-2 flex flex-wrap gap-3">
                        {Object.entries(categories).map(([c, label]) => (
                            <label key={c} className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    className="accent-teal"
                                    checked={(choice[s.key] ?? []).includes(c)}
                                    onChange={(e) =>
                                        setChoice({
                                            ...choice,
                                            [s.key]: e.target.checked
                                                ? [...(choice[s.key] ?? []), c]
                                                : (choice[s.key] ?? []).filter(
                                                      (x) => x !== c,
                                                  ),
                                        })
                                    }
                                />
                                {label}
                            </label>
                        ))}
                    </div>
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => {
                            const cats = choice[s.key] ?? [];
                            if (
                                staff &&
                                cats.length > 0 &&
                                !window.confirm(
                                    "Confirm the patient gave this consent (e.g. a signed form).",
                                )
                            ) {
                                return;
                            }
                            router.post(
                                postUrl,
                                {
                                    ...extra,
                                    key_id: s.key,
                                    categories: cats,
                                    confirmed: staff,
                                },
                                { preserveScroll: true },
                            );
                        }}
                    >
                        {(choice[s.key] ?? []).length === 0
                            ? "Stop sharing"
                            : "Save"}
                    </Button>
                </div>
            ))}
        </Card>
    );
}
