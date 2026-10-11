import { expect, type Browser } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'

export async function exerciseCustomerAccount(browser: Browser) {
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
  expect(errors).toEqual([])
  await context.close()

  const noJs = await browser.newContext({ javaScriptEnabled: false })
  const nativePage = await noJs.newPage()
  await nativePage.goto('http://127.0.0.1:8011/account/login')
  await nativePage.getByLabel('Email', { exact: true }).fill('OWNER@example.test')
  await nativePage.getByLabel('Password', { exact: true }).fill('Fictional shopper passphrase 471!')
  await nativePage.getByRole('button', { name: 'Sign in', exact: true }).click()
  await expect(nativePage).toHaveURL(/\/account$/)
  await expect(nativePage.getByText('Welcome, Example Shopper.')).toBeVisible()
  await nativePage.getByRole('button', { name: 'Sign out', exact: true }).click()
  await expect(nativePage).toHaveURL(/\/account\/login$/)
  await nativePage.goBack()
  await expect(nativePage).toHaveURL(/\/account\/login$/)
  await expect(nativePage.getByText('Example Shopper', { exact: false })).toHaveCount(0)
  await noJs.close()
}
