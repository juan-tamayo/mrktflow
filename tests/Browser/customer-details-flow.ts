import { expect, type Browser, type Page } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'

export async function exerciseCustomerDetails(
  page: Page,
  email: string,
  browser: Browser,
): Promise<void> {
  await page.getByRole('link', { name: 'Edit your profile', exact: true }).click()
  await page.getByLabel('Email', { exact: true }).fill('changed@example.test')
  await page.getByLabel('Current password').fill('Incorrect fictional passphrase')
  await page.getByRole('button', { name: 'Save profile', exact: true }).click()
  await expect(page.getByRole('alert')).toBeFocused()
  await expect(page.getByLabel('Current password')).toHaveValue('')
  await expect(page.getByLabel('Email', { exact: true })).toHaveValue(email)
  await page.getByLabel('Your name').fill('Updated Shopper')
  await page.getByRole('button', { name: 'Save profile', exact: true }).click()
  await expect(page.getByRole('status')).toContainText('Your profile is saved')
  await page.getByRole('link', { name: 'Back to your account', exact: true }).click()
  await expect(page.getByText('Welcome, Updated Shopper.')).toBeVisible()
  await page.getByRole('link', { name: 'Saved addresses', exact: true }).click()
  await expect(page.getByText('No saved addresses yet.')).toBeVisible()
  await page.getByLabel('Address label').fill('Home')
  await page.getByLabel('Recipient', { exact: true }).fill('Updated Shopper')
  await page.getByLabel('Street address', { exact: true }).fill('123 Fictional Street')
  await page.getByLabel('City', { exact: true }).fill('Example City')
  await page.getByLabel('Country', { exact: true }).selectOption('CA')
  await page.getByRole('button', { name: 'Save address', exact: true }).click()
  await expect(page.getByRole('status')).toContainText('Your address is saved')
  await expect(page.getByText('123 Fictional Street', { exact: false })).toBeVisible()
  const audit = await browser.newContext({ storageState: await page.context().storageState() })
  try {
    const auditPage = await audit.newPage()
    await auditPage.goto('http://127.0.0.1:8011/account/addresses')
    for (const width of [320, 768, 1024, 1440]) {
      await auditPage.setViewportSize({ width, height: 900 })
      expect(
        await auditPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
      ).toBe(true)
      expect(
        (
          await new AxeBuilder({ page: auditPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
            .analyze()
        ).violations,
      ).toEqual([])
    }
  } finally {
    await audit.close()
  }
  await page.getByRole('link', { name: 'Edit Home', exact: true }).click()
  await expect(page.getByLabel('Street address', { exact: true })).toHaveValue(
    '123 Fictional Street',
  )
  await page.getByLabel('Street address', { exact: true }).fill('456 Fictional Street')
  await page.getByRole('button', { name: 'Save address', exact: true }).click()
  await expect(page.getByText('456 Fictional Street', { exact: false })).toBeVisible()
  await page.setViewportSize({ width: 320, height: 900 })
  await page.screenshot({
    path: '.runtime/screenshots/customer-addresses-mobile.png',
    fullPage: true,
  })
  await page.getByRole('button', { name: 'Remove Home', exact: true }).click()
  await expect(page.getByRole('status')).toContainText('Your address is removed')
  await expect(page.getByText('No saved addresses yet.')).toBeVisible()
  await expect(page.getByText('456 Fictional Street', { exact: false })).toHaveCount(0)
}
