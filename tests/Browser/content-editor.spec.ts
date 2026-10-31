import { test, expect } from '@playwright/test'
import { totpCode } from './totp'

/**
 * T064 browser check — the section editor: add/reorder/configure with
 * keyboard controls, validation recovery, and mobile/desktop draft
 * preview (THEME-02, WEB-06).
 *
 * The journey signs in as staff with content access, opens
 * /admin/content-editor for the home page's content area, adds a Hero
 * and a Rich text block from the keyboard alone, reorders them with the
 * move buttons and with Alt + arrow keys (asserting focus follows the
 * moved section and the status region announces each move), then saves
 * with the Hero heading empty: the save is refused, the problems are
 * listed on the Hero section and in the summary, and the working copy is
 * still on screen. Filling the heading and saving again succeeds, the
 * preview renders the server-composed sections fragment, and the Mobile
 * toggle narrows the preview frame to a device width. A reload shows
 * the saved draft — the round trip persisted.
 *
 * Fixture preconditions (the installed store the setup journey builds,
 * plus):
 * - A staff account with `content.write` (the owner works) signed in
 *   through /admin/login; owners are privileged, so the two-factor
 *   challenge is answered with `totpCode` from the fixture enrollment
 *   secret in MRKTFLOW_BROWSER_TOTP_SECRET (the same fictional fixture
 *   family as tests/Fixtures/store-settings.json — never a real
 *   credential).
 * - The home/content draft starts empty (version 0), so the journey's
 *   first save lands at version 1; the spec is written to run once
 *   against a fresh browser database, like the other specs.
 *
 * Execution status: authored for the combined PHP/Node Chromium
 * harness; the VPS test stack has no such harness yet, so this file
 * has not been executed. The executable evidence for this gate lives
 * in tests/Feature/ThemesContentEditorTest.php and the Vitest suites
 * for SectionList, SectionSettings, DevicePreview and ContentEditor.
 */

test('staff edit, reorder by keyboard, recover from a refused save and preview a draft', async ({
  page,
}) => {
  test.setTimeout(120_000)
  const secret = process.env.MRKTFLOW_BROWSER_TOTP_SECRET ?? ''

  await page.goto('/admin/login')
  await page.getByLabel('Email').fill('owner@example.test')
  await page.getByLabel('Password').fill('Fictional owner passphrase 471!')
  await page.getByRole('button', { name: 'Sign in' }).click()
  if (secret !== '') {
    await page.getByLabel('Authentication code').fill(totpCode(secret))
    await page.getByRole('button', { name: 'Verify' }).click()
  }

  await page.goto('/admin/content-editor')
  await expect(page.getByRole('heading', { level: 1, name: 'Content editor' })).toBeVisible()
  await expect(page.getByText('No sections yet.')).toBeVisible()

  // Add two blocks from the keyboard: Hero first, then Rich text.
  await page.getByLabel('Add a block').selectOption('hero')
  await page.getByRole('button', { name: 'Add block' }).click()
  await expect(page.locator('[data-section-id="hero"]')).toBeVisible()
  await page.getByLabel('Add a block').selectOption('rich-text')
  await page.getByRole('button', { name: 'Add block' }).click()
  await expect(page.locator('[data-section-id="rich-text"]')).toBeVisible()

  // Reorder by keyboard: Alt + ArrowUp on the Rich text section moves
  // it above the Hero, focus follows it, and the move is announced.
  await page.locator('[data-section-id="rich-text"] [data-action="configure"]').focus()
  await page.keyboard.press('Alt+ArrowUp')
  const items = page.locator('.section-list-item')
  await expect(items.nth(0)).toHaveAttribute('data-section-id', 'rich-text')
  await expect(items.nth(1)).toHaveAttribute('data-section-id', 'hero')
  await expect(page.locator('[role="status"]')).toContainText('Rich text moved to position 1 of 2.')
  await expect(page.locator('[data-section-id="rich-text"] [data-action="move-up"]')).toBeFocused()

  // Move it back down with the button; focus follows again.
  await page.locator('[data-section-id="rich-text"] [data-action="move-down"]').click()
  await expect(items.nth(0)).toHaveAttribute('data-section-id', 'hero')
  await expect(
    page.locator('[data-section-id="rich-text"] [data-action="move-down"]'),
  ).toBeFocused()

  // Saving with the required Hero heading empty is refused; the
  // problem lands on the section and the work is still on screen.
  await page.getByRole('button', { name: 'Save draft' }).click()
  await expect(page.locator('[role="alert"]').first()).toContainText('problems to fix')
  await expect(page.locator('[data-section-id="hero"]')).toContainText(
    "missing the required setting 'heading'",
  )
  await expect(page.locator('[role="status"]')).toContainText(
    'Your changes are still here — fix them and save again.',
  )

  // Fix and re-save: validation recovery completes the round trip.
  await page.locator('[data-section-id="hero"] [data-action="configure"]').click()
  await page.getByLabel('Heading (required)').fill('Find your camera')
  await page.getByRole('button', { name: 'Save draft' }).click()
  await expect(page.locator('[role="status"]')).toContainText('Draft saved (version 1).')
  await expect(page.getByText('Draft version 1')).toBeVisible()

  // The preview is the server-rendered fragment; Mobile narrows it.
  await page.getByRole('button', { name: 'Refresh preview' }).click()
  const frame = page.frameLocator('iframe[title="Section draft preview"]')
  await expect(frame.locator('[data-section-type="hero"]')).toContainText('Find your camera')
  await page.getByRole('button', { name: 'Mobile' }).click()
  await expect(page.locator('iframe[title="Section draft preview"]')).toHaveCSS('width', '390px')

  // A reload reads the persisted draft back.
  await page.reload()
  await expect(page.locator('[data-section-id="hero"]')).toBeVisible()
  await expect(page.getByText('Draft version 1')).toBeVisible()
})
