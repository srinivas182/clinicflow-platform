import { Head, router } from "@inertiajs/react";
import { Room, RoomEvent, Track, type RemoteTrack } from "livekit-client";
import { Mic, MicOff, PhoneOff, Video, VideoOff } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { useRealtime } from "@/lib/realtime";
import { CallScribe } from "@/components/CallScribe";
import { Button } from "@/components/ui";

interface Props {
    appointmentId: string;
    serverUrl: string;
    token: string | null;
    startsAt: string;
    endsAt: string;
    graceMinutes: number;
    stateUrl: string;
    type: "video" | "audio";
    role: "doctor" | "patient";
    other: string;
    leaveUrl: string;
}

/**
 * The call runs inside Clinic Flow; only the media goes through LiveKit.
 * Adaptive streaming lowers video quality on weak connections.
 */
export default function Call({
    appointmentId,
    serverUrl,
    token,
    startsAt,
    endsAt: initialEnd,
    graceMinutes,
    stateUrl,
    type,
    role,
    other,
    leaveUrl,
}: Props) {
    const [now, setNow] = useState(() => Date.now());
    const [endsAt, setEndsAt] = useState(initialEnd);
    const [extensionPayUrl, setExtensionPayUrl] = useState<string | null>(null);
    const startMs = new Date(startsAt).getTime();
    const endMs = new Date(endsAt).getTime();
    const hardEndMs = endMs + graceMinutes * 60000;
    const minutesLeft = Math.ceil((endMs - now) / 60000);
    const preview = useRef<HTMLVideoElement>(null);
    const [deviceOk, setDeviceOk] = useState<boolean | null>(null);

    const fetchState = () =>
        fetch(stateUrl, { headers: { Accept: "application/json" } })
            .then((r) => r.json())

            .then(
                (d: {
                    endsAt: string;
                    extensionPayUrl: string | null;
                    scribe?: { id: string; status: string } | null;
                }) => {
                    setScribe(d.scribe ?? null);

                    setEndsAt(d.endsAt);

                    setExtensionPayUrl(d.extensionPayUrl);
                },
            )

            .catch(() => undefined);

    // Scribe consent/progress and extensions arrive instantly; a slower check remains as a safety net.

    const live = useRealtime(
        `call.${appointmentId}`,
        "call.changed",
        fetchState,
    );

    useEffect(() => {
        const t = setInterval(() => setNow(Date.now()), 1000);
        const s = setInterval(fetchState, live ? 30000 : 5000);
        return () => {
            clearInterval(t);
            clearInterval(s);
        };
    }, [stateUrl, live]);

    const testDevices = async () => {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: type === "video",
            });
            if (preview.current && type === "video")
                preview.current.srcObject = stream;
            setDeviceOk(true);
        } catch {
            setDeviceOk(false);
        }
    };
    const remoteRef = useRef<HTMLDivElement>(null);
    const localRef = useRef<HTMLDivElement>(null);
    const [scribe, setScribe] = useState<{ id: string; status: string } | null>(
        null,
    );
    const refreshScribe = () =>
        fetch(stateUrl, { headers: { Accept: "application/json" } })
            .then((r) => r.json())
            .then((d: { scribe?: { id: string; status: string } | null }) =>
                setScribe(d.scribe ?? null),
            )
            .catch(() => undefined);
    const roomRef = useRef<Room | null>(null);
    const [joined, setJoined] = useState(false);
    const [otherHere, setOtherHere] = useState(false);
    const [mic, setMic] = useState(true);
    const [cam, setCam] = useState(type === "video");
    const [error, setError] = useState<string | null>(null);

    const join = async () => {
        const room = new Room({ adaptiveStream: true, dynacast: true });
        roomRef.current = room;
        room.on(RoomEvent.TrackSubscribed, (track: RemoteTrack) => {
            const el = track.attach();
            el.className =
                track.kind === Track.Kind.Video
                    ? "h-full w-full rounded-xl object-cover"
                    : "hidden";
            remoteRef.current?.appendChild(el);
        });
        room.on(RoomEvent.TrackUnsubscribed, (track: RemoteTrack) =>
            track.detach().forEach((el) => el.remove()),
        );
        room.on(RoomEvent.ParticipantConnected, () => setOtherHere(true));
        room.on(RoomEvent.ParticipantDisconnected, () => setOtherHere(false));
        room.on(RoomEvent.Disconnected, () => setJoined(false));
        try {
            await room.connect(serverUrl, token ?? "");
            setOtherHere(room.remoteParticipants.size > 0);
            await room.localParticipant.setMicrophoneEnabled(true);
            if (type === "video") {
                const pub = await room.localParticipant.setCameraEnabled(true);
                const track = pub?.track;
                if (track && localRef.current) {
                    const el = track.attach();
                    el.className = "h-full w-full rounded-lg object-cover";
                    localRef.current.replaceChildren(el);
                }
            }
            setJoined(true);
        } catch {
            setError(
                "Could not connect. Check your internet connection and allow microphone and camera access.",
            );
        }
    };

    const leave = async () => {
        await roomRef.current?.disconnect();
        window.location.href = leaveUrl;
    };

    useEffect(() => () => void roomRef.current?.disconnect(), []);
    useEffect(() => {
        // The booked time plus grace is over: leave politely (the server closes the room too).
        if (joined && now > hardEndMs) void leave();
    }, [now, joined, hardEndMs]);

    if (!token) {
        const secs = Math.max(0, Math.round((startMs - now) / 1000));
        return (
            <div className="flex min-h-screen flex-col items-center justify-center bg-[#0b1f1d] px-6 text-center text-white">
                <Head title="Waiting room" />
                <h1 className="mb-1 text-2xl font-semibold">
                    Your consult starts in {Math.floor(secs / 60)}:
                    {String(secs % 60).padStart(2, "0")}
                </h1>
                <p className="mb-5 text-sm text-white/70">
                    You can join at the start time. Check your camera and
                    microphone now — this does not connect or charge anything.
                </p>
                {type === "video" && (
                    <video
                        ref={preview}
                        autoPlay
                        muted
                        playsInline
                        className="mb-4 h-48 w-64 rounded-lg bg-black object-cover"
                    />
                )}
                <Button variant="secondary" onClick={testDevices}>
                    Test camera and microphone
                </Button>
                {deviceOk === true && (
                    <p className="mt-3 text-sm text-emerald-300">
                        Your devices work.
                    </p>
                )}
                {deviceOk === false && (
                    <p className="mt-3 text-sm text-red-300">
                        Allow camera and microphone access in your browser
                        settings.
                    </p>
                )}
                {secs === 0 && (
                    <Button
                        className="mt-5"
                        onClick={() => window.location.reload()}
                    >
                        Join now
                    </Button>
                )}
            </div>
        );
    }

    return (
        <div className="flex min-h-screen flex-col bg-[#0b1f1d] text-white">
            <Head title="Online consult" />
            <header className="flex items-center gap-3 px-6 py-4">
                <span className="font-semibold">
                    Clinic Flow · {type === "video" ? "Video" : "Audio"} consult
                </span>
                <span className="text-sm text-white/60">
                    {joined
                        ? otherHere
                            ? `Connected with ${other}`
                            : `Waiting for ${other}…`
                        : "Not connected"}
                </span>
                {joined && (
                    <span
                        className={`ml-auto text-sm ${minutesLeft <= 2 ? "font-semibold text-amber-300" : "text-white/60"}`}
                    >
                        {minutesLeft > 0
                            ? `${minutesLeft} min left`
                            : "Booked time is over — please finish up"}
                    </span>
                )}
                {joined &&
                    role === "doctor" &&
                    !extensionPayUrl &&
                    minutesLeft <= 5 && (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.post(
                                    `/telemedicine/${appointmentId}/extend`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Extend
                        </Button>
                    )}
                {joined && role === "patient" && extensionPayUrl && (
                    <a
                        href={extensionPayUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="rounded-md bg-amber-400 px-3 py-1 text-sm font-medium text-black"
                    >
                        Pay to add time
                    </a>
                )}
            </header>
            {joined && (
                <CallScribe
                    role={role}
                    appointmentId={appointmentId}
                    roomRef={roomRef}
                    scribe={scribe}
                    onChange={refreshScribe}
                />
            )}
            <main className="relative flex flex-1 items-center justify-center px-6 pb-6">
                {!joined ? (
                    <div className="max-w-md text-center">
                        <h1 className="mb-2 text-2xl font-semibold">
                            Ready to join?
                        </h1>
                        <p className="mb-5 text-sm text-white/70">
                            {type === "video"
                                ? "A video consult uses about 15 MB of data per minute (around 200 MB for 15 minutes). Wi-Fi is best."
                                : "An audio consult uses less than 1 MB of data per minute."}{" "}
                            Nothing is recorded.
                        </p>
                        {error && (
                            <p
                                role="alert"
                                className="mb-4 text-sm text-red-300"
                            >
                                {error}
                            </p>
                        )}
                        <Button onClick={join}>
                            {role === "doctor"
                                ? "Start consult"
                                : "Join consult"}
                        </Button>
                    </div>
                ) : (
                    <>
                        <div
                            ref={remoteRef}
                            className="flex h-[70vh] w-full max-w-5xl items-center justify-center rounded-xl bg-black/40"
                        >
                            {(!otherHere || type === "audio") && (
                                <span className="text-white/60">
                                    {otherHere
                                        ? `${other} — audio only`
                                        : `Waiting for ${other} to join…`}
                                </span>
                            )}
                        </div>
                        {type === "video" && (
                            <div
                                ref={localRef}
                                className="absolute bottom-24 right-10 h-32 w-48 overflow-hidden rounded-lg border border-white/30 bg-black"
                            />
                        )}
                        <div className="absolute bottom-8 flex gap-3">
                            <Button
                                variant="secondary"
                                aria-label={mic ? "Mute" : "Unmute"}
                                onClick={async () => {
                                    await roomRef.current?.localParticipant.setMicrophoneEnabled(
                                        !mic,
                                    );
                                    setMic(!mic);
                                }}
                            >
                                {mic ? (
                                    <Mic className="size-5" />
                                ) : (
                                    <MicOff className="size-5" />
                                )}
                            </Button>
                            {type === "video" && (
                                <Button
                                    variant="secondary"
                                    aria-label={
                                        cam ? "Camera off" : "Camera on"
                                    }
                                    onClick={async () => {
                                        await roomRef.current?.localParticipant.setCameraEnabled(
                                            !cam,
                                        );
                                        setCam(!cam);
                                    }}
                                >
                                    {cam ? (
                                        <Video className="size-5" />
                                    ) : (
                                        <VideoOff className="size-5" />
                                    )}
                                </Button>
                            )}
                            <Button
                                variant="danger"
                                onClick={leave}
                                icon={<PhoneOff className="size-5" />}
                            >
                                Leave
                            </Button>
                        </div>
                    </>
                )}
            </main>
        </div>
    );
}
