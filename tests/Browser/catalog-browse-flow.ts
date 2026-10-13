import { expect, type Page } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'

export async function exerciseCatalogBrowse(page: Page): Promise<void> {
  await page.goto('http://127.0.0.1:8011/admin')
  await page.getByRole('link', { name: 'Product schemas', exact: true }).click()
  await page.getByRole('link', { name: 'Create a schema' }).click()
  await page.getByRole('textbox', { name: 'Label', exact: true }).fill('Camera')
  await page.getByRole('textbox', { name: 'Key', exact: true }).fill('camera')
  await page.getByRole('textbox', { name: 'Comparison group', exact: true }).fill('cameras')
  await page.getByRole('button', { name: 'Add attribute' }).click()
  await page.getByLabel('Label').nth(1).fill('Megapixels')
  await page.getByLabel('Key').nth(1).fill('sensor.megapixels')
  await page.getByLabel('Type').selectOption('decimal')
  await page.getByLabel('Unit').fill('MP')
  await page.getByRole('button', { name: 'Add attribute' }).click()
  await page.getByLabel('Label').nth(2).fill('Lens mount')
  await page.getByLabel('Key').nth(2).fill('lens.mount')
  await page.getByLabel('Type').nth(1).selectOption('enum')
  await page.getByRole('button', { name: 'Add choice' }).click()
  await page.getByLabel('Choice 1').fill('rf')
  await page.getByRole('button', { name: 'Add choice' }).click()
  await page.getByLabel('Choice 2').fill('e')
  await page.getByRole('button', { name: 'Save schema' }).click()
  await expect(page.getByRole('status')).toContainText('Schema saved')
  await page.goto('http://127.0.0.1:8011/admin')
  await page.getByRole('link', { name: 'Categories', exact: true }).click()
  await page.getByRole('link', { name: 'Create a category' }).click()
  await page.getByRole('textbox', { name: 'Title', exact: true }).fill('Lighting')
  await page.getByRole('textbox', { name: 'Slug', exact: true }).fill('lighting')
  await page
    .getByRole('textbox', { name: 'Description', exact: true })
    .fill('Lamps and fixtures for the studio.')
  await page.getByRole('button', { name: 'Publish' }).click()
  await expect(page.getByRole('status')).toContainText('Category saved')
  const empty = await page.request.get('/categories/lighting')
  expect(empty.status()).toBe(200)
  expect(await empty.text()).toContain('No products in this category yet')
  await page.goto('http://127.0.0.1:8011/admin/products/create')
  await page.getByRole('textbox', { name: 'Title', exact: true }).fill('Studio Lamp')
  await page.getByRole('textbox', { name: 'Slug', exact: true }).fill('studio-lamp')
  await page.getByRole('textbox', { name: 'SKU', exact: true }).fill('LAMP-001')
  await page
    .getByRole('textbox', { name: 'Description', exact: true })
    .fill('A simple physical lamp for the workbench.')
  await page.getByLabel('Price in the smallest currency unit').fill('2499')
  await page
    .getByRole('textbox', { name: 'Image description', exact: true })
    .fill('Brushed steel studio lamp')
  const image = page.getByLabel('Image', { exact: true })
  await image.setInputFiles({
    name: 'lamp.png',
    mimeType: 'image/png',
    buffer: Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
      'base64',
    ),
  })
  await page.getByText('lighting', { exact: true }).click()
  await page.getByLabel('Product schema').selectOption({ label: 'Camera' })
  await page.getByLabel('Megapixels').fill('24.2')
  await page.getByLabel('Lens mount').selectOption('rf')
  await page.getByRole('button', { name: 'Publish' }).click()
  await expect(page.getByRole('status')).toContainText('Product saved')
  await page.goto('http://127.0.0.1:8011/admin/schemas')
  await page.getByRole('link', { name: 'Camera' }).click()
  await page.getByRole('button', { name: 'Add attribute' }).click()
  await page.getByLabel('Label').last().fill('Weight')
  await page.getByLabel('Key').last().fill('body.weight')
  await page.getByLabel('Type').last().selectOption('integer')
  await page.getByLabel('Unit').last().fill('g')
  await page.getByRole('checkbox', { name: 'Required' }).last().uncheck()
  await page.getByRole('button', { name: 'Preview migration' }).click()
  await expect(page.getByText('Affected products: 1')).toBeVisible()
  await page.getByRole('button', { name: 'Apply migration' }).click()
  await expect(page.getByRole('status')).toContainText('Schema migration applied')
  await page.goto('http://127.0.0.1:8011/categories/lighting')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Lighting.')
  await expect(page.getByRole('link', { name: 'Studio Lamp' })).toBeVisible()
  await page.getByRole('link', { name: 'Studio Lamp' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Studio Lamp.')
  await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText('Lighting')
  await expect(page.getByText('Megapixels')).toBeVisible()
  await expect(page.getByText('24.2', { exact: true })).toBeVisible()
  await page.goto('http://127.0.0.1:8011/admin/products')
  await page.getByRole('link', { name: 'Studio Lamp' }).click()
  await page.getByRole('button', { name: 'Add option' }).click()
  await page.getByLabel('Name', { exact: true }).fill('Finish')
  await page.getByLabel('Value', { exact: true }).fill('Pine')
  await page.getByRole('button', { name: 'Add value' }).click()
  const valueFields = page.getByLabel('Value', { exact: true })
  await valueFields.nth(1).fill('Chalk')
  await page.getByRole('button', { name: 'Create combinations' }).click()
  await page.getByLabel('Price in the smallest currency unit').nth(2).fill('2699')
  await page.getByRole('button', { name: 'Save variants' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Product saved' })).toBeVisible()
  await page.getByLabel('Track inventory').check()
  await expect(page.getByLabel('On-hand quantity')).toBeVisible()
  await page.getByLabel('On-hand quantity').fill('2')
  await page.getByLabel('Low-stock threshold').fill('3')
  await page.getByRole('button', { name: 'Save stock' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Product saved' })).toBeVisible()
  await expect(page.getByRole('status').filter({ hasText: 'Low stock' })).toBeVisible()
  await page.goto('http://127.0.0.1:8011/products/studio-lamp')
  await expect(page.getByRole('status')).toContainText(
    'Choose a finish and size to see the current price',
  )
  const native = await page.request.get('/products/studio-lamp')
  expect(await native.text()).toContain('name="variant"')
  await page.getByLabel('Finish').selectOption({ label: 'Chalk' })
  await expect(page.getByRole('status')).toContainText('USD 26.99')
  expect((await page.request.get('/products/missing-lamp')).status()).toBe(404)
  await page.goto('http://127.0.0.1:8011/admin/products/create')
  await page.getByLabel('Product type').selectOption('digital')
  await page.getByRole('textbox', { name: 'Title', exact: true }).fill('Studio Lighting Guide')
  await page.getByRole('textbox', { name: 'Slug', exact: true }).fill('studio-guide')
  await page.getByRole('textbox', { name: 'SKU', exact: true }).fill('GUIDE-001')
  await page
    .getByRole('textbox', { name: 'Description', exact: true })
    .fill('A fictional downloadable lighting guide.')
  await page.getByLabel('Price in the smallest currency unit').fill('899')
  await page
    .getByRole('textbox', { name: 'Image description', exact: true })
    .fill('Cover of the studio lighting guide')
  await page.getByLabel('Image', { exact: true }).setInputFiles({
    name: 'cover.png',
    mimeType: 'image/png',
    buffer: Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
      'base64',
    ),
  })
  await page.getByRole('button', { name: 'Publish' }).click()
  await expect(page.getByRole('status')).toContainText('Product saved')
  await page.getByRole('textbox', { name: 'File label' }).fill('Lighting guide')
  await page.getByLabel('Private file').setInputFiles({
    name: 'studio-guide.txt',
    mimeType: 'text/plain',
    buffer: Buffer.from('Fictional studio lighting guide.\n'),
  })
  await page.getByRole('button', { name: 'Upload file version' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Product saved' })).toBeVisible()
  await page.getByRole('checkbox', { name: /Lighting guide/ }).check()
  await page.getByRole('button', { name: 'Attach files' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Product saved' })).toBeVisible()
  const digital = await page.request.get('/products/studio-guide')
  expect(digital.status()).toBe(200)
  const digitalBody = await digital.text()
  expect(digitalBody).toContain('Digital download')
  expect(digitalBody).toContain('Lighting guide')
  expect(digitalBody).toContain('Quantity one')
  expect(digitalBody).not.toContain('original_key')
  expect(digitalBody).not.toContain('/original')
  expect((await page.request.get('/')).status()).toBe(503)
  expect(
    (
      await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze()
    ).violations,
  ).toEqual([])
}
