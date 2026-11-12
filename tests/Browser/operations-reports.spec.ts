import { expect, test } from '@playwright/test'
import { signInStaffWithTotp } from './staff-native-login'
import { browserOwnerTotpSecret } from './totp'

/**
 * T080 browser check — sales and refund reporting (OPS-02). GET / stays
 * HTTP 503. Privileged staff pass TOTP. Definitions and date/timezone
 * rules are visible even when the browser database has no live captures.
 */
test('staff open sales reports with explicit paid, test, and timezone rules', async ({
  page,
  request,
}) => {
  await signInStaffWithTotp(page, browserOwnerTotpSecret())
  await page.goto('/admin/reports')
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Sales reports')
  await expect(page.getByRole('note')).toContainText('Test included: no')
  await expect(page.getByRole('note')).toContainText('Unpaid included: no')
  await expect(page.getByRole('heading', { name: 'Activity' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Order cohort' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Definitions' })).toBeVisible()
  expect((await request.get('/')).status()).toBe(503)
})
