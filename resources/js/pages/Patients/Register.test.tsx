import { fireEvent, render, screen } from "@testing-library/react";
import { useState, type ReactNode } from "react";
import { describe, expect, it, vi } from "vitest";

// A minimal useForm that keeps real state, so the form behaves as in the browser.
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    usePage: () => ({ props: { languages: [] } }),
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setAll] = useState(initial);
        return {
            data,
            setData: (k: keyof T, v: unknown) => setAll((d) => ({ ...d, [k]: v })),
            errors: {},
            processing: false,
            post: vi.fn(),
        };
    },
}));
vi.mock("@/layouts/AppShell", () => ({ AppShell: ({ children }: { children: ReactNode }) => <div>{children}</div> }));

const { default: Register } = await import("@/pages/Patients/Register");

describe("patient registration steps", () => {
    it("moves through the steps when scrolling returns a promise (newer browsers)", () => {
        // Chrome now returns a promise from smooth scrollTo; an effect that returned it crashed the page.
        window.scrollTo = vi.fn(() => Promise.resolve()) as unknown as typeof window.scrollTo;
        render(<Register />);
        expect(screen.getByText(/Step 1 of 4/)).toBeTruthy();
        fireEvent.click(screen.getByRole("button", { name: /^Next/ }));
        expect(screen.getByText(/Step 2 of 4/)).toBeTruthy();
        fireEvent.click(screen.getByRole("button", { name: /^Next/ }));
        expect(screen.getByText(/Step 3 of 4/)).toBeTruthy();
        fireEvent.click(screen.getByRole("button", { name: /^Back/ }));
        expect(screen.getByText(/Step 2 of 4/)).toBeTruthy();
        expect(window.scrollTo).toHaveBeenCalled();
    });
});
