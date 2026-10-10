import { usePage } from "@inertiajs/react";

/** "Developed & Maintained by …" — in every footer. Set by the platform; practices cannot change it. */
export function DeveloperCredit({ className = "" }: { className?: string }) {
    const credit = usePage<{ credit?: { text: string; url: string } }>().props
        .credit;
    if (!credit?.text) return null;

    return (
        <p className={`text-center text-xs text-muted ${className}`}>
            <a
                href={credit.url}
                target="_blank"
                rel="noopener"
                className="hover:text-ink hover:underline"
            >
                {credit.text}
            </a>
        </p>
    );
}
