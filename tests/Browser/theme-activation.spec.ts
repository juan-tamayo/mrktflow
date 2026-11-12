import { spawnSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { signInStaffWithTotp } from './staff-native-login'
import { browserOwnerTotpSecret } from './totp'

/**
 * T072 browser check — developer-theme validation, default-theme
 * activation, custom product/category templates, and intact checkout
 * (THEME-01, THEME-06).
 *
 * Staff sign in through native POST forms. The spec registers the
 * reviewed example theme and the default theme through theme:validate
 * against the browser database, activates Default from /admin/themes,
 * assigns product.compact and category.landing, then buys the catalog
 * Studio Lamp and Studio Lighting Guide. GET / stays HTTP 503.
 *
 * Fixture preconditions:
 * - Installed store from the setup journey, with the catalog flow's
 *   Studio Lamp and Studio Lighting Guide plus FREE100.
 * - Owner TOTP in BROWSER_OWNER_TOTP_SECRET.
 *
 * Execution status: authored for the combined PHP/Node Chromium
 * harness. The executable evidence for this gate lives in
 * tests/Feature/ThemesContentGateTest.php.
 */

function browserProcessEnv(): NodeJS.ProcessEnv {
  const extra: NodeJS.ProcessEnv = { ...process.env }
  for (const line of readFileSync('.env.browser', 'utf8').split(/\r?\n/)) {
    const trimmed = line.trim()
    if (trimmed === '' || trimmed.startsWith('#')) {
      continue
    }
    const separator = trimmed.indexOf('=')
    if (separator < 1) {
      continue
    }
    let value = trimmed.slice(separator + 1)
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1)
    }
    extra[trimmed.slice(0, separator)] = value
  }
  return extra
}

function validateTheme(directory: string): void {
  const result = spawnSync('bin/php', ['artisan', 'theme:validate', directory], {
    encoding: 'utf8',
    env: browserProcessEnv(),
  })
  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || `theme:validate ${directory} failed.`)
  }
}

test('staff activate the default theme, assign custom templates, and keep checkout intact', async ({
  page,
  request,
}) => {
  test.setTimeout(180_000)
  validateTheme('themes/example')
  validateTheme('themes/default')

  const secret = browserOwnerTotpSecret()
  await signInStaffWithTotp(page, secret)

  await page.goto('/admin/themes')
  await expect(page.getByRole('heading', { level: 1, name: 'Themes.' })).toBeVisible()
  await page.getByRole('button', { name: 'Activate theme mrktflow/default', exact: true }).click()
  await expect(page.getByRole('status')).toContainText('Theme activated')
  await expect(page.getByLabel('Installed theme mrktflow/default', { exact: true })).toContainText(
    'Active',
  )
  await expect(
    page.getByRole('button', { name: 'Activate theme mrktflow/default', exact: true }),
  ).toHaveCount(0)
  await expect(page.getByLabel('Installed theme mrktflow/example', { exact: true })).toBeVisible()

  await page.goto('/admin/products')
  await page.getByRole('link', { name: 'Studio Lamp', exact: true }).click()
  await page
    .getByLabel('Visual template for Studio Lamp', { exact: true })
    .selectOption({ value: 'product.compact' })
  await page
    .getByRole('button', { name: 'Assign visual template for Studio Lamp', exact: true })
    .click()
  await expect(page.getByRole('status')).toContainText('Visual template assigned')

  await page.goto('/admin/categories')
  await page.getByRole('link', { name: 'Lighting', exact: true }).click()
  await page
    .getByLabel('Visual template for Lighting', { exact: true })
    .selectOption({ value: 'category.landing' })
  await page
    .getByRole('button', { name: 'Assign visual template for Lighting', exact: true })
    .click()
  await expect(page.getByRole('status')).toContainText('Visual template assigned')

  await page.goto('/products/studio-lamp')
  await expect(page.locator('[data-visual-template="product.compact"]')).toBeVisible()
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Cart.')

  await page.goto('/categories/lighting')
  await expect(page.locator('[data-visual-template="category.landing"]')).toBeVisible()
  await expect(page.getByText('Category landing')).toBeVisible()

  await page.goto('/products/studio-guide')
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click()
  await expect(page.getByText('Studio Lighting Guide')).toBeVisible()
  await expect(page.getByText('Studio Lamp')).toBeVisible()
  await page.getByRole('link', { name: 'Checkout', exact: true }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Checkout.')
  await page.getByLabel('Email', { exact: true }).fill('theme-gate@example.test')
  await page.getByLabel('Recipient').first().fill('Theme Gate Shopper')
  await page.getByLabel('Street address').first().fill('10 Market Street')
  await page.getByLabel('City').first().fill('Los Angeles')
  await page.getByLabel('Region or state (optional)').first().fill('CA')
  await page.getByLabel('Postal code (optional)').first().fill('90001')
  await page.getByLabel('Country').first().selectOption('US')
  await page.getByLabel('Recipient').nth(1).fill('Theme Gate Shopper')
  await page.getByLabel('Street address').nth(1).fill('10 Market Street')
  await page.getByLabel('City').nth(1).fill('Los Angeles')
  await page.getByLabel('Region or state (optional)').nth(1).fill('CA')
  await page.getByLabel('Postal code (optional)').nth(1).fill('90001')
  await page.getByLabel('Country').nth(1).selectOption('US')
  await page.getByLabel('Coupon (optional)', { exact: true }).fill('FREE100')
  await page.getByRole('button', { name: 'Review totals', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Itemized total' })).toBeVisible()
  const actions = page.locator('[data-platform-slot="checkout.actions"]')
  await actions.getByLabel(/I acknowledge the store policies/).check()
  await actions.getByRole('button', { name: 'Confirm these totals', exact: true }).click()
  await actions.getByRole('button', { name: 'Place order', exact: true }).click()
  await expect(page.getByText('Order confirmed.')).toBeVisible()

  const homepage = await request.get('/')
  expect(homepage.status()).toBe(503)
})
