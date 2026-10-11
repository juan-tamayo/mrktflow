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
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'bin/php scripts/browser-server.php',
    url: 'http://127.0.0.1:8011/up',
    reuseExistingServer: false,
    timeout: 60_000,
  },
})
