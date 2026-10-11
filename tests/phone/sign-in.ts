import { chromium, type FullConfig, type Page } from "@playwright/test";

async function signIn(page: Page, email: string, password: string): Promise<void> {
    await page.goto("/login");
    await page.getByLabel("Email or cell number").fill(email);
    await page.getByLabel("Password").fill(password);
    await page.getByRole("button", { name: "Continue" }).click();
}

/** Signs in once as the platform admin and once as the demo clinic's owner; saves both sessions. */
export default async function setUp(config: FullConfig): Promise<void> {
    const baseURL = String(config.projects[0]?.use.baseURL);
    const browser = await chromium.launch();

    const admin = await browser.newPage({ baseURL });
    await signIn(admin, process.env.PHONE_ADMIN_EMAIL ?? "admin@clinicflow.test", process.env.PHONE_ADMIN_PASSWORD ?? "password");
    await admin.waitForURL(/\/admin\//, { timeout: 15_000 });
    await admin.context().storageState({ path: "tests/phone/.admin-session.json" });

    if (process.env.PHONE_PRACTICE_PASSWORD) {
        const owner = await browser.newPage({ baseURL });
        await signIn(owner, process.env.PHONE_PRACTICE_EMAIL ?? "clinic-owner@demo.localhost", process.env.PHONE_PRACTICE_PASSWORD);
        await owner.waitForURL(/\/workspaces/, { timeout: 15_000 });
        await owner.getByText("Demo Medical Centre").first().click();
        await owner.waitForURL(/demo-clinic\.localhost/, { timeout: 20_000 });
        await owner.context().storageState({ path: "tests/phone/.practice-session.json" });
    }
    await browser.close();
}
