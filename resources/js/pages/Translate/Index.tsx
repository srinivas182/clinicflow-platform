import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import { DeveloperCredit } from "@/components/DeveloperCredit";
import { Flash } from "@/components/Flash";
import { Logo } from "@/components/Logo";
import { Badge, Button, Card } from "@/components/ui";

interface Item {
    unit: string;
    english: string;
    context: string;
    placeholders: string[];
    medical: boolean;
    sms: boolean;
    text: string | null;
    status: "missing" | "ai_draft" | "draft" | "approved";
    englishChanged: boolean;
    approved: boolean;
    clinicalApproved: boolean;
    live: boolean;
}
interface Props {
    language: string;
    languages: { code: string; name: string }[];
    clinicalReviewer: boolean;
    items: Item[];
}
type Filter = "review" | "live" | "changed" | "all";

/** Highlights {{ placeholders }} so reviewers keep them exactly as they are. */
function Marked({ text }: { text: string }) {
    return (
        <>
            {text.split(/(\{\{\s*[a-z_]+\s*\}\})/g).map((part, i) =>
                /^\{\{/.test(part) ? (
                    <mark
                        key={i}
                        className="rounded bg-mint-2 px-0.5 font-mono text-xs text-ink"
                    >
                        {part}
                    </mark>
                ) : (
                    <span key={i}>{part}</span>
                ),
            )}
        </>
    );
}

function status(i: Item): {
    label: string;
    tone: "neutral" | "warning" | "success" | "teal" | "danger";
} {
    if (i.englishChanged)
        return { label: "English changed — please re-check", tone: "danger" };
    if (i.live) return { label: "Live", tone: "success" };
    if (i.status === "approved" && i.medical && !i.clinicalApproved)
        return { label: "Needs clinical approval", tone: "warning" };
    if (i.status === "ai_draft")
        return { label: "AI draft — needs review", tone: "warning" };
    if (i.status === "draft") return { label: "Draft", tone: "teal" };
    return { label: "To translate", tone: "neutral" };
}

function Row({
    item,
    language,
    clinicalReviewer,
}: {
    item: Item;
    language: string;
    clinicalReviewer: boolean;
}) {
    const [text, setText] = useState(item.text ?? "");
    const [error, setError] = useState<string | null>(null);
    const saved = text.trim() === (item.text ?? "").trim();
    const post = (url: string, data: Record<string, string | boolean>) =>
        router.post(url, data, {
            preserveScroll: true,
            onError: (e) =>
                setError(Object.values(e)[0] ?? "Something went wrong."),
            onSuccess: () => setError(null),
        });
    const s = status(item);
    const smsParts = Math.max(1, Math.ceil(text.length / 160));

    return (
        <Card
            title={item.context}
            aside={<Badge tone={s.tone}>{s.label}</Badge>}
        >
            {item.medical && (
                <p className="mb-2 text-xs font-medium text-status-warning">
                    Medical wording: a clinical reviewer must also approve this.
                </p>
            )}
            <p className="text-xs font-medium text-muted">English</p>
            <p className="mb-3 text-sm whitespace-pre-line">
                <Marked text={item.english} />
            </p>
            <label className="block text-xs font-medium text-muted">
                Translation
                <textarea
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    rows={Math.min(
                        8,
                        Math.max(2, Math.ceil(item.english.length / 60)),
                    )}
                    className="mt-1 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink"
                />
            </label>
            <p className="mt-1 text-xs text-muted">
                Keep{" "}
                {item.placeholders.map((p) => `{{ ${p} }}`).join(", ") ||
                    "the wording"}{" "}
                exactly as in the English.
                {item.sms && ` ${text.length} characters (${smsParts} SMS).`}
            </p>
            {error && (
                <p role="alert" className="mt-2 text-sm text-status-danger">
                    {error}
                </p>
            )}
            <div className="mt-3 flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="secondary"
                    disabled={saved}
                    onClick={() =>
                        post(`/translate/${language}/save`, {
                            unit: item.unit,
                            text,
                        })
                    }
                >
                    Save
                </Button>
                <Button
                    size="sm"
                    disabled={
                        !saved ||
                        text.trim() === "" ||
                        item.englishChanged ||
                        (item.approved && item.status === "approved")
                    }
                    onClick={() =>
                        post(`/translate/${language}/approve`, {
                            unit: item.unit,
                        })
                    }
                >
                    Approve
                </Button>
                {clinicalReviewer && item.medical && (
                    <Button
                        size="sm"
                        disabled={
                            !saved ||
                            text.trim() === "" ||
                            item.englishChanged ||
                            item.clinicalApproved
                        }
                        onClick={() =>
                            post(`/translate/${language}/approve`, {
                                unit: item.unit,
                                clinical: true,
                            })
                        }
                    >
                        Approve medical wording
                    </Button>
                )}
            </div>
        </Card>
    );
}

/** The review screen for invited native speakers. No patient data — only the texts to translate. */
export default function Translate({
    language,
    languages,
    clinicalReviewer,
    items,
}: Props) {
    const [filter, setFilter] = useState<Filter>("review");
    const live = items.filter((i) => i.live).length;
    const shown = items.filter((i) =>
        filter === "all"
            ? true
            : filter === "live"
              ? i.live
              : filter === "changed"
                ? i.englishChanged
                : !i.live,
    );
    const name = languages.find((l) => l.code === language)?.name ?? language;

    return (
        <div className="min-h-screen bg-paper">
            <Head title={`Review ${name}`} />
            <header className="flex flex-wrap items-center gap-3 border-b border-line bg-surface px-4 py-3 md:px-8">
                <Logo />
                <span className="text-sm text-muted">Translation review</span>
                <Button
                    size="sm"
                    variant="ghost"
                    className="ml-auto"
                    onClick={() => router.post("/logout")}
                >
                    Sign Out
                </Button>
            </header>
            <main id="main-content" className="mx-auto max-w-3xl px-4 py-6">
                <h1 className="text-2xl font-semibold">Review {name}</h1>
                <p className="mt-1 text-sm text-muted">
                    Patients see a translation only after you approve it. Until
                    then they see the English. {live} of {items.length} live.
                </p>
                <div
                    className="mt-2 h-2 rounded-full bg-line"
                    aria-hidden="true"
                >
                    <div
                        className="h-2 rounded-full bg-teal"
                        style={{
                            width: `${Math.round((live / Math.max(1, items.length)) * 100)}%`,
                        }}
                    />
                </div>
                <div className="mt-4 flex flex-wrap gap-2">
                    {languages.length > 1 && (
                        <select
                            aria-label="Language"
                            value={language}
                            onChange={(e) =>
                                router.get("/translate", {
                                    language: e.target.value,
                                })
                            }
                            className="rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm"
                        >
                            {languages.map((l) => (
                                <option key={l.code} value={l.code}>
                                    {l.name}
                                </option>
                            ))}
                        </select>
                    )}
                    {(
                        [
                            ["review", "To review"],
                            ["changed", "English changed"],
                            ["live", "Live"],
                            ["all", "All"],
                        ] as const
                    ).map(([f, label]) => (
                        <Button
                            key={f}
                            size="sm"
                            variant={filter === f ? "primary" : "secondary"}
                            onClick={() => setFilter(f)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>
                <div className="mt-4">
                    <Flash />
                </div>
                <div className="mt-4 space-y-4">
                    {shown.length === 0 && (
                        <p className="text-sm text-muted">Nothing here.</p>
                    )}
                    {shown.map((i) => (
                        <Row
                            key={`${language}-${i.unit}-${i.text ?? ""}-${i.status}`}
                            item={i}
                            language={language}
                            clinicalReviewer={clinicalReviewer}
                        />
                    ))}
                </div>
            </main>
            <DeveloperCredit className="pb-6" />
        </div>
    );
}
