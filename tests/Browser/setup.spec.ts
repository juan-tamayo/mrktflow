import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { readFileSync } from 'node:fs'
import settings from '../Fixtures/store-settings.json' with { type: 'json' }

test('setup stays locked and rejects a mutation without origin or CSRF proof', async ({
  page,
  request,
}) => {
  const response = await page.goto('/setup')
  expect(response?.status()).toBe(200)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Unlock your store setup.')
  await expect(page.getByLabel('Store name')).toHaveCount(0)
  const mutation = await request.post('/setup/settings', { data: { settings, version: 0 } })
  expect(mutation.status()).toBe(419)
  const result = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze()
  expect(result.violations).toEqual([])
})

test('owner can unlock, save and reload interrupted store setup', async ({ page }) => {
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/setup')
  await page.getByLabel('Installation secret').fill('incorrect')
  await page.getByRole('button', { name: 'Unlock setup' }).click()
  await expect(page.getByRole('alert')).toBeVisible()
  await expect(page.getByLabel('Installation secret')).toHaveValue('')
  await page
    .getByLabel('Installation secret')
    .fill(readFileSync('.runtime/browser-setup-secret', 'utf8').trim())
  await page.getByRole('button', { name: 'Unlock setup' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Set up your store.')
  await page.getByLabel('Store name').fill(settings.name)
  await page.getByLabel('Customer support email').fill(settings.contact_email)
  await page.getByLabel('Email sender name').fill(settings.sender_name)
  await page.getByLabel('Email sender address').fill(settings.sender_email)
  await page.getByLabel('Store currency').selectOption(settings.currency)
  await page.getByLabel('Store timezone').selectOption(settings.timezone)
  for (const country of ['Canada', 'United States']) {
    await page.getByLabel('Find a country').fill(country)
    await page.getByRole('checkbox', { name: country, exact: true }).check()
  }
  await page.getByLabel('Find a country').fill('')
  for (const [key, label] of [
    ['privacy', 'Privacy policy'],
    ['terms', 'Terms of sale'],
    ['returns', 'Returns and refunds'],
    ['shipping', 'Shipping policy'],
    ['digital', 'Digital delivery policy'],
  ] as const) {
    await page.getByLabel(label).fill(settings.policies[key])
  }
  await page.getByRole('button', { name: 'Save store details' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Store details saved' })).toBeVisible()
  await page.reload()
  await expect(page.getByLabel('Store name')).toHaveValue(settings.name)
  await expect(page.getByRole('checkbox', { name: 'Canada', exact: true })).toBeChecked()
  await expect(page.getByRole('checkbox', { name: 'United States', exact: true })).toBeChecked()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
    true,
  )
  const result = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze()
  expect(result.violations).toEqual([])
  expect(errors).toEqual([])
  const staleTab = await page.context().newPage()
  await staleTab.goto('/setup')
  await page.getByLabel('Store name').fill('Example Supply updated')
  await page.getByRole('button', { name: 'Save store details' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Store details saved' })).toBeVisible()
  await staleTab.getByLabel('Store name').fill('Stale change')
  await staleTab.getByRole('button', { name: 'Save store details' }).click()
  await expect(staleTab.getByRole('alert')).toContainText('Settings changed')
  await expect(staleTab.getByRole('alert')).toBeFocused()
  const attemptedSave = staleTab.waitForResponse(
    (response) =>
      response.request().method() === 'POST' && response.url().endsWith('/setup/settings'),
  )
  await staleTab.getByRole('button', { name: 'Save store details' }).click()
  await attemptedSave
  await page.reload()
  await expect(page.getByLabel('Store name')).toHaveValue('Example Supply updated')
  await staleTab.getByRole('link', { name: 'Reload the saved store details' }).click()
  await expect(staleTab.getByLabel('Store name')).toHaveValue('Example Supply updated')
  await staleTab.close()
  await page.getByRole('heading', { level: 1 }).click()
  await page.screenshot({ path: '.runtime/screenshots/setup-saved-mobile.png', fullPage: true })
})
