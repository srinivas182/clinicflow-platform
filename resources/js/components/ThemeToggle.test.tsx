import { act } from "react";
import { createRoot } from "react-dom/client";
import { afterEach, describe, expect, it } from "vitest";
import { ThemeToggle } from "./ThemeToggle";

// React needs this flag to run act() outside a test renderer.
(
    globalThis as unknown as { IS_REACT_ACT_ENVIRONMENT: boolean }
).IS_REACT_ACT_ENVIRONMENT = true;

function mount(): HTMLDivElement {
    const host = document.createElement("div");
    document.body.appendChild(host);
    act(() => createRoot(host).render(<ThemeToggle />));
    return host;
}

const click = (host: HTMLElement, label: string) =>
    act(() =>
        (
            host.querySelector(`[aria-label="${label}"]`) as HTMLButtonElement
        ).click(),
    );

describe("ThemeToggle", () => {
    afterEach(() => {
        localStorage.clear();
        document.documentElement.removeAttribute("data-theme");
        document.body.innerHTML = "";
    });

    it("applies and remembers dark or light, and returns to the device setting", () => {
        const host = mount();
        click(host, "Dark");
        expect(document.documentElement.getAttribute("data-theme")).toBe(
            "dark",
        );
        expect(localStorage.getItem("cf-theme")).toBe("dark");
        expect(
            host
                .querySelector('[aria-label="Dark"]')
                ?.getAttribute("aria-checked"),
        ).toBe("true");

        click(host, "Light");
        expect(document.documentElement.getAttribute("data-theme")).toBe(
            "light",
        );

        click(host, "Use device setting");
        expect(document.documentElement.hasAttribute("data-theme")).toBe(false);
        expect(localStorage.getItem("cf-theme")).toBeNull();
    });

    it("is a labelled group of choices for screen readers", () => {
        const host = mount();
        expect(
            host
                .querySelector('[role="radiogroup"]')
                ?.getAttribute("aria-label"),
        ).toBe("Colour theme");
        expect(host.querySelectorAll('[role="radio"]')).toHaveLength(3);
    });
});
