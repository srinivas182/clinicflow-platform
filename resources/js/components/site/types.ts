export interface SiteLink {
    label: string;
    href: string;
}

export interface SiteItem {
    title?: string;
    text?: string;
    icon?: string;
    href?: string;
    image?: string;
    question?: string;
    answer?: string;
}

export interface SiteSection {
    type: 'hero' | 'cards' | 'features' | 'steps' | 'split' | 'faq' | 'cta' | 'contact' | 'richtext';
    eyebrow?: string;
    heading?: string;
    text?: string;
    note?: string;
    image?: string;
    primary?: SiteLink;
    secondary?: SiteLink;
    bullets?: string[];
    items?: SiteItem[];
    html?: string;
}

export interface SiteInfo {
    name: string;
    colour: string;
    menu: SiteLink[];
    cta: SiteLink;
    contact: { phone: string; email: string; address: string; hours: string };
    footer: SiteLink[];
    poweredBy: boolean;
}
