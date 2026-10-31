<script setup lang="ts">
import { computed, ref } from 'vue'
import type { WatchOptionsInput } from './watchData'

const props = defineProps<WatchOptionsInput>()

const variantId = ref(
  props.selectedId ?? (props.variants.length === 1 ? props.variants[0]!.id : ''),
)
const email = ref(props.email)
const stock = ref(false)
const price = ref(false)

const ready = computed(
  () => variantId.value !== '' && email.value.trim() !== '' && (stock.value || price.value),
)
</script>

<template>
  <form method="post" :action="props.endpoint" class="theme-watch-form">
    <input type="hidden" name="_token" :value="props.csrfToken" />
    <div class="setup-field">
      <label for="watch-variant">Variant</label>
      <select id="watch-variant" v-model="variantId" name="variant_id" required>
        <option value="">Choose a combination</option>
        <option v-for="variant in props.variants" :key="variant.id" :value="variant.id">
          {{ variant.label }}
        </option>
      </select>
    </div>
    <div class="setup-field">
      <label for="watch-email">Email for alerts</label>
      <input id="watch-email" v-model="email" name="email" type="email" required />
    </div>
    <fieldset class="setup-field">
      <legend>Email me when</legend>
      <label>
        <input v-model="stock" type="checkbox" name="types[]" value="stock" />
        It is back in stock
      </label>
      <label>
        <input v-model="price" type="checkbox" name="types[]" value="price" />
        The price drops
      </label>
    </fieldset>
    <p class="theme-purchase-note">
      Alerts are a separate opt-in from saving this item. We email a confirmation link first —
      nothing is sent until the address is confirmed, and every alert carries a one-click
      unsubscribe.
    </p>
    <button type="submit" class="text-link" :disabled="!ready">Set alerts</button>
  </form>
</template>
