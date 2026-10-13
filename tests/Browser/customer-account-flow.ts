import { expect, type Browser } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { execFileSync } from 'node:child_process'
import { exerciseCustomerDetails } from './customer-details-flow'

function emailCode(purpose: 'verify_email' | 'reset_password'): string {
  const token = execFileSync('bin/php', ['tests/Fixtures/customer-browser-mail.php'], {
    input: purpose,
    encoding: 'utf8',
    timeout: 15000,
  }).trim()
  if (!/^[a-f0-9]{64}$/.test(token)) {
    throw new Error('Browser mail fixture did not return a 64-character credential.')
  }

  return token
}

export async function exerciseCustomerAccount(browser: Browser): Promise<void> {
  await Promise.all([
    exercisePrimaryCustomerAccount(browser),
    exerciseSeparateCustomerDetails(browser),
  ])
}

async function exerciseSeparateCustomerDetails(browser: Browser): Promise<void> {
  const context = await browser.newContext({ javaScriptEnabled: false })
  try {
    const page = await context.newPage()
    await page.goto('http://127.0.0.1:8011/account/register')
    await page.getByLabel('Your name').fill('Details Shopper')
    await page.getByLabel('Email', { exact: true }).fill('details@example.test')
    await page.getByLabel('Password', { exact: true }).fill('Fictional details passphrase 471!')
    await page.getByLabel('Confirm password').fill('Fictional details passphrase 471!')
    await page.getByRole('button', { name: 'Create account', exact: true }).click()
    await expect(page).toHaveURL(/\/account$/)
    await exerciseCustomerDetails(page, 'details@example.test', browser)
  } finally {
    await context.close()
  }
}

