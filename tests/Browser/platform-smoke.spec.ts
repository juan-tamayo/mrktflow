import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'

test('storefront renders on the server and Vue enhances the disclosure under CSP', async ({
  page,
}) => {
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => {
    if (message.type() === 'error' && !message.text().includes('503 (Service Unavailable)')) {
      errors.push(message.text())
    }
  })
  const response = await page.goto('/')
  expect(response?.status()).toBe(503)
  expect(response?.headers()['content-security-policy']).not.toContain('unsafe-inline')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('This store is getting ready.')
  const logo = page.getByRole('link', { name: 'MRKTFlow', exact: true }).locator('img')
  await expect(logo).toBeVisible()
  expect(
    await logo.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0),
  ).toBe(true)
  expect((await page.request.get('/brand/favicon.svg')).status()).toBe(200)
  const button = page.getByRole('button', { name: 'Can I place an order?' })
  await button.focus()
  await page.keyboard.press('Enter')
  await expect(button).toHaveAttribute('aria-expanded', 'true')
  await expect(page.getByRole('region')).toContainText("This store hasn't opened")
  await page.keyboard.press('Space')
  await expect(button).toHaveAttribute('aria-expanded', 'false')
  expect(errors).toEqual([])
})

test('storefront has a useful no-JavaScript fallback', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false })
  const page = await context.newPage()
  await page.goto('http://127.0.0.1:8011/')
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await page.locator('summary').click()
  await expect(page.locator('details p')).toBeVisible()
  await context.close()
})

test('Inertia mounts the closed admin page without exposing management controls', async ({
  page,
}) => {
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  const response = await page.goto('/admin')
  expect(response?.status()).toBe(503)
  await expect(page.getByRole('heading', { name: 'Not open just yet.' })).toBeVisible()
  await expect(page.locator('form')).toHaveCount(0)
  await page.getByRole('link', { name: 'Return to storefront' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toContainText('This store')
  expect(errors).toEqual([])
})

for (const width of [320, 768, 1024, 1440]) {
  test(`closed pages remain accessible at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    for (const path of ['/', '/admin']) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
      ).toBe(true)
      const result = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze()
      expect(result.violations).toEqual([])
    }
  })
}

test('private paths remain inaccessible over real HTTP', async ({ request }) => {
  for (const path of ['/.env', '/storage/private/purchase.pdf', '/admin/refunds', '/setup/secret']) {
    const response = await request.get(path)
    expect(response.status()).toBe(404)
    expect(response.headers()['x-frame-options']).toBe('DENY')
  }
})
