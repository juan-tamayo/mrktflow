import { test, expect } from '@playwright/test'

/**
 * T060 browser journey — saved products across guest, login merge and
 * logout (SHOP-01, SHOP-02).
 *
 * A shopper registers and saves Alpha Camera to the account, signs
 * out, saves Beta Camera as a guest, and signs back in: the saved
 * items page must show the union of both saves exactly once. Removing
 * the guest save leaves the account save, and signing out returns the
 * saved items page to the fresh guest scope — empty.
 *
 * Fixture preconditions (same installed store the setup journey
 * builds, mirroring the PHP fixture catalog in
 * ShoppingToolsCompareTest):
 * - Alpha Camera and Beta Camera published with product pages at
 *   /products/alpha-camera and /products/beta-camera, each with a
 *   single listed variant so "Save for later" posts directly.
 * - Customer registration is open; the journey registers its own
 *   shopper (wishlist@example.test) so no account fixture is needed.
 *
 * Execution status: authored for the combined PHP/Node Chromium
 * harness; the VPS test stack has no such harness yet, so this file
 * has not been executed. The executable evidence for this gate lives
 * in tests/Feature/ShoppingToolsGateTest.php and
 * tests/Feature/ShoppingToolsIsolationTest.php.
 */

test('guest saves merge into the account list at login and stay hidden after logout', async ({
  page,
}) => {
  test.setTimeout(120_000)

  // Register a fresh shopper and save Alpha Camera to the account.
  await page.goto('/account/register')
  await page.getByLabel('Your name').fill('Wishlist Shopper')
  await page.getByLabel('Email', { exact: true }).fill('wishlist@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Fictional shopper passphrase 471!')
  await page.getByLabel('Confirm password').fill('Fictional shopper passphrase 471!')
  await page.getByRole('button', { name: 'Create account', exact: true }).click()
  await expect(page).toHaveURL(/\/account$/)

  await page.goto('/products/alpha-camera')
  await page.getByRole('button', { name: 'Save for later' }).click()
  await expect(page).toHaveURL(/\/wishlist/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Saved items.')
  await expect(page.getByRole('link', { name: 'Alpha Camera' })).toBeVisible()

  // Sign out: the saved items page falls back to the empty guest
  // scope and shows none of the account's saves.
  await page.goto('/account')
  await page.getByRole('button', { name: 'Sign out' }).click()
  await page.goto('/wishlist')
  await expect(page.getByRole('link', { name: 'Alpha Camera' })).toHaveCount(0)
  await expect(page.getByText('You have not saved any items yet.')).toBeVisible()

  // As a guest, save Beta Camera instead.
  await page.goto('/products/beta-camera')
  await page.getByRole('button', { name: 'Save for later' }).click()
  await expect(page).toHaveURL(/\/wishlist/)
  await expect(page.getByRole('link', { name: 'Beta Camera' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Alpha Camera' })).toHaveCount(0)

  // Signing back in merges: both saves, one entry each.
  await page.goto('/account/login')
  await page.getByLabel('Email', { exact: true }).fill('wishlist@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Fictional shopper passphrase 471!')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)
  await page.goto('/wishlist')
  await expect(page.getByRole('link', { name: 'Alpha Camera' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Beta Camera' })).toBeVisible()

  // Removing the guest save leaves the account save untouched.
  await page
    .locator('li', { hasText: 'Beta Camera' })
    .getByRole('button', { name: 'Remove' })
    .click()
  await expect(page.getByRole('link', { name: 'Beta Camera' })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Alpha Camera' })).toBeVisible()
})
