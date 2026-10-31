import { test, expect } from '@playwright/test'

/**
 * T054 browser journey — digital-only purchase (FUL-04, WEB-07).
 *
 * A customer buys the Studio Lighting Guide alone, pays nothing through
 * the FREE100 zero-total path (which completes locally, without a
 * provider) and can immediately download the file from the account
 * download area: issue a grant, start the one-hour transfer window and
 * receive the exact file bytes.
 *
 * Fixture preconditions (same installed store the setup journey builds):
 * - Studio Lamp and Studio Lighting Guide published by the catalog flow,
 *   the guide carrying the ready "Lighting guide" file version.
 * - A FREE100 100% coupon, mirroring the PHP fixture catalog, so checkout
 *   completes on the zero-total local path ("Place order").
 * - The checkout.order.paid outbox consumers (receipts and
 *   fulfillment.entitlements) running against the browser database, so
 *   the entitlement exists by the time the downloads area is opened.
 *
 * Execution status: authored for the combined PHP/Node Chromium harness;
 * the VPS test stack has no such harness yet, so this file has not been
 * executed. The executable evidence for the gate lives in
 * tests/Feature/FulfillmentMixedOrderTest.php and
 * tests/Feature/FulfillmentDownloadTest.php.
 */
test('digital purchase completes at zero total and the file downloads from the account area', async ({
  page,
}) => {
  test.setTimeout(120_000)

  await page.goto('/account/register')
  await page.getByLabel('Your name').fill('Digital Shopper')
  await page.getByLabel('Email', { exact: true }).fill('digital@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Fictional shopper passphrase 471!')
  await page.getByLabel('Confirm password').fill('Fictional shopper passphrase 471!')
  await page.getByRole('button', { name: 'Create account', exact: true }).click()
  await expect(page).toHaveURL(/\/account$/)

  await page.goto('/products/studio-guide')
  await expect(page.getByText('Digital download')).toBeVisible()
  await page.getByRole('button', { name: 'Add to cart' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Cart.')
  await expect(page.getByText('Studio Lighting Guide')).toBeVisible()

  await page.getByRole('link', { name: 'Checkout', exact: true }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Checkout.')
  await page.getByLabel('Email').fill('digital@example.test')
  await page.getByLabel('Recipient').first().fill('Digital Shopper')
  await page.getByLabel('Street address').first().fill('10 Market Street')
  await page.getByLabel('City').first().fill('Los Angeles')
  await page.getByLabel('Region or state (optional)').first().fill('CA')
  await page.getByLabel('Postal code (optional)').first().fill('90001')
  await page.getByLabel('Country').first().selectOption('US')
  await page.getByLabel('Coupon (optional)').fill('FREE100')
  await page.getByRole('button', { name: 'Review totals' }).click()
  await expect(page.getByRole('heading', { name: 'Itemized total' })).toBeVisible()
  await page.getByRole('button', { name: 'Place order' }).click()
  await expect(page.getByText('Order confirmed.')).toBeVisible()
  await expect(page.getByText('No charge was taken for a zero total.')).toBeVisible()

  await page.goto('/account/downloads')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Downloads.')
  await expect(page.getByText('Lighting guide')).toBeVisible()
  await expect(page.getByText('5 of 5 downloads remaining')).toBeVisible()

  await page.getByRole('button', { name: 'Download' }).click()
  await expect(page.getByText('Starting spends one of your downloads')).toBeVisible()
  await page.getByRole('button', { name: 'Start download' }).click()
  const downloadPromise = page.waitForEvent('download')
  await page.getByRole('link', { name: 'Download file' }).click()
  const download = await downloadPromise
  expect(download.suggestedFilename()).toBe('studio-guide.txt')

  await page.goto('/account/downloads')
  await expect(page.getByText('4 of 5 downloads remaining')).toBeVisible()
})
