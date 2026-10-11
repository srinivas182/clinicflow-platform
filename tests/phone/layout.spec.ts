import { readFileSync } from "node:fs";
import { expect, test, type Page } from "@playwright/test";

/**
 * Every page at phone width (375 px): the page must never be wider than the screen
 * (sideways scrolling). On failure a full-page screenshot is attached.
 * Practice pages run on the demo clinic's address (demo-clinic.localhost in CI) as its owner;
 * a page the owner cannot open (package or role) is skipped and reported, not failed.
 */
const PUBLIC = ["/", "/pricing", "/find-care", "/login", "/forgot-password", "/start",
    "/pages/about", "/pages/contact", "/pages/for-clinics", "/pages/for-doctors", "/pages/for-pharmacies-and-labs", "/pages/for-patients"];

interface Route { uri: string; method: string; middleware?: string[] }
const routes: Route[] = JSON.parse(readFileSync("tests/phone/routes.json", "utf8"));
const SIGNED_IN = [...new Set(routes
    .filter((r) => r.method.includes("GET") && (r.uri.startsWith("admin/") || ["workspaces", "account/security"].includes(r.uri)))
    .filter((r) => !r.uri.includes("{") && !/(export|download|csv|xlsx|pdf|callback|webhook|horizon|\/api\/)/.test(r.uri))
    .map((r) => `/${r.uri}`))].sort();

async function noSidewaysScroll(page: Page, path: string, skipBlocked = false): Promise<void> {
    const response = await page.goto(path, { waitUntil: "networkidle" });
    const status = response?.status() ?? 0;
    if (skipBlocked && (status === 403 || status === 404)) {
        test.skip(true, `${path} is not available to the demo clinic owner (HTTP ${status})`);
    }
    expect(status, `${path} loads`).toBeLessThan(400);
    const extra = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    if (extra > 1) {
        await test.info().attach(`${path} at phone width`, { body: await page.screenshot({ fullPage: true }), contentType: "image/png" });
    }
    expect(extra, `${path} is ${extra}px wider than a phone screen`).toBeLessThanOrEqual(1);
}

test.describe("public pages", () => {
    for (const path of PUBLIC) {
        test(path, async ({ page }) => noSidewaysScroll(page, path));
    }
});

test.describe("signed-in pages", () => {
    test.use({ storageState: "tests/phone/.admin-session.json" });
    for (const path of SIGNED_IN) {
        test(path, async ({ page }) => noSidewaysScroll(page, path));
    }
});

const PRACTICE_SKIP = /^(robots\.txt|sitemap\.xml|widget\.js|widget\/slots|confirm-identity)$|export|download|csv|xlsx|pdf/;
const PRACTICE = [...new Set(routes
    .filter((r) => r.method.includes("GET") && (r.middleware ?? []).some((m) => m.includes("InitializeTenancy")))
    .filter((r) => !r.uri.includes("{") && !/^(my|api|display|kiosk|broadcasting|auth|_|storage|up)(\/|$)/.test(r.uri) && !PRACTICE_SKIP.test(r.uri))
    .map((r) => (r.uri === "/" ? "/" : `/${r.uri}`)))].sort();
const PRACTICE_URL = process.env.PHONE_PRACTICE_URL ?? "http://demo-clinic.localhost";

test.describe("practice pages", () => {
    test.skip(!process.env.PHONE_PRACTICE_PASSWORD, "no demo practice in this run");
    test.use({ storageState: process.env.PHONE_PRACTICE_PASSWORD ? "tests/phone/.practice-session.json" : undefined });
    for (const path of PRACTICE) {
        test(path, async ({ page }) => noSidewaysScroll(page, `${PRACTICE_URL}${path}`, true));
    }
});
