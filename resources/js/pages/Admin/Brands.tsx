import { Head, router, useForm } from "@inertiajs/react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AdminShell } from "@/layouts/AdminShell";

interface Brand {
    id: number;
    name: string;
    slug: string;
    logo: string | null;
    primary: string;
    accent: string;
    supportEmail: string | null;
    supportPhone: string | null;
    footer: string | null;
    poweredBy: boolean;
    practiceDomain: string | null;
    resellerId: number | null;
    active: boolean;
    signupLink: string;
    practices: number;
    emailFrom: string | null;
    emailVerified: boolean;
    emailRecords: {
        ownership: { host: string; value: string };
        spf: string;
    } | null;
    smsSender: string | null;
    smsApproved: boolean;
}
interface Props {
    brands: Brand[];
    resellers: { id: number; name: string }[];
    providers: { id: string; name: string; brandId: number | null }[];
}
const empty = {
    id: "",
    name: "",
    slug: "",
    primary_color: "#0f7c74",
    accent_color: "#0b5c57",
    support_email: "",
    support_phone: "",
    footer_text: "",
    powered_by: true,
    practice_domain: "",
    reseller_id: "",
    active: true,
    logo: null as File | null,
};

export default function AdminBrands({ brands, resellers, providers }: Props) {
    const form = useForm({ ...empty });
    const input = "w-full rounded-md border border-line px-2 py-1 text-sm";
    const edit = (b: Brand) =>
        form.setData({
            id: String(b.id),
            name: b.name,
            slug: b.slug,
            primary_color: b.primary,
            accent_color: b.accent,
            support_email: b.supportEmail ?? "",
            support_phone: b.supportPhone ?? "",
            footer_text: b.footer ?? "",
            powered_by: b.poweredBy,
            practice_domain: b.practiceDomain ?? "",
            reseller_id: b.resellerId ? String(b.resellerId) : "",
            active: b.active,
            logo: null,
        });

    return (
        <AdminShell active="Brands">
            <Head title="Brands" />
            <h1 className="mb-1 text-2xl font-semibold">White-label brands</h1>
            <p className="mb-5 text-sm text-muted">
                A brand changes what practices and patients see. Dr Business Flow
                still bills; the linked reseller earns commission. The partner
                must point *.their-domain at Dr Business Flow before practices can
                use it.
            </p>
            <Flash />
            <div className="grid grid-cols-2 gap-4">
                <Card title={form.data.id ? "Edit brand" : "New brand"}>
                    <div className="grid grid-cols-2 gap-2 text-sm">
                        <input
                            aria-label="Name"
                            placeholder="Brand name"
                            className={input}
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData("name", e.target.value)
                            }
                        />
                        <input
                            aria-label="Short name"
                            placeholder="short-name (for the sign-up link)"
                            className={input}
                            value={form.data.slug}
                            onChange={(e) =>
                                form.setData("slug", e.target.value)
                            }
                        />
                        <label className="flex items-center gap-2">
                            Main colour{" "}
                            <input
                                type="color"
                                value={form.data.primary_color}
                                onChange={(e) =>
                                    form.setData(
                                        "primary_color",
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        <label className="flex items-center gap-2">
                            Dark colour{" "}
                            <input
                                type="color"
                                value={form.data.accent_color}
                                onChange={(e) =>
                                    form.setData("accent_color", e.target.value)
                                }
                            />
                        </label>
                        <input
                            aria-label="Practice domain"
                            placeholder="Practice domain, e.g. partnerhealth.co.za"
                            className={input}
                            value={form.data.practice_domain}
                            onChange={(e) =>
                                form.setData("practice_domain", e.target.value)
                            }
                        />
                        <select
                            aria-label="Reseller"
                            className={input}
                            value={form.data.reseller_id}
                            onChange={(e) =>
                                form.setData("reseller_id", e.target.value)
                            }
                        >
                            <option value="">No commission partner</option>
                            {resellers.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.name}
                                </option>
                            ))}
                        </select>
                        <input
                            aria-label="Support email"
                            placeholder="Support email"
                            className={input}
                            value={form.data.support_email}
                            onChange={(e) =>
                                form.setData("support_email", e.target.value)
                            }
                        />
                        <input
                            aria-label="Support phone"
                            placeholder="Support phone"
                            className={input}
                            value={form.data.support_phone}
                            onChange={(e) =>
                                form.setData("support_phone", e.target.value)
                            }
                        />
                        <input
                            aria-label="Footer"
                            placeholder="Footer text"
                            className={`${input} col-span-2`}
                            value={form.data.footer_text}
                            onChange={(e) =>
                                form.setData("footer_text", e.target.value)
                            }
                        />
                        <label className="col-span-2 flex items-center gap-2">
                            Logo (PNG, JPG, WebP or SVG, up to 200 KB){" "}
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                onChange={(e) =>
                                    form.setData(
                                        "logo",
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="accent-teal"
                                checked={form.data.powered_by}
                                onChange={(e) =>
                                    form.setData("powered_by", e.target.checked)
                                }
                            />{" "}
                            Show &ldquo;Powered by Dr Business Flow&rdquo;
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="accent-teal"
                                checked={form.data.active}
                                onChange={(e) =>
                                    form.setData("active", e.target.checked)
                                }
                            />{" "}
                            Active
                        </label>
                    </div>
                    {Object.values(form.errors)[0] && (
                        <p
                            role="alert"
                            className="mt-2 text-xs text-status-danger"
                        >
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                    <div className="mt-3 flex gap-2">
                        <Button
                            size="sm"
                            onClick={() =>
                                form.post("/admin/brands", {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () => form.setData({ ...empty }),
                                })
                            }
                        >
                            Save brand
                        </Button>
                        {form.data.id && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => form.setData({ ...empty })}
                            >
                                New brand
                            </Button>
                        )}
                    </div>
                </Card>
                <Card title="Move a practice to a brand">
                    <p className="mb-2 text-xs text-muted">
                        Changes what the practice and its patients see. Its web
                        address stays the same.
                    </p>
                    {providers.slice(0, 50).map((p) => (
                        <div
                            key={p.id}
                            className="flex items-center gap-2 py-1 text-sm"
                        >
                            <span className="flex-1">{p.name}</span>
                            <select
                                aria-label={`Brand for ${p.name}`}
                                className="rounded-md border border-line px-2 py-1 text-sm"
                                value={p.brandId ?? ""}
                                onChange={(e) =>
                                    router.post(
                                        "/admin/brands/assign",
                                        {
                                            provider_id: p.id,
                                            brand_id: e.target.value || null,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <option value="">Dr Business Flow</option>
                                {brands.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    ))}
                </Card>
            </div>
            {brands.map((b) => (
                <Card
                    key={b.id}
                    title={b.name}
                    aside={
                        <Badge tone={b.active ? "success" : "neutral"}>
                            {b.active ? `${b.practices} practices` : "inactive"}
                        </Badge>
                    }
                    className="mt-4"
                >
                    <div className="flex items-center gap-3 text-sm">
                        {b.logo && (
                            <img
                                src={b.logo}
                                alt=""
                                className="h-8 max-w-[8rem] object-contain"
                            />
                        )}
                        <span
                            className="inline-block size-5 rounded"
                            style={{ background: b.primary }}
                        />
                        <span className="flex-1">
                            Sign-up link:{" "}
                            <span className="font-mono text-xs">
                                {b.signupLink}
                            </span>
                            {b.practiceDomain && (
                                <span className="block text-xs text-muted">
                                    Practices at *.{b.practiceDomain}
                                </span>
                            )}
                        </span>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => edit(b)}
                        >
                            Edit
                        </Button>
                    </div>
                    <div className="mt-3 grid grid-cols-2 gap-3 border-t border-line-soft pt-3 text-sm">
                        <div>
                            <p className="mb-1 font-medium">
                                Email sender{" "}
                                {b.emailVerified ? (
                                    <Badge tone="success">verified</Badge>
                                ) : b.emailFrom ? (
                                    <Badge tone="warning">not verified</Badge>
                                ) : null}
                            </p>
                            <p className="mb-1 text-xs text-muted">
                                {b.emailFrom ??
                                    "Uses the Dr Business Flow address until a verified brand address is set."}
                            </p>
                            {b.emailRecords && !b.emailVerified && (
                                <p className="mb-1 font-mono text-[11px]">
                                    TXT {b.emailRecords.ownership.host} ={" "}
                                    {b.emailRecords.ownership.value}
                                    <br />
                                    SPF on the domain must include:{" "}
                                    {b.emailRecords.spf}
                                </p>
                            )}
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => {
                                        const email = window.prompt(
                                            "From address on the brand domain",
                                            b.emailFrom ?? "",
                                        );
                                        if (email)
                                            router.post(
                                                `/admin/brands/${b.id}/senders/email`,
                                                { email_from: email },
                                                { preserveScroll: true },
                                            );
                                    }}
                                >
                                    Set address
                                </Button>
                                {b.emailFrom && !b.emailVerified && (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/admin/brands/${b.id}/senders/verify`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Verify
                                    </Button>
                                )}
                            </div>
                        </div>
                        <div>
                            <p className="mb-1 font-medium">
                                SMS sender{" "}
                                {b.smsSender && (
                                    <Badge
                                        tone={
                                            b.smsApproved
                                                ? "success"
                                                : "warning"
                                        }
                                    >
                                        {b.smsApproved
                                            ? "approved"
                                            : "not approved"}
                                    </Badge>
                                )}
                            </p>
                            <p className="mb-1 text-xs text-muted">
                                {b.smsSender ??
                                    "Uses the SMS supplier default."}{" "}
                                Only approved names are used — register them
                                with the SMS supplier first.
                            </p>
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => {
                                    const name = window.prompt(
                                        "SMS sender name (3–11 letters/numbers)",
                                        b.smsSender ?? "",
                                    );
                                    if (name !== null)
                                        router.post(
                                            `/admin/brands/${b.id}/senders/sms`,
                                            {
                                                sms_sender: name,
                                                approved: window.confirm(
                                                    "Is this name registered and approved with the SMS supplier?",
                                                ),
                                            },
                                            { preserveScroll: true },
                                        );
                                }}
                            >
                                Set SMS sender
                            </Button>
                        </div>
                    </div>
                </Card>
            ))}
        </AdminShell>
    );
}
