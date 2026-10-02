export type ProviderType = 'clinic' | 'independent_doctor' | 'pharmacy' | 'lab';

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
    };
    errors: Record<string, string>;
    provider: SharedProvider | null;
    [key: string]: unknown;
}
