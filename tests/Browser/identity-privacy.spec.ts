import { expect, test } from '@playwright/test'
import { signInStaffWithTotp } from './staff-native-login'
import { browserOwnerTotpSecret } from './totp'

/**
 * T082 browser check — account data and retention workflow (ID-06). GET /
 * stays HTTP 503. Privileged staff pass TOTP. Processing uses a native form.
 */
test('staff open privacy requests with retention notice', async ({ page, request }) => {
  await signInStaffWithTotp(page, browserOwnerTotpSecret())
  await page.goto('/admin/privacy')
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Privacy requests')
  await expect(page.getByRole('note')).toContainText('Financial history is never blanket-deleted')
  expect((await request.get('/', { timeout: 10_000 })).status()).toBe(503)
})
