import { act, render } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const reload = vi.fn();
vi.mock("@inertiajs/react", () => ({
    router: { reload: (...args: unknown[]) => reload(...args) },
    usePage: () => ({ props: { realtime: null } }),
}));

const { useLiveReload } = await import("@/lib/realtime");

function Screen({
    ms,
    fullSpeed = false,
}: {
    ms: number;
    fullSpeed?: boolean;
}) {
    useLiveReload("queue", "queue.changed", ms, undefined, fullSpeed);
    return null;
}

function setHidden(hidden: boolean) {
    Object.defineProperty(document, "hidden", {
        configurable: true,
        get: () => hidden,
    });
    document.dispatchEvent(new Event("visibilitychange"));
}

function setSaveData(on: boolean) {
    Object.defineProperty(navigator, "connection", {
        configurable: true,
        value: on ? { saveData: true } : undefined,
    });
}

describe("screens that refresh themselves", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        reload.mockClear();
        setSaveData(false);
        Object.defineProperty(document, "hidden", {
            configurable: true,
            get: () => false,
        });
    });
    afterEach(() => vi.useRealTimers());

    it("stops refreshing while hidden and refreshes at once on return", () => {
        render(<Screen ms={15000} />);
        act(() => vi.advanceTimersByTime(15000));
        expect(reload).toHaveBeenCalledTimes(1);
        act(() => setHidden(true));
        act(() => vi.advanceTimersByTime(60000));
        expect(reload).toHaveBeenCalledTimes(1);
        act(() => setHidden(false));
        expect(reload).toHaveBeenCalledTimes(2);
    });

    it("refreshes three times less often when the phone saves data, except full-speed screens", () => {
        setSaveData(true);
        render(<Screen ms={10000} />);
        act(() => vi.advanceTimersByTime(20000));
        expect(reload).toHaveBeenCalledTimes(0);
        act(() => vi.advanceTimersByTime(10000));
        expect(reload).toHaveBeenCalledTimes(1);

        reload.mockClear();
        render(<Screen ms={5000} fullSpeed />);
        act(() => vi.advanceTimersByTime(5000));
        expect(reload).toHaveBeenCalledTimes(1);
    });
});
