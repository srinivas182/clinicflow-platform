import { usePage } from "@inertiajs/react";
import { useEffect, useRef } from "react";

declare global {
    interface Window {
        turnstile?: {
            render: (
                el: HTMLElement,
                options: Record<string, unknown>,
            ) => string;
            remove: (id: string) => void;
        };
    }
}

const SCRIPT =
    "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
let loading: Promise<void> | null = null;

function loadScript(): Promise<void> {
    if (window.turnstile) return Promise.resolve();
    loading ??= new Promise((resolve, reject) => {
        const s = document.createElement("script");
        s.src = SCRIPT;
        s.async = true;
        s.onload = () => resolve();
        s.onerror = () => reject(new Error("Security check could not load"));
        document.head.appendChild(s);
    });
    return loading;
}

/**
 * Cloudflare Turnstile check on public forms, shown only when bot protection is on
 * (Admin → Security). Usually invisible to real people; passes a token to the form.
 */
export function HumanCheck({
    onToken,
    error,
}: {
    onToken: (token: string) => void;
    error?: string;
}) {
    const bot = usePage<{ botProtection?: { siteKey: string } | null }>().props
        .botProtection;
    const box = useRef<HTMLDivElement>(null);
    const callback = useRef(onToken);
    callback.current = onToken;

    useEffect(() => {
        if (!bot || !box.current) return;
        let id: string | undefined;
        let gone = false;
        loadScript()
            .then(() => {
                if (gone || !box.current || !window.turnstile) return;
                id = window.turnstile.render(box.current, {
                    sitekey: bot.siteKey,
                    callback: (token: string) => callback.current(token),
                    "expired-callback": () => callback.current(""),
                    "error-callback": () => callback.current(""),
                });
            })
            .catch(() => callback.current(""));
        return () => {
            gone = true;
            if (id && window.turnstile) window.turnstile.remove(id);
        };
    }, [bot?.siteKey]);

    if (!bot) return null;

    return (
        <div className="my-3">
            <div ref={box} />
            {error && (
                <p role="alert" className="mt-1 text-xs text-status-danger">
                    {error}
                </p>
            )}
        </div>
    );
}
