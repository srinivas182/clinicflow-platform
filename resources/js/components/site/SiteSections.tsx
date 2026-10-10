import { usePage } from "@inertiajs/react";
import {
    Activity,
    BarChart3,
    CalendarCheck,
    Check,
    ChevronDown,
    ClipboardList,
    Clock,
    FileCheck2,
    FlaskConical,
    Globe,
    type LucideIcon,
    MessageCircle,
    Pill,
    Receipt,
    ShieldCheck,
    Smartphone,
    Stethoscope,
    Truck,
    Users,
    Video,
    Wallet,
} from "lucide-react";
import { useEffect, useState } from "react";
import type { CSSProperties } from "react";
import type { SiteInfo, SiteLink, SiteSection } from "./types";

const ButtonLink = ({
    link,
    primary,
    colour,
}: {
    link: SiteLink;
    primary?: boolean;
    colour: string;
}) => (
    <a
        href={link.href}
        className={`inline-flex items-center rounded-lg px-5 py-2.5 text-sm font-semibold ${primary ? "text-white" : "border border-line bg-surface text-ink"}`}
        style={primary ? { background: colour } : undefined}
    >
        {link.label}
    </a>
);

function BookingSection({
    heading,
    colour,
}: {
    heading?: string;
    colour: string;
}) {
    const [data, setData] = useState<{
        bookUrl: string;
        doctors: { doctor: string; times: string[] }[];
    } | null>(null);
    useEffect(() => {
        fetch("/widget/slots", { headers: { Accept: "application/json" } })
            .then((r) => r.json())
            .then(setData)
            .catch(() => setData({ bookUrl: "/my", doctors: [] }));
    }, []);

    return (
        <section className="mx-auto max-w-3xl px-6 py-14">
            <h2 className="mb-4 text-2xl font-semibold">
                {heading ?? "Book an appointment"}
            </h2>
            {data === null && <p className="text-muted">Loading free times…</p>}
            {data?.doctors.length === 0 && (
                <p className="text-muted">
                    No free times this week online — please call us.
                </p>
            )}
            {data?.doctors.map((d) => (
                <div key={d.doctor} className="mb-3">
                    <p className="font-medium">{d.doctor}</p>
                    <div className="mt-1 flex flex-wrap gap-2">
                        {d.times.map((t) => (
                            <a
                                key={t}
                                href={data.bookUrl}
                                className="rounded-md px-3 py-1 text-sm text-white"
                                style={{ background: colour }}
                            >
                                {new Date(t).toLocaleString("en-ZA", {
                                    weekday: "short",
                                    day: "numeric",
                                    month: "short",
                                    hour: "2-digit",
                                    minute: "2-digit",
                                })}
                            </a>
                        ))}
                    </div>
                </div>
            ))}
            {data && (
                <a
                    href={data.bookUrl}
                    className="text-sm font-medium"
                    style={{ color: colour }}
                >
                    See all times
                </a>
            )}
        </section>
    );
}

