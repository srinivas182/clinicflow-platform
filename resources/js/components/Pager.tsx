import { Link } from "@inertiajs/react";

/** A Laravel paginator as sent to the page. */
export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
    last_page: number;
}

/** Page controls for long lists (filters are kept in the page links). */
export function Pager<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) return null;
    const label = (l: string) =>
        l
            .replace("&laquo;", "‹")
            .replace("&raquo;", "›")
            .replace(/<[^>]*>/g, "")
            .trim();

    return (
        <nav
            aria-label="Pages"
            className="mt-4 flex flex-wrap items-center gap-1 text-sm"
        >
            <span className="mr-2 text-muted">
                {page.from}–{page.to} of {page.total}
            </span>
            {page.links.map((l, i) =>
                l.url === null ? (
                    <span key={i} className="px-2 py-1 text-muted">
                        {label(l.label)}
                    </span>
                ) : (
                    <Link
                        key={i}
                        href={l.url}
                        preserveScroll
                        className={`rounded px-2 py-1 ${l.active ? "bg-teal text-white" : "hover:bg-paper"}`}
                        aria-current={l.active ? "page" : undefined}
                    >
                        {label(l.label)}
                    </Link>
                ),
            )}
        </nav>
    );
}
