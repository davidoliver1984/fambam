import { expect, test } from "@playwright/test";

for (const theme of ["light", "dark"] as const) {
  test(`shared production primitives — ${theme}`, async ({ page }) => {
    await page.addInitScript((selectedTheme) => {
      window.localStorage.setItem("fambam-theme", selectedTheme);
    }, theme);
    await page.goto("/ui-playground#production-primitives");
    await page.evaluate((selectedTheme) => {
      document.documentElement.dataset.theme = selectedTheme;
    }, theme);
    await expect(page.locator("html")).toHaveAttribute("data-theme", theme);
    await expect(
      page.getByRole("heading", { name: "Production primitives" }),
    ).toBeVisible();
    await expect(page.locator("#production-primitives")).toHaveScreenshot(
      `production-primitives-${theme}.png`,
    );
  });
}
