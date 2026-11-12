import { spawnSync } from 'node:child_process'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { expect, test } from '@playwright/test'
import { signInStaffWithTotp } from './staff-native-login'
import { browserOwnerTotpSecret } from './totp'

/**
 * T078 browser check — AI authoring integration gate (AI-01..AI-06).
 *
 * Staff sign in through native POST forms (privileged 2FA cannot be
 * skipped). Built-in AI stays off in the installed browser store, so
 * this journey proves manual import still works and GET / stays HTTP
 * 503. Page generate → review → apply → publish, plus cancelled/failed
 * /duplicate recovery, is the PHP evidence in
 * tests/Feature/AiAuthoringRecoveryTest.php: Playwright has no
 * provider fixture and must not call a live model.
 *
 * Fixture preconditions (the installed store the setup journey builds):
 * - Owner staff at owner@example.test with TOTP in
 *   BROWSER_OWNER_TOTP_SECRET / MRKTFLOW_BROWSER_TOTP_SECRET.
 * - AI settings default to disabled with no store-owned secret.
 * - Search/content is unused by this spec (content-publish.spec.ts
 *   owns that slot); this import targets the cart content slot.
 */

function templatePackageZip(): string {
  const dir = mkdtempSync(join(tmpdir(), 'mrktflow-t078-'))
  const manifestPath = join(dir, 'manifest.json')
  writeFileSync(
    manifestPath,
    JSON.stringify({
      schemaVersion: '1.0',
      kind: 'template-package',
      documents: [
        {
          kind: 'section_draft',
          page: 'cart',
          slot: 'content',
          sections: [
            {
              id: 'intro',
              type: 'hero',
              version: 1,
              settings: { heading: 'Imported with AI off' },
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

test('staff import with AI disabled and never open GET /', async ({ page, request }) => {
  test.setTimeout(180_000)
  const secret = browserOwnerTotpSecret()
  await signInStaffWithTotp(page, secret)

  const closed = await request.get('/')
  expect(closed.status()).toBe(503)

  await page.goto('/admin/ai')
  await expect(page.getByRole('heading', { level: 1, name: 'AI authoring.' })).toBeVisible()
  await expect(page.getByLabel('Enable built-in AI drafts', { exact: true })).not.toBeChecked()

  await page.goto('/admin/content/import')
  await expect(page.getByRole('heading', { level: 1, name: 'Import template.' })).toBeVisible()
  await page.getByLabel('Template package', { exact: true }).setInputFiles(templatePackageZip())
  await page.getByRole('button', { name: 'Stage and validate package', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Import report', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Apply template to drafts', exact: true }).click()
  await expect(
    page.getByRole('status', { name: 'Import apply result', exact: true }),
  ).toContainText('Applied to drafts')

  const stillClosed = await request.get('/')
  expect(stillClosed.status()).toBe(503)
  expect(await stillClosed.text()).not.toContain('Imported with AI off')
})
