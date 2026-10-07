import { router, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";

type Realtime =
    | {
          key: string;
          host: string;
          port: number;
          tls: boolean;
          provider: string;
      }
    | null
    | undefined;

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let echo: any = null;

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m?.[1] ? decodeURIComponent(m[1]) : "";
}

/**
 * Listens on a private, practice-scoped channel (e.g. "queue" or "chat.<thread>") and calls onEvent
 * when the named event arrives. Returns true while connected, so pages can slow their safety-net polling.
 * Does nothing when real-time updates are off (pages keep polling as before).
 */
export function useRealtime(
    channel: string,
    event: string,
    onEvent: () => void,
): boolean {
    const config = (usePage().props as { realtime?: Realtime }).realtime;
    const [connected, setConnected] = useState(false);

    useEffect(() => {
        if (!config || !channel || typeof window === "undefined") return;
        let cancelled = false;
        let name = "";
        void (async () => {
            if (!echo) {
                const [{ default: Echo }, { default: Pusher }] =
                    await Promise.all([
                        import("laravel-echo"),
                        import("pusher-js"),
                    ]);
                (window as unknown as { Pusher: unknown }).Pusher = Pusher;
                echo = new Echo({
                    broadcaster: "reverb",
                    key: config.key,
                    wsHost: config.host,
                    wsPort: config.port,
                    wssPort: config.port,
                    forceTLS: config.tls,
                    enabledTransports: ["ws", "wss"],
                    authEndpoint: "/broadcasting/auth",
                    auth: { headers: { "X-XSRF-TOKEN": xsrf() } },
                });
            }
            if (cancelled) return;
            name = `provider.${config.provider}.${channel}`;
            echo.private(name).listen(`.${event}`, onEvent);
            const conn = echo.connector?.pusher?.connection;
            setConnected(conn?.state === "connected");
            conn?.bind("state_change", (s: { current: string }) =>
                setConnected(s.current === "connected"),
            );
        })();
        return () => {
            cancelled = true;
            if (echo && name) echo.leave(name);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [config?.provider, channel, event]);

    return connected;
}

/**
 * Refreshes page data when a real-time event arrives. While connected, a slow 60-second safety
 * refresh runs; when not connected (or real-time is off) it refreshes every fallbackMs as before.
 */
export function useLiveReload(
    channel: string,
    event: string,
    fallbackMs: number,
    only?: string[],
): boolean {
    const reload = () => router.reload(only ? { only } : {});
    const live = useRealtime(channel, event, reload);
    useEffect(() => {
        const t = setInterval(reload, live ? 60000 : fallbackMs);
        return () => clearInterval(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [live, fallbackMs]);

    return live;
}
