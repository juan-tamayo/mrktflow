import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'

export default defineConfig({
  plugins: [
    laravel({
      input: [
        'resources/css/app.css',
        'resources/js/app.ts',
        'resources/js/storefront.ts',
        'themes/default/src/theme.css',
        'themes/default/src/product.ts',
      ],
      refresh: true,
    }),
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
