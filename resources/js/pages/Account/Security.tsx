import { Head, Link } from "@inertiajs/react";
import QRCode from "qrcode";
import { useEffect, useState } from "react";
import { Badge, Button, Card } from "@/components/ui";
import { scribePost as post } from "@/lib/scribe";

interface Props {
    enabled: boolean;
    required: boolean;
    recoveryLeft: number;
    signIns: {
        at: string;
        ip: string | null;
        device: string;
        newDevice: boolean;
    }[];
}

/** Account → Security: sign in with an authenticator app (Google or Microsoft Authenticator, Authy, 1Password…). */
export default function Security({
    enabled: initiallyEnabled,
    required,
    recoveryLeft,
    signIns,
}: Props) {
    const [enabled, setEnabled] = useState(initiallyEnabled);
    const [setup, setSetup] = useState<{ secret: string; uri: string } | null>(
        null,
    );
    const [qr, setQr] = useState<string | null>(null);
    const [code, setCode] = useState("");
    const [codes, setCodes] = useState<string[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (setup)
            void QRCode.toDataURL(setup.uri, { margin: 1, width: 200 }).then(
                setQr,
            );
    }, [setup]);

    const run = async (fn: () => Promise<void>) => {
        setError(null);
        try {
            await fn();
        } catch (e) {
            setError((e as Error).message);
        }
    };
    const input =
        "w-40 rounded-md border border-line px-2 py-1 text-center font-mono tracking-widest";

    return (
        <div className="mx-auto max-w-2xl px-6 py-10">
            <Head title="Security" />
            <Link href="/workspaces" className="text-sm text-teal-deep">
                ← Back to workspaces
            </Link>
            <h1 className="mb-1 mt-3 text-2xl font-semibold">
                Sign-in security
            </h1>
            <p className="mb-5 text-sm text-muted">
                An authenticator app on your phone gives you a new 6-digit code
                every 30 seconds. It is safer than codes by SMS or email.
            </p>
            {required && !enabled && (
                <p className="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                    Your role needs an authenticator app. Set it up to continue.
                </p>
            )}
            {error && (
                <p className="mb-3 text-sm text-status-danger">{error}</p>
            )}
            <Card
                title="Authenticator app"
                aside={
                    <Badge tone={enabled ? "success" : "neutral"}>
                        {enabled ? "on" : "off"}
                    </Badge>
                }
            >
                {codes && (
                    <div className="mb-4 rounded-lg border border-line p-3">
                        <p className="mb-2 text-sm font-medium">
                            Your recovery codes — save them somewhere safe. Each
                            works once if you lose your phone. They will not be
                            shown again.
                        </p>
                        <pre className="mb-2 grid grid-cols-2 gap-1 font-mono text-sm">
                            {codes.join("\n")}
                        </pre>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                void navigator.clipboard.writeText(
                                    codes.join("\n"),
                                )
                            }
                        >
                            Copy
                        </Button>
                    </div>
                )}
                {!enabled && !setup && (
                    <Button
                        size="sm"
                        onClick={() =>
                            run(async () =>
                                setSetup(
                                    (await post(
                                        "/account/security/authenticator",
                                    )) as unknown as {
                                        secret: string;
                                        uri: string;
                                    },
                                ),
                            )
                        }
                    >
                        Set up authenticator app
                    </Button>
                )}
                {!enabled && setup && (
                    <div className="text-sm">
                        <p className="mb-2">
                            1. Scan this code with your authenticator app.
                        </p>
                        {qr && (
                            <img
                                src={qr}
                                alt="QR code for your authenticator app"
                                className="mb-2 size-48"
                            />
                        )}
                        <p className="mb-3 text-xs text-muted">
                            Can’t scan? Enter this key:{" "}
                            <span className="font-mono">
                                {setup.secret.match(/.{1,4}/g)?.join(" ")}
                            </span>
                        </p>
                        <p className="mb-2">
                            2. Enter the 6-digit code the app shows.
                        </p>
                        <div className="flex items-center gap-2">
                            <input
                                aria-label="Code from your app"
                                inputMode="numeric"
                                maxLength={6}
                                className={input}
                                value={code}
                                onChange={(e) =>
                                    setCode(e.target.value.replace(/\D/g, ""))
                                }
                            />
                            <Button
                                size="sm"
                                disabled={code.length !== 6}
                                onClick={() =>
                                    run(async () => {
                                        const r = (await post(
                                            "/account/security/authenticator/confirm",
                                            { code },
                                        )) as unknown as {
                                            recoveryCodes: string[];
                                        };
                                        setCodes(r.recoveryCodes);
                                        setEnabled(true);
                                        setSetup(null);
                                        setCode("");
                                    })
                                }
                            >
                                Confirm
                            </Button>
                        </div>
                    </div>
                )}
                {enabled && !codes && (
                    <div className="text-sm">
                        <p className="mb-3">
                            Signing in asks for a code from your app.{" "}
                            {recoveryLeft} recovery code
                            {recoveryLeft === 1 ? "" : "s"} left.
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <input
                                aria-label="Current code"
                                placeholder="Code"
                                className={input}
                                value={code}
                                onChange={(e) =>
                                    setCode(e.target.value.trim().toLowerCase())
                                }
                            />
                            <Button
                                size="sm"
                                variant="secondary"
                                disabled={!code}
                                onClick={() =>
                                    run(async () => {
                                        setCodes(
                                            (
                                                (await post(
                                                    "/account/security/recovery-codes",
                                                    { code },
                                                )) as unknown as {
                                                    recoveryCodes: string[];
                                                }
                                            ).recoveryCodes,
                                        );
                                        setCode("");
                                    })
                                }
                            >
                                New recovery codes
                            </Button>
                            {!required && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={!code}
                                    onClick={() =>
                                        run(async () => {
                                            await post(
                                                "/account/security/authenticator/disable",
                                                { code },
                                            );
                                            setEnabled(false);
                                            setCode("");
                                        })
                                    }
                                >
                                    Turn off
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </Card>
            <Card title="Recent sign-ins" className="mt-4">
                {signIns.length === 0 && (
                    <p className="text-sm text-muted">
                        No sign-ins recorded yet.
                    </p>
                )}
                {signIns.map((e, i) => (
                    <p key={i} className="text-sm">
                        {e.at} · {e.ip ?? "unknown"} ·{" "}
                        <span className="text-muted">{e.device}</span>{" "}
                        {e.newDevice && (
                            <Badge tone="warning">new device</Badge>
                        )}
                    </p>
                ))}
                <div className="mt-3">
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() =>
                            run(async () => {
                                const password = window.prompt(
                                    "Enter your password to sign out every other browser and device",
                                );
                                if (!password) return;
                                await post(
                                    "/account/security/sign-out-others",
                                    { password },
                                );
                                window.alert(
                                    "Other browsers and devices will be signed out.",
                                );
                            })
                        }
                    >
                        Sign out other devices
                    </Button>
                </div>
            </Card>
        </div>
    );
}
