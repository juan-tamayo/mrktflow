<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'
</script>

<template>
  <Head title="Administration unavailable" />
  <a class="skip-link" href="#main">Skip to content</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/" />
    </header>
    <main id="main" class="intro">
      <p class="eyebrow">Store administration</p>
      <h1>Not open<br />just yet.</h1>
      <p class="lede">
        Store setup is not complete. Administration will be available after the owner finishes
        setup.
      </p>
      <a class="text-link" href="/">Return to storefront <span aria-hidden="true">↗</span></a>
    </main>
    <footer class="footer"><span>MRKTFlow</span><span>Administration unavailable</span></footer>
  </div>
</template>
