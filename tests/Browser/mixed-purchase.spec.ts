import { test, expect } from '@playwright/test'

/**
 * T054 browser journey — the mixed order fulfillment gate (FUL-01,
 * FUL-04, FUL-05, WEB-07).
 *
 * One purchase containing two Studio Lamps and one Studio Lighting
 * Guide unlocks the guide download immediately, while the lamps have
 * not shipped: the customer's downloads area serves the file and the
 * order page shows no shipment at all. The companion staff-side walk
 * (partial physical refund, then shipping the remainder) is
 * fulfillment.spec.ts.
 *
 * Fixture preconditions (same installed store the setup journey builds):
 * - Studio Lamp (variant "Chalk", tracked stock) and Studio Lighting
 *   Guide published by the catalog flow, the guide carrying the ready
 *   "Lighting guide" file version.
 * - A FREE100 100% coupon, mirroring the PHP fixture catalog, so the
 *   purchase completes on the zero-total local path ("Place order").
 * - The checkout.order.paid outbox consumers running against the
 *   browser database.
 *
 * Execution status: authored for the combined PHP/Node Chromium
 * harness; the VPS test stack has no such harness yet, so this file has
 * not been executed. The executable evidence for this exact gate —
 * including the refund and shipment halves — lives in
 * tests/Feature/FulfillmentMixedOrderTest.php.
 */
test('mixed purchase unlocks downloads while shipping stays pending', async ({ page }) => {
  test.setTimeout(180_000)

  // Buy two lamps and one guide in a single purchase.
  await page.goto('/account/register')
  await page.getByLabel('Your name').fill('Mixed Shopper')
  await page.getByLabel('Email', { exact: true }).fill('mixed@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Fictional shopper passphrase 471!')
  await page.getByLabel('Confirm password').fill('Fictional shopper passphrase 471!')
  await page.getByRole('button', { name: 'Create account', exact: true }).click()
  await expect(page).toHaveURL(/\/account$/)

  await page.goto('/products/studio-lamp')
  await page.getByLabel('Variant').selectOption({ label: 'Chalk' })
  await page.getByRole('button', { name: 'Add to cart' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Cart.')
  const lampLine = page.locator('.theme-cart-lines li').filter({ hasText: 'Studio Lamp' })
  await lampLine.getByLabel('Quantity').fill('2')
  await lampLine.getByRole('button', { name: 'Update' }).click()
  await page.goto('/products/studio-guide')
  await page.getByRole('button', { name: 'Add to cart' }).click()
  await expect(page.getByText('Studio Lighting Guide')).toBeVisible()
  await expect(page.getByText('Studio Lamp')).toBeVisible()

  await page.getByRole('link', { name: 'Checkout', exact: true }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Checkout.')
  await page.getByLabel('Email').fill('mixed@example.test')
  await page.getByLabel('Recipient').first().fill('Mixed Shopper')
  await page.getByLabel('Street address').first().fill('10 Market Street')
  await page.getByLabel('City').first().fill('Los Angeles')
  await page.getByLabel('Region or state (optional)').first().fill('CA')
  await page.getByLabel('Postal code (optional)').first().fill('90001')
  await page.getByLabel('Country').first().selectOption('US')
  await page.getByLabel('Recipient').nth(1).fill('Mixed Shopper')
  await page.getByLabel('Street address').nth(1).fill('10 Market Street')
  await page.getByLabel('City').nth(1).fill('Los Angeles')
  await page.getByLabel('Region or state (optional)').nth(1).fill('CA')
  await page.getByLabel('Postal code (optional)').nth(1).fill('90001')
  await page.getByLabel('Country').nth(1).selectOption('US')
  await page.getByLabel('Coupon (optional)').fill('FREE100')
  await page.getByRole('button', { name: 'Review totals' }).click()
  await expect(page.getByRole('heading', { name: 'Itemized total' })).toBeVisible()
  await page.getByRole('button', { name: 'Place order' }).click()
  await expect(page.getByText('Order confirmed.')).toBeVisible()

  // The digital half is unlocked now; the physical half has not moved.
  await page.goto('/account/downloads')
  await expect(page.getByText('Lighting guide')).toBeVisible()
  await expect(page.getByText('5 of 5 downloads remaining')).toBeVisible()
  await page.getByRole('button', { name: 'Download' }).click()
  await page.getByRole('button', { name: 'Start download' }).click()
  const firstDownload = page.waitForEvent('download')
  await page.getByRole('link', { name: 'Download file' }).click()
  expect((await firstDownload).suggestedFilename()).toBe('studio-guide.txt')

  // The order page shows the physical half exactly as it is: a
  // shipments section with nothing in it yet.
  await page.goto('/account/orders')
  await page.getByRole('link', { name: /Order/ }).first().click()
  await expect(page.getByText('Shipments')).toBeVisible()
  await expect(page.getByText('Track this shipment')).toHaveCount(0)
})