async function exercisePrimaryCustomerAccount(browser: Browser) {
  const context = await browser.newContext()
  const page = await context.newPage()
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await page.goto('http://127.0.0.1:8011/account/register')
  await page.getByLabel('Your name').fill('Example Shopper')
  await page.getByLabel('Email', { exact: true }).fill('OWNER@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Fictional shopper passphrase 471!')
  await page.getByLabel('Confirm password').fill('Incorrect fictional confirmation')
  await page.getByRole('button', { name: 'Create account', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('confirmation')
  await expect(page.getByRole('alert')).toBeFocused()
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('')
  await page.getByLabel('Your name').fill('Example Shopper')
  await page.getByLabel('Email', { exact: true }).fill('OWNER@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Fictional shopper passphrase 471!')
  await page.getByLabel('Confirm password').fill('Fictional shopper passphrase 471!')
  await page.getByRole('button', { name: 'Create account', exact: true }).click()
  await expect(page).toHaveURL(/\/account$/)
  await expect(page.getByText('Welcome, Example Shopper.')).toBeVisible()
  await expect(page.getByText('Email not verified', { exact: true })).toBeVisible()
  expect(
    (await page.request.get('/admin/staff', { headers: { Accept: 'application/json' } })).status(),
  ).toBe(401)
  for (const width of [320, 768, 1024, 1440]) {
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
  await page.screenshot({ path: '.runtime/screenshots/customer-account.png', fullPage: true })
  const verification = emailCode('verify_email')
  const credentialRequests: string[] = []
  page.on('request', (request) => {
    if (request.url().includes(verification)) credentialRequests.push(request.url())
  })
  await page.goto(`http://127.0.0.1:8011/account/verify#${verification}`)
  await expect(page).toHaveURL(/\/account\/verify$/)
  await expect(page.getByLabel('Email code')).toHaveValue(verification)
  await page.getByRole('button', { name: 'Confirm email', exact: true }).click()
  await expect(page).toHaveURL(/\/account$/)
  await expect(page.getByText('Email verified', { exact: true })).toBeVisible()
  expect(credentialRequests).toEqual([])
  await page.getByRole('button', { name: 'Sign out', exact: true }).click()
  await expect(page).toHaveURL(/\/account\/login$/)
  await page.goBack()
  await expect(page).toHaveURL(/\/account\/login$/)
  await expect(page.getByText('Example Shopper', { exact: false })).toHaveCount(0)
  await page.getByLabel('Email', { exact: true }).fill('owner@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Wrong fictional passphrase')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('could not be verified')
  await expect(page.getByRole('alert')).toBeFocused()
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('')
  await page.setViewportSize({ width: 320, height: 900 })
  expect(
    (
      await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze()
    ).violations,
  ).toEqual([])
  await page.screenshot({ path: '.runtime/screenshots/customer-login-mobile.png', fullPage: true })
  await page.getByRole('link', { name: 'Forgot your password?' }).click()
  await page.getByLabel('Email', { exact: true }).fill('OWNER@example.test')
  await page.getByRole('button', { name: 'Send reset code', exact: true }).click()
  await expect(page.getByRole('status')).toContainText('If an eligible customer account')
  const reset = emailCode('reset_password')
  await page.goto(`http://127.0.0.1:8011/account/reset-password#${reset}`)
  await expect(page).toHaveURL(/\/account\/reset-password$/)
  await expect(page.getByLabel('Email code')).toHaveValue(reset)
  await page.getByLabel('New password', { exact: true }).fill('Changed fictional passphrase 471!')
  await page.getByLabel('Confirm new password').fill('Wrong fictional confirmation')
  await page.getByRole('button', { name: 'Change password', exact: true }).click()
  await expect(page.getByRole('alert')).toBeFocused()
  await expect(page.getByLabel('Email code')).toHaveValue('')
  await expect(page.getByLabel('New password', { exact: true })).toHaveValue('')
  await page.getByLabel('Email code').fill(reset)
  await page.getByLabel('New password', { exact: true }).fill('Changed fictional passphrase 471!')
  await page.getByLabel('Confirm new password').fill('Changed fictional passphrase 471!')
  expect(
    (
      await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze()
    ).violations,
  ).toEqual([])
  await page.evaluate(() => window.scrollTo(0, 0))
  await page.screenshot({ path: '.runtime/screenshots/customer-reset-mobile.png', fullPage: true })
  await page.getByRole('button', { name: 'Change password', exact: true }).click()
  await expect(page).toHaveURL(/\/account\/login$/)
  await expect(page.getByRole('status')).toContainText('Your password has been changed')
  expect(errors).toEqual([])
  await context.close()

  const noJs = await browser.newContext({ javaScriptEnabled: false })
  const nativePage = await noJs.newPage()
  await nativePage.goto('http://127.0.0.1:8011/account/login')
  await nativePage.getByLabel('Email', { exact: true }).fill('OWNER@example.test')
  await nativePage.getByLabel('Password', { exact: true }).fill('Changed fictional passphrase 471!')
  await nativePage.getByRole('button', { name: 'Sign in', exact: true }).click()
  await expect(nativePage).toHaveURL(/\/account$/)
  await expect(nativePage.getByText('Welcome, Example Shopper.')).toBeVisible()
  await nativePage.getByRole('button', { name: 'Sign out', exact: true }).click()
  await expect(nativePage).toHaveURL(/\/account\/login$/)
  await nativePage.goBack()
  await expect(nativePage).toHaveURL(/\/account\/login$/)
  await expect(nativePage.getByText('Example Shopper', { exact: false })).toHaveCount(0)
  await nativePage.goto('http://127.0.0.1:8011/account/forgot-password')
  await nativePage.getByLabel('Email', { exact: true }).fill('owner@example.test')
  await nativePage.getByRole('button', { name: 'Send reset code', exact: true }).click()
  const nativeReset = emailCode('reset_password')
  await nativePage.getByRole('link', { name: 'I have a reset code' }).click()
  await nativePage.getByLabel('Email code').fill(nativeReset)
  await nativePage
    .getByLabel('New password', { exact: true })
    .fill('Native changed passphrase 471!')
  await nativePage.getByLabel('Confirm new password').fill('Native changed passphrase 471!')
  await nativePage.getByRole('button', { name: 'Change password', exact: true }).click()
  await expect(nativePage).toHaveURL(/\/account\/login$/)
  await nativePage.getByLabel('Email', { exact: true }).fill('owner@example.test')
  await nativePage.getByLabel('Password', { exact: true }).fill('Native changed passphrase 471!')
  await nativePage.getByRole('button', { name: 'Sign in', exact: true }).click()
  await expect(nativePage).toHaveURL(/\/account$/)
  await expect(nativePage.getByText('Email verified', { exact: true })).toBeVisible()
  await noJs.close()
}
