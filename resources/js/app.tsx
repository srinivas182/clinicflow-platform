import "../css/app.css";
import "@fontsource/ibm-plex-sans/400.css";
import "@fontsource/ibm-plex-sans/500.css";
import "@fontsource/ibm-plex-sans/600.css";
import "@fontsource/ibm-plex-sans/700.css";

import { createInertiaApp } from "@inertiajs/react";
import type { ComponentType } from "react";
import { createRoot } from "react-dom/client";
import { brandTitle } from "@/lib/brandTitle";

// Each page is downloaded only when it is opened (smaller first load).
const pages = import.meta.glob<{ default: ComponentType }>("./pages/**/*.tsx");

void createInertiaApp({
    title: (title) =>
        title ? `${title} · ${brandTitle.name}` : brandTitle.name,
    resolve: async (name) => {
        const load = pages[`./pages/${name}.tsx`];
        if (!load) {
            throw new Error(`Page not found: ${name}`);
        }
        return (await load()).default;
    },
    setup({ el, App, props }) {
        if (el) {
            createRoot(el).render(<App {...props} />);
        }
    },
    progress: {
        color: "#0F7C74",
    },
});
