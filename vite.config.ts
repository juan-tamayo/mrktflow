import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'

function themeManifestEntries(): string[] {
  const root = 'themes'
  if (!existsSync(root)) return []
  const entries: string[] = []
  for (const dirent of readdirSync(root, { withFileTypes: true })) {
    if (!dirent.isDirectory()) continue
    const manifestPath = join(root, dirent.name, 'theme.json')
    if (!existsSync(manifestPath)) continue
    const manifest: { assets?: Record<string, unknown> } = JSON.parse(
      readFileSync(manifestPath, 'utf8'),
    ) as { assets?: Record<string, unknown> }
    const assets = manifest.assets
    if (assets === undefined || Array.isArray(assets) || typeof assets !== 'object') continue
    for (const value of Object.values(assets)) {
      if (typeof value !== 'string' || value.includes('\\') || value.includes('://')) {
        throw new Error(`Theme asset path in ${manifestPath} is not confined.`)
      }
      const relative = value.startsWith('themes/') ? value : join(root, dirent.name, value)
      const prefix = `themes/${dirent.name}/`
      if (
        !relative.startsWith(prefix) ||
        relative.split('/').some((segment) => segment === '' || segment === '.' || segment === '..')
      ) {
        throw new Error(`Theme asset path ${value} leaves ${dirent.name}.`)
      }
      if (!existsSync(relative)) {
        throw new Error(`Theme asset ${relative} is missing.`)
      }
      entries.push(relative.replaceAll('\\', '/'))
    }
  }
  return entries
}

const laravelEntries = [
  ...new Set([
    'resources/css/app.css',
    'resources/js/app.ts',
    'resources/js/storefront.ts',
    'themes/default/src/watch.ts',
    'themes/default/src/compareTray.ts',
    'themes/default/src/compareActions.ts',
    'themes/default/src/comparisonTable.ts',
    'themes/default/src/search.ts',
    'themes/default/src/cart.ts',
    'themes/default/src/checkout.ts',
    'themes/default/src/cartIndicator.ts',
    'themes/default/src/home.ts',
    ...themeManifestEntries(),
  ]),
]

export default defineConfig({
  plugins: [
    // Vitest resolves this file in Vite's "serve" command. laravel-vite-plugin
    // treats that as an HMR server and aborts when CI is set. Unit tests do not
    // need the Laravel plugin; production builds still register it.
    ...(process.env.VITEST
      ? []
      : [
          laravel({
            input: [...laravelEntries],
            refresh: true,
          }),
        ]),
    vue(),
  ],
  server: { host: '127.0.0.1', port: 5173, strictPort: true },
  test: {
    environment: 'jsdom',
    include: ['resources/js/**/*.test.ts', 'themes/**/src/**/*.test.ts'],
    passWithNoTests: false,
    restoreMocks: true,
  },
})
