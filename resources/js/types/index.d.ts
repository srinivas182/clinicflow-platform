export type ProviderType = "clinic" | "independent_doctor" | "pharmacy" | "lab";

export interface SharedProvider {
    id: string;
    name: string;
    type: ProviderType;
    typeLabel: string;
}

export interface SharedProps {
    app: {
        name: string;
        version: string;
    };
    auth: {
        user: { name: string; email: string } | null;
    };
    flash: {
        success: string | null;
        error?: string | null;
        newApiKey?: string | null;
    };
    errors: Record<string, string>;
    botProtection?: { siteKey: string } | null;
    brand?: {
        name: string;
        logo: string | null;
        primary: string;
        accent: string;
        supportEmail: string | null;
        supportPhone: string | null;
        footer: string | null;
        poweredBy: boolean;
    } | null;
    provider: SharedProvider | null;
    [key: string]: unknown;
}
