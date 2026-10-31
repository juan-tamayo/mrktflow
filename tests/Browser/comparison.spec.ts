import { test, expect, type Page } from '@playwright/test'

/**
 * T059 browser journey — compare tray and comparison table (SHOP-05,
 * SHOP-06, WEB-06).
 *
 * Four products are added to the compare tray from catalog cards using
 * the keyboard alone at a 360px viewport, a fifth is refused, and the
 * comparison table is then driven by keyboard: the differences-only
 * toggle, a column removal and a column replacement. At 360px every
 * row must stay reachable without horizontal scrolling.
 *
 * Fixture preconditions (same installed store the setup journey builds,
 * mirroring the PHP fixture catalog in ShoppingToolsCompareTest):
 * - Alpha Camera, Beta Camera and Pro Camera published in the cameras
 *   comparison group, Field Notebook published in stationery, and a
 *   fifth published product (Gamma Camera) so the tray cap is testable.
 * - Search lists all five products with their compare controls.
 *
 * Execution status: authored for the combined PHP/Node Chromium harness;
 * the VPS test stack has no such harness yet, so this file has not been
 * executed. The executable evidence for this slice lives in the Vue unit
 * suites (compareData, ProductActions, CompareTray, ComparisonTable)
 * and tests/Feature/ShoppingToolsCompareTest.php.
 */

async function tabUntilFocused(page: Page, name: string): Promise<void> {
  const target = page.getByRole('button', { name }).or(page.getByRole('link', { name }))
  for (let attempt = 0; attempt < 60; attempt++) {
    const focused = await target.evaluateAll((elements) =>
      elements.some((element) => element === document.activeElement),
    )
    if (focused) return
    await page.keyboard.press('Tab')
  }
  throw new Error(`Could not reach "${name}" by keyboard`)
}

test('four products compare from a 360px keyboard-only flow', async ({ page }) => {
  test.setTimeout(120_000)
  await page.setViewportSize({ width: 360, height: 740 })

  await page.goto('/search')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Search.')

  for (const title of ['Alpha Camera', 'Beta Camera', 'Pro Camera', 'Field Notebook']) {
    await tabUntilFocused(page, `Add ${title} to compare`)
    await page.keyboard.press('Enter')
    await expect(page.getByText(`${title} added to compare.`).first()).toBeVisible()
  }
  await expect(page.getByText('Compare tray · 4 of 4')).toBeVisible()

  await tabUntilFocused(page, 'Add Gamma Camera to compare')
  await page.keyboard.press('Enter')
  await expect(page.getByText('Compare holds up to four items.')).toBeVisible()
  await expect(page.getByText('Compare tray · 4 of 4')).toBeVisible()

  await tabUntilFocused(page, 'Compare items')
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL(/\/compare\?/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Compare items.')

  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth)
  expect(scrollWidth).toBeLessThanOrEqual(360)
  const cellBoxes = await page.locator('.compare-table td').evaluateAll((cells) =>
    cells.map((cell) => {
      const box = cell.getBoundingClientRect()
      return { left: box.left, right: box.right }
    }),
  )
  expect(cellBoxes.length).toBeGreaterThan(0)
  for (const box of cellBoxes) {
    expect(box.left).toBeGreaterThanOrEqual(0)
    expect(box.right).toBeLessThanOrEqual(360)
  }

  const differences = page.getByLabel('Show differences only')
  await differences.focus()
  await page.keyboard.press('Space')
  await expect(differences).toBeChecked()
  await expect(page.getByText(/details where these items differ/)).toBeVisible()

  await tabUntilFocused(page, 'Remove Field Notebook from comparison')
  await page.keyboard.press('Enter')
  await expect(page.getByText('Field Notebook removed from the comparison.')).toBeVisible()
  await expect(page.getByRole('link', { name: 'Field Notebook' })).toHaveCount(0)
  const focusedLabel = await page.evaluate(
    () => document.activeElement?.getAttribute('aria-label') ?? '',
  )
  expect(focusedLabel).toMatch(/^Remove .+ from comparison$/)

  const replacement = page.getByLabel('Replace Alpha Camera with')
  await replacement.focus()
  await replacement.selectOption({ label: 'Gamma Camera · Pine' })
  await tabUntilFocused(page, 'Replace Alpha Camera')
  await page.keyboard.press('Enter')
  await expect(page.getByText('Alpha Camera replaced with Gamma Camera.')).toBeVisible()
  await expect(page.getByRole('link', { name: 'Gamma Camera' })).toBeVisible()

  await page.goto('/search')
  await expect(page.getByText('Compare tray · 3 of 4')).toBeVisible()
})