function Section({ s, site }: { s: SiteSection; site: SiteInfo }) {
    const accent = { color: site.colour } as CSSProperties;

    switch (s.type) {
        case "hero":
            return (
                <section className="mx-auto grid max-w-6xl items-center gap-10 px-6 py-16 md:grid-cols-2">
                    <div>
                        {s.eyebrow && (
                            <p
                                className="text-sm font-semibold uppercase tracking-wide"
                                style={accent}
                            >
                                {s.eyebrow}
                            </p>
                        )}
                        <h1 className="mt-3 text-4xl leading-tight font-semibold tracking-tight md:text-5xl">
                            {s.heading}
                        </h1>
                        {s.text && (
                            <p className="mt-4 text-lg text-muted">{s.text}</p>
                        )}
                        <div className="mt-7 flex flex-wrap gap-3">
                            {s.primary && (
                                <ButtonLink
                                    link={s.primary}
                                    primary
                                    colour={site.colour}
                                />
                            )}
                            {s.secondary && (
                                <ButtonLink
                                    link={s.secondary}
                                    colour={site.colour}
                                />
                            )}
                        </div>
                    </div>
                    {s.image && (
                        <img
                            src={versioned(s.image)}
                            alt=""
                            className="w-full"
                        />
                    )}
                </section>
            );
        case "cards":
            return (
                <section className="bg-paper py-16">
                    <div className="mx-auto max-w-6xl px-6">
                        {s.heading && (
                            <h2 className="mb-8 text-3xl font-semibold">
                                {s.heading}
                            </h2>
                        )}
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            {s.items?.map((i, k) => (
                                <a
                                    key={k}
                                    href={i.href ?? "#"}
                                    className="rounded-2xl border border-line bg-surface p-5 hover:shadow-md"
                                >
                                    {i.image && (
                                        <img
                                            src={versioned(i.image)}
                                            alt=""
                                            className="mb-4 w-full rounded-xl"
                                        />
                                    )}
                                    <h3 className="text-lg font-semibold">
                                        {i.title}
                                    </h3>
                                    <p className="mt-1 text-sm text-muted">
                                        {i.text}
                                    </p>
                                </a>
                            ))}
                        </div>
                    </div>
                </section>
            );
        case "features":
            return (
                <section className="mx-auto max-w-6xl px-6 py-16">
                    {s.heading && (
                        <h2 className="mb-8 text-3xl font-semibold">
                            {s.heading}
                        </h2>
                    )}
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {s.items?.map((i, k) => (
                            <div
                                key={k}
                                className="rounded-xl border border-line p-5"
                            >
                                <span
                                    className="mb-3 grid size-9 place-items-center rounded-lg text-white"
                                    style={{ background: site.colour }}
                                >
                                    <FeatureIcon name={i.icon} />
                                </span>
                                <h3 className="font-semibold">{i.title}</h3>
                                <p className="mt-1 text-sm text-muted">
                                    {i.text}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>
            );
        case "steps":
            return (
                <section id={anchor(s.heading)} className="bg-paper py-16">
                    <div className="mx-auto max-w-6xl px-6">
                        {s.heading && (
                            <h2 className="mb-10 text-center text-3xl font-semibold">
                                {s.heading}
                            </h2>
                        )}
                        <ol
                            className={`grid gap-5 sm:grid-cols-2 ${STEP_COLUMNS[s.items?.length ?? 0] ?? "lg:grid-cols-4"}`}
                        >
                            {s.items?.map((i, k) => (
                                <li
                                    key={k}
                                    className="rounded-xl bg-surface p-6 text-center"
                                >
                                    <span
                                        className="mx-auto grid size-10 place-items-center rounded-full text-base font-bold text-white"
                                        style={{ background: site.colour }}
                                    >
                                        {k + 1}
                                    </span>
                                    <h3 className="mt-3 font-semibold">
                                        {i.title}
                                    </h3>
                                    <p className="mt-1 text-sm text-muted">
                                        {i.text}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>
            );
        case "split":
            return (
                <section className="mx-auto grid max-w-6xl items-center gap-10 px-6 py-16 md:grid-cols-2">
                    {s.image && (
                        <img
                            src={versioned(s.image)}
                            alt=""
                            className="w-full"
                        />
                    )}
                    <div>
                        <h2 className="text-3xl font-semibold">{s.heading}</h2>
                        {s.text && <p className="mt-3 text-muted">{s.text}</p>}
                        <ul className="mt-5 space-y-2">
                            {s.bullets?.map((b, k) => (
                                <li key={k} className="flex gap-2">
                                    <Check
                                        className="mt-0.5 size-5 flex-none"
                                        style={accent}
                                        aria-hidden="true"
                                    />
                                    {b}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            );
        case "faq":
            return (
                <section className="mx-auto max-w-3xl px-6 py-16">
                    {s.heading && (
                        <h2 className="mb-6 text-3xl font-semibold">
                            {s.heading}
                        </h2>
                    )}
                    {s.items?.map((i, k) => (
                        <details
                            key={k}
                            className="group border-b border-line py-4"
                        >
                            <summary className="flex cursor-pointer list-none items-center justify-between font-medium">
                                {i.question}
                                <ChevronDown
                                    className="size-4 transition group-open:rotate-180"
                                    aria-hidden="true"
                                />
                            </summary>
                            <p className="mt-2 text-muted">{i.answer}</p>
                        </details>
                    ))}
                </section>
            );
        case "cta":
            return (
                <section className="px-6 py-16">
                    <div
                        className="mx-auto max-w-5xl rounded-3xl px-8 py-12 text-center text-white"
                        style={{ background: site.colour }}
                    >
                        <h2 className="text-3xl font-semibold">{s.heading}</h2>
                        {s.text && (
                            <p className="mt-3 text-white/85">{s.text}</p>
                        )}
                        <div className="mt-6 flex justify-center gap-3">
                            {s.primary && (
                                <a
                                    href={s.primary.href}
                                    className="rounded-lg bg-surface px-5 py-2.5 text-sm font-semibold"
                                    style={accent}
                                >
                                    {s.primary.label}
                                </a>
                            )}
                            {s.secondary && (
                                <a
                                    href={s.secondary.href}
                                    className="rounded-lg border border-white/60 px-5 py-2.5 text-sm font-semibold"
                                >
                                    {s.secondary.label}
                                </a>
                            )}
                        </div>
                    </div>
                </section>
            );
        case "contact":
            return (
                <section className="mx-auto max-w-6xl px-6 py-16">
                    <h2 className="mb-6 text-3xl font-semibold">{s.heading}</h2>
                    <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        {[
                            ["Phone", site.contact.phone],
                            ["Email", site.contact.email],
                            ["Address", site.contact.address],
                            ["Hours", site.contact.hours],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="rounded-xl border border-line p-5"
                            >
                                <dt className="text-xs font-medium uppercase text-muted">
                                    {label}
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {value || "—"}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    {s.note && (
                        <p className="mt-4 text-sm text-muted">{s.note}</p>
                    )}
                </section>
            );
        case "team":
        case "gallery":
            return (
                <section className="mx-auto max-w-6xl px-6 py-14">
                    {s.heading && (
                        <h2 className="mb-6 text-2xl font-semibold">
                            {s.heading}
                        </h2>
                    )}
                    <div
                        className={`grid gap-6 ${s.type === "team" ? "sm:grid-cols-2 md:grid-cols-3" : "grid-cols-2 md:grid-cols-4"}`}
                    >
                        {(s.items ?? []).map((item, i) => (
                            <figure key={i}>
                                {item.image && (
                                    <img
                                        src={item.image}
                                        alt={item.title ?? ""}
                                        loading="lazy"
                                        className={`w-full rounded-xl object-cover ${s.type === "team" ? "aspect-square" : "aspect-[4/3]"}`}
                                    />
                                )}
                                {item.title && (
                                    <figcaption className="mt-2 font-medium">
                                        {item.title}
                                    </figcaption>
                                )}
                                {item.text && (
                                    <p className="text-sm text-muted">
                                        {item.text}
                                    </p>
                                )}
                            </figure>
                        ))}
                    </div>
                </section>
            );
        case "hours":
            return (
                <section className="mx-auto max-w-3xl px-6 py-14">
                    <h2 className="mb-4 text-2xl font-semibold">
                        {s.heading ?? "Opening hours"}
                    </h2>
                    {(s.items ?? []).length > 0 ? (
                        <table className="w-full text-sm">
                            <tbody>
                                {(s.items ?? []).map((item, i) => (
                                    <tr
                                        key={i}
                                        className="border-t border-line"
                                    >
                                        <td className="py-2">{item.title}</td>
                                        <td className="py-2 text-right">
                                            {item.text}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <p>{site.contact.hours}</p>
                    )}
                    {s.note && (
                        <p className="mt-3 text-sm text-muted">{s.note}</p>
                    )}
                </section>
            );
        case "map":
            return (
                <section className="mx-auto max-w-3xl px-6 py-14">
                    <h2 className="mb-2 text-2xl font-semibold">
                        {s.heading ?? "Find us"}
                    </h2>
                    <p className="mb-4">{site.contact.address}</p>
                    <a
                        className="font-medium"
                        style={accent}
                        target="_blank"
                        rel="noopener noreferrer"
                        href={`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(site.contact.address)}`}
                    >
                        Get directions
                    </a>
                </section>
            );
        case "booking":
            return <BookingSection heading={s.heading} colour={site.colour} />;
        case "reviews":
            return site.reviews && site.reviews.length > 0 ? (
                <section className="mx-auto max-w-6xl px-6 py-14">
                    <h2 className="mb-6 text-2xl font-semibold">
                        {s.heading ?? "What patients say"}
                    </h2>
                    <div className="grid gap-4 md:grid-cols-3">
                        {site.reviews.map((r, i) => (
                            <blockquote
                                key={i}
                                className="rounded-xl border border-line p-4"
                            >
                                <p
                                    aria-label={`${r.rating} out of 5`}
                                    style={accent}
                                >
                                    {"★".repeat(r.rating)}
                                    {"☆".repeat(5 - r.rating)}
                                </p>
                                {r.comment && (
                                    <p className="mt-2 text-sm">{r.comment}</p>
                                )}
                                <footer className="mt-2 text-xs text-muted">
                                    {r.name} · {r.date}
                                </footer>
                            </blockquote>
                        ))}
                    </div>
                </section>
            ) : null;
        case "richtext":
            // Server-sanitised HTML only.
            return (
                <section
                    className="prose mx-auto max-w-3xl px-6 py-16"
                    dangerouslySetInnerHTML={{ __html: s.html ?? "" }}
                />
            );
        default:
            return null;
    }
}

export function SiteSections({
    sections,
    site,
}: {
    sections: SiteSection[];
    site: SiteInfo;
}) {
    appVersion =
        usePage<{ app?: { version?: string } }>().props.app?.version ?? "";
    return (
        <>
            {sections.map((s, i) => (
                <Section key={i} s={s} site={site} />
            ))}
        </>
    );
}

const ICONS: Record<string, LucideIcon> = {
    users: Users,
    activity: Activity,
    stethoscope: Stethoscope,
    pill: Pill,
    receipt: Receipt,
    chart: BarChart3,
    calendar: CalendarCheck,
    video: Video,
    globe: Globe,
    phone: Smartphone,
    truck: Truck,
    flask: FlaskConical,
    file: FileCheck2,
    shield: ShieldCheck,
    message: MessageCircle,
    clipboard: ClipboardList,
    wallet: Wallet,
    clock: Clock,
    check: Check,
};

/** The current release, read once per render of the sections (see SiteSections). */
let appVersion = "";

/** The icon named in a feature item (falls back to a tick). */
function FeatureIcon({ name }: { name?: string }) {
    const Icon = (name && ICONS[name]) || Check;
    return <Icon className="size-4" aria-hidden="true" />;
}

/** Adds the release number to site pictures so browsers fetch the new version after every deploy. */
function versioned(src?: string): string | undefined {
    if (!src || !src.startsWith("/images/")) return src;
    return appVersion
        ? `${src}${src.includes("?") ? "&" : "?"}v=${encodeURIComponent(appVersion)}`
        : src;
}

/** "How it works" → "how-it-works" (in-page links such as #how-it-works). */
function anchor(heading?: string): string | undefined {
    return heading
        ? heading
              .toLowerCase()
              .replace(/[^a-z0-9]+/g, "-")
              .replace(/^-|-$/g, "")
        : undefined;
}

/** As many columns as there are steps, so they always fill the row evenly. */
const STEP_COLUMNS: Record<number, string> = {
    2: "lg:grid-cols-2",
    3: "lg:grid-cols-3",
    4: "lg:grid-cols-4",
    5: "lg:grid-cols-5",
};
