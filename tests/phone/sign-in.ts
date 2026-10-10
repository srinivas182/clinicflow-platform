import { chromium, type FullConfig } from "@playwright/test";

/** Signs in once as the seeded platform admin and saves the session for the admin checks. */
export default async function signIn(config: FullConfig): Promise<void> {
    const baseURL = String(config.projects[0]?.use.baseURL);
    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL });
    await page.goto("/login");
    await page.getByLabel("Email or cell number").fill(process.env.PHONE_ADMIN_EMAIL ?? "admin@clinicflow.test");
    await page.getByLabel("Password").fill(process.env.PHONE_ADMIN_PASSWORD ?? "password");
    await page.getByRole("button", { name: "Continue" }).click();
    await page.waitForURL(/\/admin\//, { timeout: 15_000 });
    await page.context().storageState({ path: "tests/phone/.admin-session.json" });
    await browser.close();
}
