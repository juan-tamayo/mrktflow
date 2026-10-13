import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { readFileSync } from 'node:fs'
import settings from '../Fixtures/store-settings.json' with { type: 'json' }
import { exerciseCustomerAccount } from './customer-account-flow'
import { exerciseCatalogBrowse } from './catalog-browse-flow'
import { exerciseThemePreview } from './theme-preview-flow'
import { totpCode } from './totp'

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
  for (const path of [
    '/admin/staff/invitations',
    '/admin/two-factor',
    '/admin/two-factor/challenge',
    '/admin/two-factor/reauthenticate',
    '/staff/invitation',
    '/account/register',
    '/account/login',
    '/account/logout',
    '/account/forgot-password',
    '/account/verification',
    '/account/verify',
    '/account/reset-password',
    '/account/profile',
    '/account/addresses',
    '/account/addresses/00000000-0000-4000-8000-000000000000',
    '/account/addresses/00000000-0000-4000-8000-000000000000/delete',
    '/checkout',
    '/store-api/v1/checkout-quotes',
    '/store-api/v1/checkout-sessions',
    '/store-api/v1/checkout-sessions/00000000-0000-4000-8000-000000000000/payment',
    '/checkout/00000000-0000-4000-8000-000000000000/pay',
    '/admin/pricing-preview',
  ]) {
    expect(
      (
        await request.post(path, {
          data: { invitation_token: '0'.repeat(64), owner: {}, invitation: {} },
        })
      ).status(),
    ).toBe(419)
  }
  const result = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze()
  expect(result.violations).toEqual([])
})

