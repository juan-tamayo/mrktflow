import { expect, type Browser, type Page } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'

export async function exerciseThemePreview(page: Page, browser: Browser): Promise<void> {
  await page.goto('http://127.0.0.1:8011/admin')
  await page.getByRole('link', { name: 'Preview the default theme', exact: true }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('The studio lamp.')
  await expect(page.getByText('Theme preview', { exact: true })).toBeVisible()
  await expect(page.getByRole('status')).toContainText('USD 89.00')
  expect(
    (await page.getByLabel('Finish', { exact: true }).boundingBox())?.height,
  ).toBeGreaterThanOrEqual(44)
  await page
    .getByLabel('Finish', { exact: true })
    .selectOption('10000000-0000-4000-8000-000000000003')
  await expect(page.getByRole('status')).toContainText('USD 95.00')
  await expect(page.getByRole('button', { name: 'Update view', exact: true })).toHaveCount(0)
  expect((await page.request.get('/')).status()).toBe(503)
  for (const width of [320, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 900 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    expect(
      (
        await new AxeBuilder({ page })
          .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
          .analyze()
      ).violations,
    ).toEqual([])
  }
  await page.screenshot({ path: '.runtime/screenshots/theme-product-desktop.png', fullPage: true })
  await page.setViewportSize({ width: 320, height: 900 })
  await page.screenshot({ path: '.runtime/screenshots/theme-product-mobile.png', fullPage: true })
  const native = await browser.newContext({
    javaScriptEnabled: false,
    storageState: await page.context().storageState(),
  })
  try {
    const nativePage = await native.newPage()
    const response = await nativePage.goto('http://127.0.0.1:8011/admin/theme-preview')
    expect(response?.status()).toBe(200)
    await expect(nativePage.getByText('USD 89.00', { exact: true })).toBeVisible()
    await expect(
      nativePage.getByText('Illustrative powder-coated steel', { exact: true }),
    ).toBeVisible()
    await nativePage
      .getByLabel('Finish', { exact: true })
      .selectOption('10000000-0000-4000-8000-000000000003')
    await nativePage.getByRole('button', { name: 'Update view', exact: true }).click()
    await expect(nativePage).toHaveURL(/variant=10000000-0000-4000-8000-000000000003$/)
    await expect(nativePage.getByText('USD 95.00', { exact: true })).toBeVisible()
    expect(response?.headers()['cache-control']).toBe('no-store, private')
  } finally {
    await native.close()
  }
}
