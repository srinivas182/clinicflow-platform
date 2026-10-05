import { useEffect, useRef, useState } from "react";
import { Badge, Button, Card } from "@/components/ui";
import {
    type ScribeDraft,
    scribePost,
    startRecording,
    uploadRecording,
} from "@/lib/scribe";

interface Props {
    consultationId: string;
    locked: boolean;
    scribe: {
        enabled: boolean;
        left: number;
        declinedBefore: boolean;
        session: {
            id: string;
            status: string;
            error: string | null;
            draft: ScribeDraft | null;
        } | null;
    };
    onUse: (draft: ScribeDraft) => void;
    onAddDiagnosis: (code: string, description: string) => void;
}

/**
 * In-person AI scribe: the doctor confirms the patient agreed, records, and reviews a draft.
 * Nothing is saved to the note until the doctor uses the draft and saves the consultation.
 */
export function ScribePanel({
    consultationId,
    locked,
    scribe,
    onUse,
    onAddDiagnosis,
}: Props) {
    const [step, setStep] = useState<
        "idle" | "ask" | "recording" | "working" | "draft" | "declined"
    >(scribe.session?.status === "drafted" ? "draft" : "idle");
    const [sessionId, setSessionId] = useState<string | null>(
        scribe.session?.id ?? null,
    );
    const [draft, setDraft] = useState<ScribeDraft | null>(
        scribe.session?.draft ?? null,
    );
    const [error, setError] = useState<string | null>(
        scribe.session?.status === "failed" ? scribe.session.error : null,
    );
    const [seconds, setSeconds] = useState(0);
    const rec = useRef<{
        stop: () => Promise<{ blob: Blob; seconds: number }>;
    } | null>(null);
    const stream = useRef<MediaStream | null>(null);

    useEffect(() => {
        if (step !== "recording") return;
        const t = setInterval(() => setSeconds((s) => s + 1), 1000);
        return () => clearInterval(t);
    }, [step]);

    if (!scribe.enabled || locked) return null;

    const answer = async (agreed: boolean) => {
        setError(null);
        try {
            const r = await scribePost(
                `/consultations/${consultationId}/scribe/start`,
                { agreed },
            );
            if (!agreed) return setStep("declined");
            setSessionId(r.session);
            stream.current = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            rec.current = startRecording(stream.current);
            setSeconds(0);
            setStep("recording");
        } catch (e) {
            setError((e as Error).message);
            setStep("idle");
        }
    };

    const stop = async () => {
        if (!rec.current || !sessionId) return;
        setStep("working");
        const { blob, seconds: secs } = await rec.current.stop();
        stream.current?.getTracks().forEach((t) => t.stop());
        try {
            const r = await uploadRecording(sessionId, blob, secs);
            setDraft(r.draft ?? null);
            setStep(r.draft ? "draft" : "idle");
        } catch (e) {
            setError((e as Error).message);
            setStep("idle");
        }
    };

    const close = async (accept: boolean) => {
        if (!sessionId) return;
        if (accept && draft) onUse(draft);
        await scribePost(
            `/scribe/${sessionId}/${accept ? "accept" : "discard"}`,
        ).catch(() => undefined);
        setStep("idle");
        setDraft(null);
    };

    return (
        <Card
            title="AI scribe"
            aside={
                step === "recording" ? (
                    <Badge tone="danger">
                        ● recording {Math.floor(seconds / 60)}:
                        {String(seconds % 60).padStart(2, "0")}
                    </Badge>
                ) : (
                    <Badge>{scribe.left} included min left</Badge>
                )
            }
            className="mb-4"
        >
            {error && (
                <p className="mb-2 text-xs text-status-danger">{error}</p>
            )}
            {step === "idle" && (
                <>
                    {scribe.declinedBefore && (
                        <p className="mb-2 text-xs text-status-warning">
                            This patient declined the AI scribe last time. Only
                            ask if they raise it.
                        </p>
                    )}
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => setStep("ask")}
                    >
                        Use AI scribe
                    </Button>
                </>
            )}
            {step === "ask" && (
                <div className="text-sm">
                    <p className="mb-2">
                        Ask the patient:{" "}
                        <i>
                            &ldquo;May I use an AI assistant to help write my
                            notes? It listens to our conversation; the recording
                            is not kept and I check every word.&rdquo;
                        </i>
                    </p>
                    <div className="flex gap-2">
                        <Button size="sm" onClick={() => answer(true)}>
                            Patient agreed — start
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => answer(false)}
                        >
                            Patient declined
                        </Button>
                    </div>
                </div>
            )}
            {step === "recording" && (
                <Button size="sm" onClick={stop}>
                    Stop and draft note
                </Button>
            )}
            {step === "working" && (
                <p className="text-sm text-muted">Transcribing and drafting…</p>
            )}
            {step === "declined" && (
                <p className="text-sm text-muted">
                    Recorded that the patient declined. The AI scribe was not
                    used.
                </p>
            )}
            {step === "draft" && draft && (
                <div className="text-sm">
                    <p className="mb-2 text-xs text-status-warning">
                        AI draft — check every line before saving. Nothing is in
                        the note until you use it and save.
                    </p>
                    {(
                        [
                            "history",
                            "examination",
                            "assessment",
                            "plan",
                        ] as const
                    ).map((k) => (
                        <p key={k} className="mb-1">
                            <b className="capitalize">{k}:</b> {draft[k] || "—"}
                        </p>
                    ))}
                    {draft.icd10.length > 0 && (
                        <p className="mb-2 flex flex-wrap items-center gap-2">
                            Suggested ICD-10:
                            {draft.icd10.map((c) => (
                                <button
                                    key={c.code}
                                    className="rounded border border-line px-2 py-0.5 text-xs"
                                    onClick={() =>
                                        onAddDiagnosis(c.code, c.description)
                                    }
                                >
                                    + {c.code} {c.description}
                                </button>
                            ))}
                        </p>
                    )}
                    <div className="flex gap-2">
                        <Button size="sm" onClick={() => close(true)}>
                            Use draft in note
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => close(false)}
                        >
                            Discard
                        </Button>
                        {sessionId && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={async () => {
                                    const r = await scribePost(
                                        `/scribe/${sessionId}/redraft`,
                                    ).catch((e) => {
                                        setError((e as Error).message);
                                        return null;
                                    });
                                    if (r?.draft) setDraft(r.draft);
                                }}
                            >
                                Redraft
                            </Button>
                        )}
                    </div>
                </div>
            )}
        </Card>
    );
}
