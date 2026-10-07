import type { Room } from "livekit-client";
import { type MutableRefObject, useRef, useState } from "react";
import {
    scribePost,
    startRecording,
    uploadRecording,
    waitForDraft,
} from "@/lib/scribe";

interface Props {
    role: "doctor" | "patient";
    appointmentId: string;
    roomRef: MutableRefObject<Room | null>;
    scribe: { id: string; status: string } | null;
    onChange: () => void;
}

/**
 * AI scribe during a video/audio call. The doctor asks; the patient agrees or declines on their own
 * screen; both see when it is on. The doctor's browser mixes both sides of the call for the recording.
 */
export function CallScribe({
    role,
    appointmentId,
    roomRef,
    scribe,
    onChange,
}: Props) {
    const [recording, setRecording] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const rec = useRef<{
        stop: () => Promise<{ blob: Blob; seconds: number }>;
    } | null>(null);
    const ctx = useRef<AudioContext | null>(null);
    const status = scribe?.status ?? null;

    if (role === "patient") {
        if (status === "awaiting" && scribe) {
            const answer = (a: "agree" | "decline") =>
                scribePost(`/my/scribe/${scribe.id}/${a}`)
                    .then(onChange)
                    .catch((e) => setMessage((e as Error).message));
            return (
                <div className="mx-6 mb-3 rounded-lg bg-surface/10 p-3 text-sm">
                    Your doctor would like to use an AI scribe to help write
                    your notes. It listens to this call; the recording is not
                    kept and your doctor checks everything. It is your choice.
                    <div className="mt-2 flex gap-2">
                        <button
                            className="rounded bg-teal px-3 py-1"
                            onClick={() => answer("agree")}
                        >
                            Agree
                        </button>
                        <button
                            className="rounded bg-surface/20 px-3 py-1"
                            onClick={() => answer("decline")}
                        >
                            Decline
                        </button>
                    </div>
                    {message && <p className="mt-1 text-xs">{message}</p>}
                </div>
            );
        }
        return status === "created" ? (
            <div className="mx-6 mb-3 text-xs text-amber-300">
                ● AI scribe on — ask your doctor to stop it at any time.
            </div>
        ) : null;
    }

    const ask = () =>
        scribePost(`/appointments/${appointmentId}/scribe/request`, {
            source: "call",
        })
            .then(onChange)
            .catch((e) => setMessage((e as Error).message));
    const start = () => {
        const room = roomRef.current;
        if (!room) return;
        const audio = new AudioContext();
        const dest = audio.createMediaStreamDestination();
        const add = (t?: MediaStreamTrack) =>
            t &&
            audio.createMediaStreamSource(new MediaStream([t])).connect(dest);
        room.localParticipant.audioTrackPublications.forEach((p) =>
            add(p.track?.mediaStreamTrack),
        );
        room.remoteParticipants.forEach((rp) =>
            rp.audioTrackPublications.forEach((p) =>
                add(p.track?.mediaStreamTrack),
            ),
        );
        ctx.current = audio;
        rec.current = startRecording(dest.stream);
        setRecording(true);
    };
    const stop = async () => {
        if (!rec.current || !scribe) return;
        setRecording(false);
        setMessage("Transcribing and drafting…");
        const { blob, seconds } = await rec.current.stop();
        void ctx.current?.close();
        try {
            const r = await uploadRecording(scribe.id, blob, seconds);
            const done =
                r.status === "processing" ? await waitForDraft(scribe.id) : r;
            if (done.status === "failed")
                throw new Error(
                    done.error ??
                        "The recording could not be processed. Nothing was charged.",
                );
            setMessage("Draft ready — review it in the consultation note.");
        } catch (e) {
            setMessage((e as Error).message);
        }
        onChange();
    };

    return (
        <div className="mx-6 mb-3 flex items-center gap-3 text-sm">
            {!scribe ||
            ["declined", "discarded", "accepted", "drafted", "failed"].includes(
                status ?? "",
            ) ? (
                <button
                    className="rounded bg-surface/15 px-3 py-1"
                    onClick={ask}
                >
                    AI scribe
                </button>
            ) : status === "awaiting" ? (
                <span className="text-white/70">
                    Waiting for the patient to agree…
                </span>
            ) : status === "created" ? (
                recording ? (
                    <button
                        className="rounded bg-red-600 px-3 py-1"
                        onClick={stop}
                    >
                        ● Stop and draft
                    </button>
                ) : (
                    <button
                        className="rounded bg-teal px-3 py-1"
                        onClick={start}
                    >
                        Patient agreed — start recording
                    </button>
                )
            ) : null}
            {status === "declined" && (
                <span className="text-xs text-white/60">
                    The patient declined.
                </span>
            )}
            {message && (
                <span className="text-xs text-white/70">{message}</span>
            )}
        </div>
    );
}
