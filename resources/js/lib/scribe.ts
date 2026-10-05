/** Small helpers for the AI scribe: JSON requests with the CSRF token, and a timed recorder. */
export type ScribeDraft = {
    history: string;
    examination: string;
    assessment: string;
    plan: string;
    icd10: { code: string; description: string }[];
};
export type ScribeState = {
    session: string | null;
    status: string;
    minutes?: number;
    error?: string | null;
    draft?: ScribeDraft | null;
};

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m?.[1] ? decodeURIComponent(m[1]) : "";
}

export async function scribePost(
    url: string,
    body?: Record<string, unknown> | FormData,
): Promise<
    ScribeState & { message?: string; errors?: Record<string, string[]> }
> {
    const isForm = body instanceof FormData;
    const res = await fetch(url, {
        method: "POST",
        headers: {
            Accept: "application/json",
            "X-XSRF-TOKEN": xsrf(),
            ...(isForm ? {} : { "Content-Type": "application/json" }),
        },
        body: isForm ? body : JSON.stringify(body ?? {}),
        credentials: "same-origin",
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const first = data?.errors
            ? (Object.values(data.errors)[0] as string[])[0]
            : data?.message;
        throw new Error(first || "Something went wrong. Please try again.");
    }
    return data;
}

/** Records a MediaStream; stop() resolves with the audio and its length in seconds. */
export function startRecording(stream: MediaStream): {
    stop: () => Promise<{ blob: Blob; seconds: number }>;
} {
    const mime = MediaRecorder.isTypeSupported("audio/webm")
        ? "audio/webm"
        : "";
    const recorder = new MediaRecorder(
        stream,
        mime ? { mimeType: mime } : undefined,
    );
    const chunks: Blob[] = [];
    const started = Date.now();
    recorder.ondataavailable = (e) => e.data.size > 0 && chunks.push(e.data);
    recorder.start(1000);
    return {
        stop: () =>
            new Promise((resolve) => {
                recorder.onstop = () =>
                    resolve({
                        blob: new Blob(chunks, {
                            type: recorder.mimeType || "audio/webm",
                        }),
                        seconds: Math.max(
                            1,
                            Math.round((Date.now() - started) / 1000),
                        ),
                    });
                recorder.stop();
            }),
    };
}

export async function uploadRecording(
    sessionId: string,
    blob: Blob,
    seconds: number,
): Promise<ScribeState> {
    const form = new FormData();
    form.append("audio", blob, "consult.webm");
    form.append("seconds", String(seconds));
    return scribePost(`/scribe/${sessionId}/audio`, form);
}
