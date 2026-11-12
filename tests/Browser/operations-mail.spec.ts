import { expect, test } from '@playwright/test'
import { signInStaffWithTotp } from './staff-native-login'
import { browserOwnerTotpSecret } from './totp'

/**
 * T079 browser check — transactional email settings and visible failures
 * (OPS-01, PLAT-05). GET / stays HTTP 503. Privileged staff pass TOTP.
 */
test('staff open email settings and notification failures without claiming exactly-once SMTP', async ({
  page,
  request,
}) => {
  await signInStaffWithTotp(page, browserOwnerTotpSecret())
  await page.goto('/admin/email')
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Transactional email')
  await expect(page.getByRole('note')).toContainText('does not promise exactly-once SMTP')
  await expect(page.getByRole('link', { name: 'Notification failures' })).toBeVisible()
  await page.getByRole('link', { name: 'Notification failures' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Notification failures')
  await expect(page.getByRole('note')).toContainText('does not promise exactly-once SMTP')
  expect((await request.get('/')).status()).toBe(503)
})
