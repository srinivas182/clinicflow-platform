import { Head, Link } from "@inertiajs/react";
import QRCode from "qrcode";
import { useEffect, useState } from "react";
import { Badge, Button, Card } from "@/components/ui";
import { scribePost as post } from "@/lib/scribe";

interface Props {
    enabled: boolean;
    required: boolean;
    recoveryLeft: number;
    trustedDevices: {
        id: number;
        label: string;
        lastUsed: string;
        expires: string;
    }[];
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
    trustedDevices,
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
                <p role="alert" className="mb-3 text-sm text-status-danger">
                    {error}
                </p>
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
            <Card title="Trusted devices" className="mt-4">
                {trustedDevices.length === 0 && (
                    <p className="text-sm text-muted">
                        No trusted devices. Tick “Trust this device” when
                        entering a sign-in code to skip it for 30 days.
                    </p>
                )}
                {trustedDevices.map((d) => (
                    <div
                        key={d.id}
                        className="flex items-center gap-2 py-1 text-sm"
                    >
                        <span className="flex-1">
                            {d.label || "Unknown browser"}{" "}
                            <span className="text-muted">
                                · last used {d.lastUsed} · until {d.expires}
                            </span>
                        </span>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                run(async () => {
                                    await post(
                                        `/account/security/devices/${d.id}/forget`,
                                    );
                                    window.location.reload();
                                })
                            }
                        >
                            Remove
                        </Button>
                    </div>
                ))}
            </Card>
            <Card title="Change password" className="mt-4">
                <form
                    className="flex flex-wrap items-end gap-2 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const f = new FormData(e.currentTarget);
                        run(async () => {
                            await post("/account/security/password", {
                                current_password: f.get("current"),
                                password: f.get("next"),
                                password_confirmation: f.get("repeat"),
                            });
                            window.alert(
                                "Password changed. Your other browsers and devices have been signed out.",
                            );
                            (e.target as HTMLFormElement).reset();
                        });
                    }}
                >
                    <label>
                        Current password
                        <br />
                        <input
                            name="current"
                            type="password"
                            autoComplete="current-password"
                            required
                            className="rounded-md border border-line px-2 py-1"
                        />
                    </label>
                    <label>
                        New password
                        <br />
                        <input
                            name="next"
                            type="password"
                            autoComplete="new-password"
                            required
                            minLength={10}
                            className="rounded-md border border-line px-2 py-1"
                        />
                    </label>
                    <label>
                        Repeat it
                        <br />
                        <input
                            name="repeat"
                            type="password"
                            autoComplete="new-password"
                            required
                            minLength={10}
                            className="rounded-md border border-line px-2 py-1"
                        />
                    </label>
                    <Button size="sm">Change password</Button>
                </form>
                <p className="mt-2 text-xs text-muted">
                    10+ characters with letters and numbers. Passwords known
                    from data breaches are refused.
                </p>
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
