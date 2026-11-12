import { expect, test } from '@playwright/test'
import { signInStaffWithTotp } from './staff-native-login'
import { browserOwnerTotpSecret } from './totp'

/**
 * T083 browser check — owner diagnostics and audit (OPS-04, PLAT-05). GET /
 * stays HTTP 503. Privileged staff pass TOTP.
 */
test('owner opens system health and audit without private payloads', async ({ page, request }) => {
  await signInStaffWithTotp(page, browserOwnerTotpSecret())
  await page.goto('/admin/health')
  await expect(page.getByRole('heading', { level: 1 })).toContainText('System health')
  await expect(page.getByRole('note')).toContainText('1.0.0')
  await expect(page.getByRole('button', { name: 'Download support export' })).toBeVisible()
  await page.getByRole('link', { name: 'Audit log' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Audit log')
  expect((await request.get('/')).status()).toBe(503)
})
