import { render } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Field } from "@/components/form/Field";
import { ResponsiveTables } from "@/components/ResponsiveTables";

function page(html: string): HTMLElement {
    const root = document.createElement("main");
    root.id = "main-content";
    root.innerHTML = html;
    document.body.replaceChildren(root);
    render(<ResponsiveTables />);
    return root;
}

describe("tables on phones", () => {
    it("turns a table with column headings into labelled cards", () => {
        const root = page(
            "<table><thead><tr><th>Patient</th><th colspan='2'>Amount</th><th></th></tr></thead><tbody><tr><td>Thandi</td><td>R</td><td>350</td><td><button>Pay</button></td></tr></tbody></table>",
        );
        const cells = root.querySelectorAll("tbody td");
        expect(
            root.querySelector("table")?.classList.contains("rt-cards"),
        ).toBe(true);
        expect(
            Array.from(cells).map((c) => c.getAttribute("data-label")),
        ).toEqual(["Patient", "Amount", "Amount", ""]);
    });

    it("uses a first row of headings when there is no heading section", () => {
        const root = page(
            "<table><tbody><tr><th>Date</th><th>Status</th></tr><tr><td>1 Oct</td><td>Paid</td></tr></tbody></table>",
        );
        expect(root.querySelector("tr")?.classList.contains("rt-head")).toBe(
            true,
        );
        expect(root.querySelectorAll("td")[1]?.getAttribute("data-label")).toBe(
            "Status",
        );
    });

    it("leaves label/value tables alone and puts other tables in a scroll box", () => {
        const root = page(
            "<table id='kv'><tbody><tr><th>Total</th><td>R350</td></tr></tbody></table><table id='raw'><tbody><tr><td>a</td><td>b</td><td>c</td></tr></tbody></table>",
        );
        expect(root.querySelector("#kv")?.classList.contains("rt-keep")).toBe(
            true,
        );
        expect(
            root.querySelector("#raw")?.classList.contains("rt-scroll"),
        ).toBe(true);
    });
});

describe("phone keyboards", () => {
    it("picks the keypad from the field name, unless the page sets its own", () => {
        const { container } = render(
            <>
                <Field label="Cell" name="cell" />
                <Field label="Code" name="code" />
                <Field label="Amount" name="amount_rand" />
                <Field label="Name" name="name" />
                <Field label="Postal code" name="postal_code" />
                <Field label="Cell" name="guardian_cell" inputMode="text" />
            </>,
        );
        const mode = (n: string) =>
            container.querySelector(`[name="${n}"]`)?.getAttribute("inputmode");
        expect([
            mode("cell"),
            mode("code"),
            mode("amount_rand"),
            mode("name"),
            mode("postal_code"),
            mode("guardian_cell"),
        ]).toEqual(["tel", "numeric", "decimal", null, null, "text"]);
        expect(
            container
                .querySelector('[name="code"]')
                ?.getAttribute("autocomplete"),
        ).toBe("one-time-code");
    });
});
