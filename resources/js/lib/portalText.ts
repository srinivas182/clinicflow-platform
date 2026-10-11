import { usePage } from "@inertiajs/react";

/**
 * Patient portal texts in the patient's language. The server sends the texts for the portal's
 * current language (approved translations only, English for everything else).
 */
export function usePortalText(): (key: string) => string {
    const texts =
        usePage<{ portalText?: Record<string, string> }>().props.portalText ??
        {};
    return (key: string) => texts[key] ?? key;
}
