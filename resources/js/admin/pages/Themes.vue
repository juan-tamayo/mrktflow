<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'

defineOptions({ name: 'ThemeWorkspace' })

type ThemeRecord = {
  theme_id: string
  name: string | null
  version: string | null
  status: string
  activatable: boolean
  errors: string[]
}

defineProps<{
  themes: ThemeRecord[]
  activeThemeId: string | null
  canActivate: boolean
  activated: boolean
}>()

function activate(themeId: string) {
  useForm({ theme_id: themeId }).post('/admin/themes/activate', { preserveScroll: true })
}
</script>

<template>
  <Head title="Themes" />
  <a class="skip-link" href="#themes-main">Skip to themes</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="themes-main" class="setup-main">
      <p class="eyebrow">Storefront appearance</p>
      <h1>Themes<span class="accent">.</span></h1>
      <p class="lede">
        Activation checks storefront route coverage and live section types. An incompatible theme
        stays inactive. The storefront homepage stays closed.
      </p>
      <p v-if="activated" role="status" class="success-notice">Theme activated.</p>
      <p v-if="themes.length === 0" class="workspace-note">No installed themes yet.</p>
      <ul v-else class="product-list">
        <li v-for="theme in themes" :key="theme.theme_id">
          <p class="tool-link">{{ theme.name ?? theme.theme_id }}</p>
          <p>
            {{ theme.theme_id }} · {{ theme.version ?? 'unknown version' }} ·
            {{ theme.status }}
            <span v-if="activeThemeId === theme.theme_id"> · Active</span>
          </p>
          <p v-if="theme.errors.length" role="status">{{ theme.errors.join(' ') }}</p>
          <form
            v-if="canActivate && theme.activatable && activeThemeId !== theme.theme_id"
            @submit.prevent="activate(theme.theme_id)"
          >
            <button
              type="submit"
              class="primary-button"
              :aria-label="`Activate theme ${theme.theme_id}`"
            >
              Activate theme {{ theme.theme_id }}
            </button>
          </form>
        </li>
      </ul>
    </main>
  </div>
</template>
