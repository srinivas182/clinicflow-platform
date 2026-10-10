import { useEffect } from "react";

/**
 * Phones: every table in the page's main area becomes a stack of cards, each row showing
 * "Label: value" lines taken from the table's own column headings (see .rt-cards in app.css).
 * Desktop is unchanged. Two-column label/value tables are left as they are; any other table
 * without headings scrolls inside its own box, so the page never scrolls sideways.
 * Add data-cards="off" to a table to opt out.
 */
export function ResponsiveTables({
    rootId = "main-content",
}: {
    rootId?: string;
}) {
    useEffect(() => {
        const root = document.getElementById(rootId);
        if (!root) return;
        let frame = 0;
        const label = () => {
            frame = 0;
            root.querySelectorAll<HTMLTableElement>("table").forEach(prepare);
        };
        const schedule = () => {
            if (!frame) frame = requestAnimationFrame(label);
        };
        label();
        const observer = new MutationObserver(schedule);
        observer.observe(root, { childList: true, subtree: true });
        return () => {
            observer.disconnect();
            if (frame) cancelAnimationFrame(frame);
        };
    }, [rootId]);

    return null;
}

function prepare(table: HTMLTableElement): void {
    if (table.dataset.cards === "off") return;
    const rows = Array.from(table.rows);
    if (rows.length === 0) return;
    let headerRow: HTMLTableRowElement | null = table.tHead?.rows[0] ?? null;
    if (
        !headerRow &&
        rows[0] &&
        Array.from(rows[0].cells).every((c) => c.tagName === "TH")
    ) {
        headerRow = rows[0];
        headerRow.classList.add("rt-head");
    }
    if (!headerRow) {
        // Label/value tables (a heading cell then a value in each row) already fit a phone.
        const keyValue = rows.every(
            (r) => r.cells.length <= 2 && r.cells[0]?.tagName === "TH",
        );
        table.classList.add(keyValue ? "rt-keep" : "rt-scroll");
        return;
    }
    const labels: string[] = [];
    Array.from(headerRow.cells).forEach((cell) => {
        for (let i = 0; i < Math.max(1, cell.colSpan); i++)
            labels.push(cell.textContent?.trim() ?? "");
    });
    table.classList.add("rt-cards");
    rows.forEach((row) => {
        if (row === headerRow || row.parentElement === table.tHead) return;
        let col = 0;
        Array.from(row.cells).forEach((cell) => {
            const text = labels[col] ?? "";
            if (cell.getAttribute("data-label") !== text)
                cell.setAttribute("data-label", text);
            col += Math.max(1, cell.colSpan);
        });
    });
}
