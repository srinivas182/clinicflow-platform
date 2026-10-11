import { usePage } from "@inertiajs/react";

/**
 * Patient portal texts in the patient's language. The server sends the texts for the portal's
 * current language (approved translations only, English for everything else). Values are put in
 * place of {{ name }} placeholders, so each language can order the sentence its own way.
 */
export function usePortalText(): (
    key: string,
    values?: Record<string, string | number>,
) => string {
    const texts =
        usePage<{ portalText?: Record<string, string> }>().props.portalText ??
        {};
    return (key, values = {}) =>
        (texts[key] ?? key).replace(
            /\{\{\s*([a-z_]+)\s*\}\}/g,
            (whole, name: string) =>
                name in values ? String(values[name]) : whole,
        );
}
