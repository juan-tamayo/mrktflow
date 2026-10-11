<script setup lang="ts">
withDefaults(defineProps<{ href?: string }>(), { href: '/' })
</script>

<template>
  <a :href="href" class="wordmark" aria-label="MRKTFlow">
    <img :src="'/brand/mark.svg'" width="36" height="36" alt="" />
    <span>mrktflow</span>
  </a>
</template>
