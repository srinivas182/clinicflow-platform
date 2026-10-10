import { defineConfig } from "@playwright/test";

/** Phone-width layout checks (see layout.spec.ts). Run in CI by .github/workflows/phone-layout.yml. */
export default defineConfig({
    testDir: ".",
    timeout: 30_000,
    retries: 1,
    reporter: [["list"]],
    globalSetup: "./sign-in.ts",
    outputDir: "../../test-results/phone",
    use: {
        baseURL: process.env.PHONE_BASE_URL ?? "http://localhost:8000",
        viewport: { width: 375, height: 800 },
        isMobile: true,
        hasTouch: true,
        deviceScaleFactor: 2,
    },
});
