import { spawnSync } from 'node:child_process'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { expect, test } from '@playwright/test'
import { signInStaffWithTotp } from './staff-native-login'
import { browserOwnerTotpSecret } from './totp'

/**
 * T072 browser check — import → edit → preview → publish → rollback
 * (THEME-02, THEME-03, THEME-04, THEME-05).
 *
 * Staff sign in through native POST forms, stage a data-only ZIP onto
 * the search content slot (so the home/content draft used by
 * content-editor.spec.ts stays untouched), edit the imported heading,
 * preview without publishing, publish, then restore the prior snapshot.
 * A CMS page takes the same preview/publish/rollback path and is the
 * public evidence: GET /pages/gate-studio shows live copy only. GET /
 * stays HTTP 503.
 *
 * Fixture preconditions (the installed store the setup journey builds):
 * - Owner staff at owner@example.test with TOTP in
 *   BROWSER_OWNER_TOTP_SECRET / MRKTFLOW_BROWSER_TOTP_SECRET.
 * - The search/content section draft starts empty.
 * - No page at slug gate-studio.
 *
 * Execution status: authored for the combined PHP/Node Chromium
 * harness. The executable evidence for this gate lives in
 * tests/Feature/ThemesContentGateTest.php.
 */

function templatePackageZip(): string {
  const dir = mkdtempSync(join(tmpdir(), 'mrktflow-t072-'))
  const manifestPath = join(dir, 'manifest.json')
  writeFileSync(
    manifestPath,
    JSON.stringify({
      schemaVersion: '1.0',
      kind: 'template-package',
      documents: [
        {
          kind: 'section_draft',
          page: 'search',
          slot: 'content',
          sections: [
            {
              id: 'intro',
              type: 'hero',
              version: 1,
              settings: { heading: 'Imported search banner' },
            },
          ],
        },
      ],
      media: [],
      references: { products: [], categories: [] },
    }),
  )
  const zipPath = join(dir, 'template.zip')
  const packed = spawnSync(
    'python3',
    [
      '-c',
      'import zipfile,sys; z=zipfile.ZipFile(sys.argv[1],"w"); z.write(sys.argv[2],"manifest.json")',
      zipPath,
      manifestPath,
    ],
    { encoding: 'utf8' },
  )
  if (packed.status !== 0) {
    throw new Error(packed.stderr || packed.stdout || 'Failed to write the template ZIP.')
  }
  return zipPath
}

test('staff import, edit, preview, publish and rollback without opening GET /', async ({
  page,
  request,
}) => {
  test.setTimeout(180_000)
  const secret = browserOwnerTotpSecret()
  await signInStaffWithTotp(page, secret)

  const closed = await request.get('/')
  expect(closed.status()).toBe(503)

  await page.goto('/admin/content/import')
  await expect(page.getByRole('heading', { level: 1, name: 'Import template.' })).toBeVisible()
  await page.getByLabel('Template package', { exact: true }).setInputFiles(templatePackageZip())
  await page.getByRole('button', { name: 'Stage and validate package', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Import report', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Apply template to drafts', exact: true }).click()
  await expect(
    page.getByRole('status', { name: 'Import apply result', exact: true }),
  ).toContainText('Applied to drafts')

  await page.goto('/admin/content-editor')
  await page.locator('#editor-page').selectOption('search')
  await page.locator('#editor-slot').selectOption('content')
  await expect(page.locator('[data-section-id="hero"]')).toBeVisible()
  await page.locator('[data-section-id="hero"] [data-action="configure"]').click()
  await page.getByLabel('Heading (required)', { exact: true }).fill('Edited search banner')
  await page.getByRole('button', { name: 'Save draft', exact: true }).click()
  await expect(page.locator('[role="status"]')).toContainText('Draft saved')
  await page.getByRole('button', { name: 'Refresh preview', exact: true }).click()
  const frame = page.frameLocator('iframe[title="Section draft preview"]')
  await expect(frame.locator('[data-section-type="hero"]')).toContainText('Edited search banner')

  await page.getByRole('link', { name: 'Preview search content sections', exact: true }).click()
  await expect(page.getByText('Edited search banner')).toBeVisible()
  await expect(page.getByText('Content preview')).toBeVisible()
  await page.goto('/admin/content-editor')
  await page.locator('#editor-page').selectOption('search')
  await page.getByRole('button', { name: 'Publish search content sections', exact: true }).click()
  await expect(page.getByText(/Live revision/)).toBeVisible()

  await page.goto('/admin/content')
  await page.locator('#page-title').fill('Gate studio')
  await page.locator('#page-slug').fill('gate-studio')
  await page.locator('#page-status').selectOption('published')
  await page.getByRole('button', { name: 'Save page', exact: true }).click()
  await expect(page.getByRole('status')).toContainText('Page saved')
  const publicPage = await request.get('/pages/gate-studio')
  expect(publicPage.status()).toBe(200)
  expect(await publicPage.text()).toContain('Gate studio')

  await page.getByRole('link', { name: 'Preview page Gate studio', exact: true }).click()
  await expect(page.getByRole('heading', { name: /Gate studio/ })).toBeVisible()

  await page.goto('/admin/content-editor')
  await page.locator('#editor-page').selectOption('search')
  await page
    .getByRole('button', {
      name: 'Restore prior published revision of search content sections',
      exact: true,
    })
    .click()

  const homepage = await request.get('/')
  expect(homepage.status()).toBe(503)
  expect(await homepage.text()).not.toContain('Edited search banner')
  expect(await homepage.text()).not.toContain('Imported search banner')
})
