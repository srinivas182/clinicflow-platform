import { usePage } from "@inertiajs/react";
import { Activity } from "lucide-react";
import { useEffect } from "react";
import { brandTitle } from "@/lib/brandTitle";

type Brand =
    | {
          name: string;
          logo: string | null;
          primary: string;
          accent: string;
          poweredBy: boolean;
      }
    | null
    | undefined;

/**
 * The product logo: Clinic Flow, or a white-label brand's logo and name. A brand's colours are
 * applied to the theme tokens, so every screen picks them up.
 */
export function Logo({ tone = "dark" }: { tone?: "dark" | "light" }) {
    const brand = (usePage().props as { brand?: Brand }).brand;

    useEffect(() => {
        const root = document.documentElement.style;
        brandTitle.name = brand ? brand.name : "Clinic Flow";
        if (brand) {
            root.setProperty("--color-teal", brand.primary);
            root.setProperty("--color-teal-deep", brand.accent);
        } else {
            root.removeProperty("--color-teal");
            root.removeProperty("--color-teal-deep");
        }
    }, [brand]);

    const text = tone === "light" ? "text-white" : "text-ink";
    if (brand) {
        return (
            <span className={`inline-flex flex-col ${text}`}>
                <span className="inline-flex items-center gap-2 text-lg font-semibold">
                    {brand.logo ? (
                        <img
                            src={brand.logo}
                            alt=""
                            className="h-7 max-w-[7rem] object-contain"
                        />
                    ) : null}
                    {brand.name}
                </span>
                {brand.poweredBy && (
                    <span
                        className={`text-[10px] ${tone === "light" ? "text-white/60" : "text-muted"}`}
                    >
                        Powered by Clinic Flow
                    </span>
                )}
            </span>
        );
    }

    return (
        <span
            className={`inline-flex items-center gap-2 text-lg font-semibold ${text}`}
        >
            <span className="grid size-7 place-items-center rounded-lg bg-teal text-white">
                <Activity
                    className="size-4"
                    strokeWidth={2.2}
                    aria-hidden="true"
                />
            </span>
            Clinic Flow
        </span>
    );
}