test('owner can unlock, save and reload interrupted store setup', async ({ page, browser }) => {
  test.setTimeout(240_000)
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
  await page.getByLabel('Your name').fill('Example Owner')
  await page.getByLabel('Owner email').fill('owner@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Fictional owner passphrase 471!')
  await page.getByLabel('Confirm password').fill('Incorrect fictional confirmation')
  await page.getByRole('button', { name: 'Create owner account' }).click()
  await expect(page.getByRole('alert')).toContainText('confirmation')
  await expect(page.getByRole('alert')).toBeFocused()
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('')
  await page.getByLabel('Password', { exact: true }).fill('Fictional owner passphrase 471!')
  await page.getByLabel('Confirm password').fill('Fictional owner passphrase 471!')
  await page.getByRole('button', { name: 'Create owner account' }).click()
  await expect(page).toHaveURL(/\/admin\/two-factor$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Protect this account.')
  const authenticatorSecret = await page.getByLabel('Authenticator secret').inputValue()
  await page.getByLabel('Authentication code').fill(totpCode(authenticatorSecret))
  await page.getByRole('button', { name: 'Confirm authenticator' }).click()
  await expect(page.getByRole('status')).toContainText('Recovery codes are shown once')
  const recoveryCode = (await page.locator('.recovery-code').first().textContent()) ?? ''
  expect(recoveryCode).toMatch(/^[a-f0-9]{4}-[a-f0-9]{4}$/)
  await page.getByRole('link', { name: 'Continue to workspace' }).click()
  await expect(page).toHaveURL(/\/admin$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Your store starts here.')
  await page.reload()
  await expect(page.getByText('Welcome, Example Owner.', { exact: false })).toBeVisible()
  expect((await page.request.get('/setup')).status()).toBe(404)
  await page.getByRole('button', { name: 'Sign out' }).click()
  await expect(page).toHaveURL(/\/admin\/login$/)
  await page.goBack()
  await expect(page).toHaveURL(/\/admin\/login$/)
  await expect(page.getByText('Welcome, Example Owner.', { exact: false })).toHaveCount(0)
  await page.getByLabel('Email', { exact: true }).fill('owner@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Wrong fictional passphrase')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('could not be verified')
  await expect(page.getByRole('alert')).toBeFocused()
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('')
  for (const width of [320, 768, 1440]) {
    await page.setViewportSize({ width, height: 900 })
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true)
    expect(
      (
        await new AxeBuilder({ page })
          .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
          .analyze()
      ).violations,
    ).toEqual([])
  }
  await page.getByLabel('Email', { exact: true }).fill('OWNER@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Fictional owner passphrase 471!')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await expect(page).toHaveURL(/\/admin\/two-factor$/)
  await page.getByLabel('Authentication code').fill(totpCode(authenticatorSecret))
  await page.getByRole('button', { name: 'Verify' }).click()
  await expect(page).toHaveURL(/\/admin$/)
  await page.reload()
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()
  expect(
    (
      await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze()
    ).violations,
  ).toEqual([])
  for (const width of [320, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 900 })
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true)
    await expect(
      page.getByRole('region', { name: "A little preparation. A store that's yours." }),
    ).toContainText('Storefront closed')
    expect(
      (
        await new AxeBuilder({ page })
          .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
          .analyze()
      ).violations,
    ).toEqual([])
    if (width === 320)
      await page.screenshot({
        path: '.runtime/screenshots/owner-workspace-mobile.png',
        fullPage: true,
      })
  }
  await page.screenshot({ path: '.runtime/screenshots/owner-workspace.png', fullPage: true })
  await page.getByRole('link', { name: 'Manage staff' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Staff access.')
  await page.getByLabel('Role for Example Owner').selectOption('manager')
  await page.getByRole('button', { name: 'Save access for Example Owner' }).click()
  await expect(page.getByRole('alert')).toContainText('another active owner')
  await expect(page.getByRole('alert')).toBeFocused()
  await page.getByLabel('Role for Example Owner').selectOption('owner')
  await page.getByRole('button', { name: 'Save access for Example Owner' }).click()
  await expect(page.getByRole('status')).toContainText('Staff access saved')
  await page.reload()
  await expect(page.getByLabel('Role for Example Owner')).toHaveValue('owner')
  await page.setViewportSize({ width: 320, height: 900 })
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
    true,
  )
  expect(
    (
      await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze()
    ).violations,
  ).toEqual([])
  await page.screenshot({ path: '.runtime/screenshots/staff-mobile.png', fullPage: true })
  await page.getByLabel('Invited staff email').fill('editor@example.test')
  await page.getByRole('button', { name: 'Create invitation link' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Invitation created' })).toBeVisible()
  const invitationUrl = await page.getByLabel('Private invitation link').inputValue()
  const token = new URL(invitationUrl).hash.slice(1)
  expect(await page.evaluate(() => JSON.stringify(window.history.state))).not.toContain(token)
  const recipient = await browser.newContext()
  const invitedPage = await recipient.newPage()
  const requestedUrls: string[] = []
  invitedPage.on('request', (request) => requestedUrls.push(request.url()))
  invitedPage.on('pageerror', (error) => errors.push(error.message))
  await invitedPage.goto(invitationUrl)
  await expect(invitedPage).toHaveURL(/\/staff\/invitation$/)
  expect(await invitedPage.evaluate(() => JSON.stringify(window.history.state))).not.toContain(
    token,
  )
  await invitedPage.getByLabel('Your name').fill('Example Editor')
  await invitedPage.getByLabel('Password', { exact: true }).fill('Fictional editor passphrase 471!')
  await invitedPage.getByLabel('Confirm password').fill('Incorrect fictional confirmation')
  await invitedPage.getByRole('button', { name: 'Accept invitation' }).click()
  await expect(invitedPage.getByRole('alert')).toContainText('confirmation')
  await expect(invitedPage.getByRole('alert')).toBeFocused()
  await expect(invitedPage.getByLabel('Password', { exact: true })).toHaveValue('')
  await invitedPage.getByLabel('Password', { exact: true }).fill('Fictional editor passphrase 471!')
  await invitedPage.getByLabel('Confirm password').fill('Fictional editor passphrase 471!')
  await invitedPage.getByRole('button', { name: 'Accept invitation' }).click()
  await expect(invitedPage).toHaveURL(/\/admin$/)
  await expect(invitedPage.getByRole('link', { name: 'Manage staff' })).toHaveCount(0)
  expect((await invitedPage.request.get('/admin/staff')).status()).toBe(403)
  expect(requestedUrls.some((url) => url.includes(token))).toBe(false)
  await page.reload()
  await expect(page.getByLabel('Private invitation link')).toHaveCount(0)
  await page.getByLabel('Role for Example Editor').selectOption('fulfillment')
  await page.getByRole('button', { name: 'Save access for Example Editor' }).click()
  await expect(page.getByRole('status')).toContainText('Staff access saved')
  await invitedPage.reload()
  await expect(invitedPage).toHaveURL(/\/admin\/login$/)
  await recipient.close()
  const replay = await browser.newContext()
  const replayPage = await replay.newPage()
  await replayPage.goto(invitationUrl)
  await replayPage.getByLabel('Your name').fill('Example Replay')
  await replayPage.getByLabel('Password', { exact: true }).fill('Fictional editor passphrase 471!')
  await replayPage.getByLabel('Confirm password').fill('Fictional editor passphrase 471!')
  await replayPage.getByRole('button', { name: 'Accept invitation' }).click()
  await expect(replayPage.getByRole('alert')).toContainText('already used')
  expect(
    (
      await new AxeBuilder({ page: replayPage })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze()
    ).violations,
  ).toEqual([])
  await replay.close()
  await Promise.all([exerciseCustomerAccount(browser), exerciseThemePreview(page, browser)])
  await exerciseCatalogBrowse(page)
  const lostDevice = await browser.newContext()
  const lostPage = await lostDevice.newPage()
  await lostPage.goto('/admin/login')
  await lostPage.getByLabel('Email', { exact: true }).fill('owner@example.test')
  await lostPage.getByLabel('Password', { exact: true }).fill('Fictional owner passphrase 471!')
  await lostPage.getByRole('button', { name: 'Sign in', exact: true }).click()
  await lostPage.getByLabel('Authentication code').fill(recoveryCode)
  await lostPage.getByRole('button', { name: 'Verify' }).click()
  await expect(lostPage).toHaveURL(/\/admin\/two-factor$/)
  await expect(lostPage.getByRole('heading', { level: 1 })).toHaveText('Protect this account.')
  await lostDevice.close()
  expect(errors).toEqual([])
})
