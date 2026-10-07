import { cn } from "@/lib/cn";

/**
 * The queue ticket — Clinic Flow's signature element. It follows the patient
 * from the kiosk to triage, the doctor, the pharmacy and the TV display.
 */
export function Ticket({
    number,
    label = "Ticket",
    size = "md",
}: {
    number: string;
    label?: string;
    size?: "md" | "lg";
}) {
    const large = size === "lg";
    return (
        <span
            role="img"
            aria-label={`${label} ${number}`}
            className={cn(
                "relative inline-flex flex-col items-center justify-center rounded-lg bg-chrome text-white",
                large ? "px-8 py-4" : "px-4 py-2",
            )}
        >
            <span
                className={cn(
                    "absolute top-1/2 -left-1.5 -translate-y-1/2 rounded-full bg-paper",
                    large ? "size-5 -left-2.5" : "size-3",
                )}
            />
            <span
                className={cn(
                    "absolute top-1/2 -right-1.5 -translate-y-1/2 rounded-full bg-paper",
                    large ? "size-5 -right-2.5" : "size-3",
                )}
            />
            <span
                className={cn(
                    "text-chrome-muted",
                    large ? "text-sm" : "text-[10px]",
                )}
            >
                {label}
            </span>
            <span
                className={cn(
                    "leading-none font-semibold tracking-wide",
                    large ? "text-5xl" : "text-xl",
                )}
            >
                {number}
            </span>
        </span>
    );
}
