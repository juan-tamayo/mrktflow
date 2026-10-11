<script setup lang="ts">
import { computed, ref } from 'vue'
import type { PublicVariant } from './variantData'
const props = defineProps<{ variants: PublicVariant[]; selectedId: string }>()
const selectedId = ref(props.selectedId)
const selected = computed(() => props.variants.find((variant) => variant.id === selectedId.value))
</script>

<template>
  <div class="theme-variant-form">
    <div class="setup-field">
      <label for="enhanced-variant">Finish</label
      ><select id="enhanced-variant" v-model="selectedId">
        <option v-for="variant in variants" :key="variant.id" :value="variant.id">
          {{ variant.label }}
        </option>
      </select>
    </div>
    <p class="theme-price" role="status" aria-live="polite" aria-atomic="true">
      <span>Illustrative price</span><strong v-if="selected">{{ selected.money.formatted }}</strong>
    </p>
    <p class="field-help">The price updates when you choose a finish.</p>
  </div>
</template>
