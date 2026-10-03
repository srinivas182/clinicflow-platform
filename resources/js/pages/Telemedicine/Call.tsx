import { Head } from '@inertiajs/react';
import { Room, RoomEvent, Track, type RemoteTrack } from 'livekit-client';
import { Mic, MicOff, PhoneOff, Video, VideoOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui';

interface Props {
    serverUrl: string;
    token: string;
    type: 'video' | 'audio';
    role: 'doctor' | 'patient';
    other: string;
    leaveUrl: string;
}

/**
 * The call runs inside Clinic Flow; only the media goes through LiveKit.
 * Adaptive streaming lowers video quality on weak connections.
 */
export default function Call({ serverUrl, token, type, role, other, leaveUrl }: Props) {
    const remoteRef = useRef<HTMLDivElement>(null);
    const localRef = useRef<HTMLDivElement>(null);
    const roomRef = useRef<Room | null>(null);
    const [joined, setJoined] = useState(false);
    const [otherHere, setOtherHere] = useState(false);
    const [mic, setMic] = useState(true);
    const [cam, setCam] = useState(type === 'video');
    const [error, setError] = useState<string | null>(null);

    const join = async () => {
        const room = new Room({ adaptiveStream: true, dynacast: true });
        roomRef.current = room;
        room.on(RoomEvent.TrackSubscribed, (track: RemoteTrack) => {
            const el = track.attach();
            el.className = track.kind === Track.Kind.Video ? 'h-full w-full rounded-xl object-cover' : 'hidden';
            remoteRef.current?.appendChild(el);
        });
        room.on(RoomEvent.TrackUnsubscribed, (track: RemoteTrack) => track.detach().forEach((el) => el.remove()));
        room.on(RoomEvent.ParticipantConnected, () => setOtherHere(true));
        room.on(RoomEvent.ParticipantDisconnected, () => setOtherHere(false));
        room.on(RoomEvent.Disconnected, () => setJoined(false));
        try {
            await room.connect(serverUrl, token);
            setOtherHere(room.remoteParticipants.size > 0);
            await room.localParticipant.setMicrophoneEnabled(true);
            if (type === 'video') {
                const pub = await room.localParticipant.setCameraEnabled(true);
                const track = pub?.track;
                if (track && localRef.current) {
                    const el = track.attach();
                    el.className = 'h-full w-full rounded-lg object-cover';
                    localRef.current.replaceChildren(el);
                }
            }
            setJoined(true);
        } catch {
            setError('Could not connect. Check your internet connection and allow microphone and camera access.');
        }
    };

    const leave = async () => {
        await roomRef.current?.disconnect();
        window.location.href = leaveUrl;
    };

    useEffect(() => () => void roomRef.current?.disconnect(), []);

    return (
        <div className="flex min-h-screen flex-col bg-[#0b1f1d] text-white">
            <Head title="Online consult" />
            <header className="flex items-center gap-3 px-6 py-4">
                <span className="font-semibold">Clinic Flow · {type === 'video' ? 'Video' : 'Audio'} consult</span>
                <span className="text-sm text-white/60">{joined ? (otherHere ? `Connected with ${other}` : `Waiting for ${other}…`) : 'Not connected'}</span>
            </header>
            <main className="relative flex flex-1 items-center justify-center px-6 pb-6">
                {!joined ? (
                    <div className="max-w-md text-center">
                        <h1 className="mb-2 text-2xl font-semibold">Ready to join?</h1>
                        <p className="mb-5 text-sm text-white/70">
                            {type === 'video'
                                ? 'A video consult uses about 15 MB of data per minute (around 200 MB for 15 minutes). Wi-Fi is best.'
                                : 'An audio consult uses less than 1 MB of data per minute.'}{' '}
                            Nothing is recorded.
                        </p>
                        {error && <p role="alert" className="mb-4 text-sm text-red-300">{error}</p>}
                        <Button onClick={join}>{role === 'doctor' ? 'Start consult' : 'Join consult'}</Button>
                    </div>
                ) : (
                    <>
                        <div ref={remoteRef} className="flex h-[70vh] w-full max-w-5xl items-center justify-center rounded-xl bg-black/40">
                            {(!otherHere || type === 'audio') && <span className="text-white/60">{otherHere ? `${other} — audio only` : `Waiting for ${other} to join…`}</span>}
                        </div>
                        {type === 'video' && <div ref={localRef} className="absolute bottom-24 right-10 h-32 w-48 overflow-hidden rounded-lg border border-white/30 bg-black" />}
                        <div className="absolute bottom-8 flex gap-3">
                            <Button variant="secondary" aria-label={mic ? 'Mute' : 'Unmute'} onClick={async () => { await roomRef.current?.localParticipant.setMicrophoneEnabled(!mic); setMic(!mic); }}>
                                {mic ? <Mic className="size-5" /> : <MicOff className="size-5" />}
                            </Button>
                            {type === 'video' && (
                                <Button variant="secondary" aria-label={cam ? 'Camera off' : 'Camera on'} onClick={async () => { await roomRef.current?.localParticipant.setCameraEnabled(!cam); setCam(!cam); }}>
                                    {cam ? <Video className="size-5" /> : <VideoOff className="size-5" />}
                                </Button>
                            )}
                            <Button variant="danger" onClick={leave} icon={<PhoneOff className="size-5" />}>
                                Leave
                            </Button>
                        </div>
                    </>
                )}
            </main>
        </div>
    );
}
