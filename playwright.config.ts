import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './tests/Browser',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: 'http://127.0.0.1:8011',
    trace: process.env.CI ? 'off' : 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'closed',
      testMatch: /platform-smoke\.spec\.ts$|cart\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'install',
      testMatch: /setup\.spec\.ts$/,
      dependencies: ['closed'],
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'shop-orders',
      testMatch: /mixed-purchase\.spec\.ts$/,
      dependencies: ['install'],
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'shop',
      testIgnore:
        /platform-smoke\.spec\.ts$|cart\.spec\.ts$|setup\.spec\.ts$|mixed-purchase\.spec\.ts$/,
      dependencies: ['shop-orders'],
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: {
    command: 'bin/php scripts/browser-server.php',
    url: 'http://127.0.0.1:8011/up',
    reuseExistingServer: false,
    timeout: 60_000,
  },
})
