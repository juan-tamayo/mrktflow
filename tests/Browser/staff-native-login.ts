import { expect, type Page } from '@playwright/test'
import { totpCode } from './totp'

const OWNER_EMAIL = 'owner@example.test'
const OWNER_PASSWORD = 'Fictional owner passphrase 471!'

/**
 * Staff sign-in and TOTP challenge use native HTML form POST (no
 * Inertia intercept). Accessible names are exact so they do not collide
 * with customer Sign in or other Authentication code fields.
 */
export async function signInStaffWithTotp(page: Page, secret: string): Promise<void> {
  await page.goto('/admin/login')
  await page.getByLabel('Email', { exact: true }).fill(OWNER_EMAIL)
  await page.getByLabel('Password', { exact: true }).fill(OWNER_PASSWORD)
  await page
    .locator('form[action="/admin/login"]')
    .getByRole('button', { name: 'Sign in', exact: true })
    .click()
  if (secret !== '') {
    await expect(page).toHaveURL(/\/admin\/two-factor/)
    await page.getByLabel('Authentication code', { exact: true }).fill(totpCode(secret))
    await page
      .locator('form[action="/admin/two-factor/challenge"]')
      .getByRole('button', { name: 'Verify', exact: true })
      .click()
  }
  await expect(page).not.toHaveURL(/\/admin\/login$/)
  await expect(page).not.toHaveURL(/\/admin\/two-factor/)
}
