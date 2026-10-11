<script setup lang="ts">
import { ref, useId } from 'vue'

defineProps<{ question: string; answer: string }>()
const open = ref(false)
const id = useId()
</script>

<template>
  <div class="store-notice">
    <button :id="`${id}-button`" :aria-expanded="open" :aria-controls="id" @click="open = !open">
      {{ question }}
      <span aria-hidden="true">{{ open ? '−' : '+' }}</span>
    </button>
    <div v-if="open" :id="id" role="region" :aria-labelledby="`${id}-button`">
      <p>{{ answer }}</p>
    </div>
  </div>
</template>
