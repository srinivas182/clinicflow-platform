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
    provider: SharedProvider | null;
    [key: string]: unknown;
}
