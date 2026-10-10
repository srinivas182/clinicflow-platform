import { readFileSync } from "node:fs";
import { expect, test, type Page } from "@playwright/test";

/**
 * Every page at phone width (375 px): the page must never be wider than the screen
 * (sideways scrolling). On failure a full-page screenshot is attached.
 * Practice-area pages (practice subdomains) follow in a later sprint.
 */
const PUBLIC = ["/", "/pricing", "/find-care", "/login", "/forgot-password", "/start",
    "/pages/about", "/pages/contact", "/pages/for-clinics", "/pages/for-doctors", "/pages/for-pharmacies-and-labs", "/pages/for-patients"];

interface Route { uri: string; method: string }
const routes: Route[] = JSON.parse(readFileSync("tests/phone/routes.json", "utf8"));
const SIGNED_IN = [...new Set(routes
    .filter((r) => r.method.includes("GET") && (r.uri.startsWith("admin/") || ["workspaces", "account/security"].includes(r.uri)))
    .filter((r) => !r.uri.includes("{") && !/(export|download|csv|xlsx|pdf|callback|webhook)/.test(r.uri))
    .map((r) => `/${r.uri}`))].sort();

async function noSidewaysScroll(page: Page, path: string): Promise<void> {
    const response = await page.goto(path, { waitUntil: "networkidle" });
    expect(response?.status() ?? 0, `${path} loads`).toBeLessThan(400);
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
