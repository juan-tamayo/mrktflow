import { expect, test } from '@playwright/test'
import { signInStaffWithTotp } from './staff-native-login'
import { browserOwnerTotpSecret } from './totp'

/**
 * T081 browser check — permission-gated private exports (OPS-03). GET /
 * stays HTTP 503. Privileged staff pass TOTP. Queueing uses a native form.
 */
test('staff queue a private product export with an expiry notice', async ({ page, request }) => {
  await signInStaffWithTotp(page, browserOwnerTotpSecret())
  await page.goto('/admin/exports')
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Private exports')
  await expect(page.getByRole('note')).toContainText('24 hours')
  await expect(page.getByRole('note')).toContainText('formula')
  await page.locator('select[name="dataset"]').selectOption('products')
  await page.getByRole('button', { name: 'Queue export' }).click()
  await expect(page.getByRole('status')).toContainText('Export queued')
  await expect(page.getByRole('link', { name: 'Download CSV' })).toBeVisible()
  await page.waitForLoadState('domcontentloaded')
  expect((await request.get('/', { timeout: 10_000 })).status()).toBe(503)
})
