<script setup lang="ts">
defineProps<{ itemCount: number }>()
</script>

<template>
  <a class="text-link" href="/cart" aria-current="page">
    Cart<span v-if="itemCount > 0"> ({{ itemCount }})</span>
  </a>
</template>
