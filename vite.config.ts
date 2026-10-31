import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'

const laravelEntries = [
  'resources/css/app.css',
  'resources/js/app.ts',
  'resources/js/storefront.ts',
  'themes/default/src/theme.css',
  'themes/default/src/product.ts',
  'themes/default/src/watch.ts',
  'themes/default/src/compareTray.ts',
  'themes/default/src/compareActions.ts',
  'themes/default/src/comparisonTable.ts',
  'themes/default/src/search.ts',
  'themes/default/src/cart.ts',
  'themes/default/src/checkout.ts',
  'themes/default/src/cartIndicator.ts',
  'themes/default/src/home.ts',
] as const

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
