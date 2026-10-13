import { test, expect } from '@playwright/test'

test('cart stays closed until the store is installed', async ({ request }) => {
  expect((await request.get('/')).status()).toBe(503)
  expect((await request.get('/cart')).status()).toBe(503)
  expect((await request.post('/cart/lines', { data: { quantity: 1 } })).status()).toBe(419)
})
