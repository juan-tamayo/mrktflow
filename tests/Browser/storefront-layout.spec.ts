import { test, expect } from '@playwright/test'

/**
 * T062 browser check — every storefront route type renders through the
 * one layout, and the commerce actions live in platform slots
 * (WEB-01, WEB-02, THEME-01).
 *
 * The journey walks the route types a shopper touches — home,
 * category, product, search, compare, wishlist, cart, checkout,
 * account orders/downloads/preferences and the order status page —
 * asserting the shared layout shell (`data-layout="storefront"`, the
 * page key, masthead/footer slots) on each, and the platform slot
 * markers around the commerce actions: the product purchase panel,
 * cart line actions, and the checkout summary + accept/pay forms,
 * which a theme cannot replace. A second test disables JavaScript and
 * still discovers the product and adds it to the cart through the
 * server-rendered forms.
 *
 * Fixture preconditions (same installed store the setup journey
 * builds, mirroring the PHP fixture catalogs):
 * - Lighting category published with Studio Lamp at
 *   /products/studio-lamp; US shipping + tax rules saved so a guest
 *   quote prices; a FREE100 100% coupon for the zero-total digital
 *   purchase that reaches the order status page (as in
 *   digital-purchase.spec.ts), with the Studio Lighting Guide
 *   published at /products/studio-guide carrying its ready file
 *   version and the checkout.order.paid consumers running.
 * - Customer registration is open; the journey registers its own
 *   shopper (layout@example.test).
 * - The payment status return page is covered at the HTTP layer by
 *   tests/Feature/StorefrontThemeTest.php; reaching it in a browser
 *   needs a hosted provider session, which the fixture store does
 *   not configure.
 *
 * Execution status: authored for the combined PHP/Node Chromium
 * harness; the VPS test stack has no such harness yet, so this file
 * has not been executed. The executable evidence for this gate lives
 * in tests/Feature/StorefrontThemeTest.php.
 */

test('all route types render through the one layout with platform commerce slots', async ({
  page,
}) => {
  test.setTimeout(120_000)

  const shell = async (path: string, pageKey: string) => {
    await page.goto(path)
    await expect(page.locator('[data-layout="storefront"]')).toHaveCount(1)
    await expect(page.locator('[data-layout="storefront"]')).toHaveAttribute('data-page', pageKey)
    await expect(page.locator('[data-platform-slot="masthead"]')).toHaveCount(1)
    await expect(page.locator('[data-platform-slot="footer"]')).toHaveCount(1)
  }

  await shell('/', 'home')
  await shell('/categories/lighting', 'category')
  await shell('/search', 'search')
  await shell('/compare', 'compare')
  await shell('/wishlist', 'wishlist')

  // Product: title and price are server-rendered, and the purchase
  // panel is the platform component, not theme markup.
  await shell('/products/studio-lamp', 'product')
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Studio Lamp')
  const purchase = page.locator('[data-platform-slot="product.purchase"]')
  await expect(purchase).toHaveCount(1)
  await expect(purchase.getByRole('button', { name: 'Add to cart' })).toBeVisible()

  // Add through the platform form, then the cart's line actions are
  // the platform component too.
  await purchase.getByRole('button', { name: 'Add to cart' }).click()
  await shell('/cart', 'cart')
  await expect(page.locator('[data-platform-slot="cart.line-actions"]')).toHaveCount(1)

  // Checkout: the itemized summary and the accept/pay forms render in
  // their platform slots inside the real quote flow.
  await page.getByRole('link', { name: 'Checkout' }).click()
  await shell('/checkout', 'checkout')
  await page.getByLabel('Email').fill('layout@example.test')
  await page.getByLabel('Recipient').first().fill('Layout Shopper')
  await page.getByLabel('Street address').first().fill('10 Market Street')
  await page.getByLabel('City').first().fill('Los Angeles')
  await page.getByLabel('Country').first().selectOption('US')
  await page.getByRole('button', { name: 'Review totals' }).click()
  await expect(page.locator('[data-platform-slot="checkout.summary"]')).toHaveCount(1)
  const actions = page.locator('[data-platform-slot="checkout.actions"]')
  await expect(actions).toHaveCount(1)
  await expect(actions.locator('form')).toHaveCount(1)
  await actions.getByLabel(/I acknowledge the store policies/).check()
  await actions.getByRole('button', { name: 'Confirm these totals' }).click()
  await expect(actions.locator('form')).toHaveCount(2)
  await expect(actions.getByRole('button', { name: 'Pay now' })).toBeVisible()

  // Account route types share the same shell.
  await page.goto('/account/register')
  await page.getByLabel('Your name').fill('Layout Shopper')
  await page.getByLabel('Email', { exact: true }).fill('layout@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Fictional shopper passphrase 471!')
  await page.getByLabel('Confirm password').fill('Fictional shopper passphrase 471!')
  await page.getByRole('button', { name: 'Create account', exact: true }).click()
  await expect(page).toHaveURL(/\/account$/)
  await shell('/account/orders', 'account-orders')
  await shell('/account/downloads', 'account-downloads')
  await shell('/account/preferences', 'preferences')
})

test('order status renders through the layout after a zero-total purchase', async ({ page }) => {
  test.setTimeout(120_000)

  await page.goto('/products/studio-guide')
  await page
    .locator('[data-platform-slot="product.purchase"]')
    .getByRole('button', { name: 'Add to cart' })
    .click()
  await page.getByRole('link', { name: 'Checkout' }).click()
  await page.getByLabel('Email').fill('layout-status@example.test')
  await page.getByLabel('Coupon (optional)').fill('FREE100')
  await page.getByLabel('Recipient').first().fill('Layout Shopper')
  await page.getByLabel('Street address').first().fill('10 Market Street')
  await page.getByLabel('City').first().fill('Los Angeles')
  await page.getByLabel('Country').first().selectOption('US')
  await page.getByRole('button', { name: 'Review totals' }).click()
  const actions = page.locator('[data-platform-slot="checkout.actions"]')
  await actions.getByLabel(/I acknowledge the store policies/).check()
  await actions.getByRole('button', { name: 'Confirm these totals' }).click()
  await actions.getByRole('button', { name: 'Place order' }).click()
  await expect(page).toHaveURL(/\/checkout\/orders\//)
  await expect(page.locator('[data-layout="storefront"]')).toHaveAttribute(
    'data-page',
    'order-status',
  )
  await expect(page.getByText('Order confirmed')).toBeVisible()
})

test('product discovery and add-to-cart work with JavaScript disabled', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false })
  const page = await context.newPage()
  await page.goto('http://127.0.0.1:8011/products/studio-lamp')
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Studio Lamp')
  await expect(page.getByText('USD 24.99')).toBeVisible()
  await page
    .locator('[data-platform-slot="product.purchase"]')
    .getByRole('button', { name: 'Add to cart' })
    .click()
  await expect(page).toHaveURL(/\/cart/)
  await expect(page.locator('[data-platform-slot="cart.line-actions"]')).toHaveCount(1)
  await context.close()
})
