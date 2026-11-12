<script setup lang="ts">
import type { SpecAttribute } from './specSheet'
defineProps<{
  title: string
  attributes: SpecAttribute[]
}>()
</script>
<template>
  <section class="example-spec-sheet__facts" aria-labelledby="example-specifications-heading">
    <h2 id="example-specifications-heading">Specifications</h2>
    <dl>
      <template v-for="attribute in attributes" :key="attribute.label">
        <dt>{{ attribute.label }}</dt>
        <dd>{{ attribute.value }}</dd>
      </template>
    </dl>
  </section>
</template>
