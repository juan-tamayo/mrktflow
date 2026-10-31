import { test, expect } from '@playwright/test'
import { totpCode } from './totp'

/**
 * T054 browser journey — staff fulfillment of a mixed order (FUL-01,
 * FUL-05, WEB-07).
 *
 * Starting from an existing paid mixed order (two Studio Lamps, one
 * Studio Lighting Guide, guide already downloaded once), staff refunds
 * ONE lamp from the admin order page. The refund lands on the physical
 * half only: the customer's downloads area still lists the guide and a
 * fresh download still streams it. Staff then ships the remaining lamp
 * and the customer's order page shows the shipment.
 *
 * Fixture preconditions:
 * - The installed store from the setup journey, with the catalog flow's
 *   Studio Lamp (variant "Chalk", tracked stock) and Studio Lighting
 *   Guide (ready "Lighting guide" file version).
 * - A PAID mixed order placed by mixed@example.test through a priced
 *   checkout with provider test credentials provisioned in the browser
 *   environment. A zero-total order has no refundable capture, so the
 *   FREE100 order from mixed-purchase.spec.ts cannot serve here; the
 *   provider path is what makes the refund real.
 * - The checkout.order.paid outbox consumers running against the
 *   browser database.
 * - Staff sign-in uses the owner credentials from the setup journey
 *   (owner@example.test) with a fresh authenticator code: the harness
 *   provisions the owner's TOTP secret as BROWSER_OWNER_TOTP_SECRET
 *   (captured when the owner account was created during setup).
 *
 * Execution status: authored for the combined PHP/Node Chromium
 * harness; the VPS test stack has no such harness yet, so this file has
 * not been executed. The executable evidence for this exact walk —
 * partial physical refund, download before and after, shipment of the
 * remainder — lives in tests/Feature/FulfillmentMixedOrderTest.php.
 */
test('staff partial physical refund leaves the digital half untouched, then ships the rest', async ({
  browser,
}) => {
  test.setTimeout(180_000)
  const ownerTotpSecret = process.env.BROWSER_OWNER_TOTP_SECRET ?? ''
  expect(ownerTotpSecret).not.toBe('')

  // The customer re-checks the downloads area around the staff actions.
  const customerContext = await browser.newContext()
  const customer = await customerContext.newPage()
  await customer.goto('/account/login')
  await customer.getByLabel('Email', { exact: true }).fill('mixed@example.test')
  await customer.getByLabel('Password', { exact: true }).fill('Fictional shopper passphrase 471!')
  await customer.getByRole('button', { name: 'Sign in' }).click()
  await customer.goto('/account/downloads')
  await expect(customer.getByText('Lighting guide')).toBeVisible()
  await expect(customer.getByText('4 of 5 downloads remaining')).toBeVisible()

  // Staff opens the mixed order and refunds one of the two lamps.
  const staffContext = await browser.newContext()
  const staff = await staffContext.newPage()
  await staff.goto('/admin/login')
  await staff.getByLabel('Email', { exact: true }).fill('owner@example.test')
  await staff.getByLabel('Password', { exact: true }).fill('Fictional owner passphrase 471!')
  await staff.getByRole('button', { name: 'Sign in' }).click()
  await staff.getByLabel('Authentication code').fill(totpCode(ownerTotpSecret))
  await staff.getByRole('button', { name: 'Confirm authenticator' }).click()
  await staff.goto('/admin/orders')
  await staff.locator('a[href^="/admin/orders/"]').first().click()
  await expect(staff.getByRole('heading', { name: 'Refunds' })).toBeVisible()
  await expect(staff.getByRole('heading', { name: 'Refund' })).toBeVisible()
  await staff.getByLabel(/Studio Lamp/).fill('1')
  await staff.getByLabel(/Reason/).fill('One lamp arrived damaged.')
  await staff.getByRole('button', { name: 'Request refund' }).click()
  await expect(staff.getByText('Refund submitted.')).toBeVisible()

  // Nothing digital moved: the guide is still listed and a fresh grant
  // still starts and streams it, spending the allowance normally.
  await customer.goto('/account/downloads')
  await expect(customer.getByText('Lighting guide')).toBeVisible()
  await expect(customer.getByText('4 of 5 downloads remaining')).toBeVisible()
  await customer.getByRole('button', { name: 'Download' }).click()
  await customer.getByRole('button', { name: 'Start download' }).click()
  const downloadPromise = customer.waitForEvent('download')
  await customer.getByRole('link', { name: 'Download file' }).click()
  expect((await downloadPromise).suggestedFilename()).toBe('studio-guide.txt')
  await customer.goto('/account/downloads')
  await expect(customer.getByText('3 of 5 downloads remaining')).toBeVisible()

  // Staff ships the lamp that remains; the customer's order page shows
  // the shipment next to the untouched download.
  await expect(staff.getByRole('heading', { name: 'Ship items' })).toBeVisible()
  await staff.getByLabel(/Studio Lamp/).fill('1')
  await staff.getByRole('button', { name: 'Record shipment' }).click()
  await expect(staff.getByText('Shipment recorded.')).toBeVisible()
  await staffContext.close()

  await customer.goto('/account/orders')
  await customer.getByRole('link', { name: /Order/ }).first().click()
  await expect(customer.getByText('Shipments')).toBeVisible()
  await expect(customer.getByText('Track this shipment')).toBeVisible()
  await customer.goto('/account/downloads')
  await expect(customer.getByText('Lighting guide')).toBeVisible()
  await customerContext.close()
})
