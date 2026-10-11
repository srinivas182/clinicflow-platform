import { renderHook } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

vi.mock("@inertiajs/react", () => ({
    usePage: () => ({
        props: {
            portalText: {
                "portal.home.ahead_of_you": "{{ count }} ahead of you",
                "portal.pharmacies.in_stock":
                    "Kukhona ezingu-{{ available }} kwezingu-{{ total }}",
            },
        },
    }),
}));

const { usePortalText } = await import("@/lib/portalText");

describe("portal texts", () => {
    it("puts values where each language places them", () => {
        const t = renderHook(() => usePortalText()).result.current;
        expect(t("portal.home.ahead_of_you", { count: 3 })).toBe(
            "3 ahead of you",
        );
        expect(
            t("portal.pharmacies.in_stock", { available: 2, total: 5 }),
        ).toBe("Kukhona ezingu-2 kwezingu-5");
        // A missing text falls back to its key (never blank); a missing value leaves its placeholder visible.
        expect(t("portal.unknown")).toBe("portal.unknown");
        expect(t("portal.home.ahead_of_you")).toBe("{{ count }} ahead of you");
    });
});
