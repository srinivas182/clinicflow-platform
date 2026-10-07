import { Monitor, Moon, Sun } from "lucide-react";
import { useEffect, useState } from "react";

type Theme = "system" | "light" | "dark";
const KEY = "cf-theme";

function read(): Theme {
    try {
        const v = localStorage.getItem(KEY);
        return v === "light" || v === "dark" ? v : "system";
    } catch {
        return "system";
    }
}

/** Applies the chosen theme to <html>; "system" follows the device setting. */
export function applyTheme(theme: Theme): void {
    const root = document.documentElement;
    if (theme === "system") root.removeAttribute("data-theme");
    else root.setAttribute("data-theme", theme);
}

/** System / Light / Dark switch (remembered on this device). */
export function ThemeToggle({ className = "" }: { className?: string }) {
    const [theme, setTheme] = useState<Theme>("system");
    useEffect(() => setTheme(read()), []);
    const choose = (t: Theme) => {
        setTheme(t);
        applyTheme(t);
        try {
            if (t === "system") localStorage.removeItem(KEY);
            else localStorage.setItem(KEY, t);
        } catch {
            /* storage unavailable: applies for this page only */
        }
    };
    const options: { value: Theme; label: string; Icon: typeof Sun }[] = [
        { value: "system", label: "Use device setting", Icon: Monitor },
        { value: "light", label: "Light", Icon: Sun },
        { value: "dark", label: "Dark", Icon: Moon },
    ];

    return (
        <div
            role="radiogroup"
            aria-label="Colour theme"
            className={`inline-flex rounded-md bg-chrome-2 p-0.5 ${className}`}
        >
            {options.map(({ value, label, Icon }) => (
                <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={theme === value}
                    aria-label={label}
                    title={label}
                    onClick={() => choose(value)}
                    className={`rounded px-2 py-1 text-chrome-muted focus-visible:outline-2 focus-visible:outline-teal ${theme === value ? "bg-chrome text-white" : "hover:text-white"}`}
                >
                    <Icon size={14} aria-hidden="true" />
                </button>
            ))}
        </div>
    );
}
