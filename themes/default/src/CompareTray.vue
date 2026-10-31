<script setup lang="ts">
import { computed } from 'vue'
import {
  COMPARE_TRAY_LIMIT,
  comparePageUrl,
  sharedCompareTrayStore,
  type CompareTrayStore,
} from './compareData'

const props = defineProps<{ store?: CompareTrayStore }>()

const store = props.store ?? sharedCompareTrayStore()
const tray = computed(() => store.tray.value)
const announcement = computed(() => store.announcement.value)
const url = computed(() => comparePageUrl(tray.value))
</script>

<template>
  <div>
    <section v-if="tray.length > 0" class="theme-compare-tray" aria-label="Compare tray">
      <p class="theme-compare-count">
        Compare tray · {{ tray.length }} of {{ COMPARE_TRAY_LIMIT }}
      </p>
      <ul class="theme-compare-entries">
        <li v-for="entry in tray" :key="entry.productId">
          <span>
            {{ entry.title }}
            <template v-if="entry.variantLabel !== 'Standard'">
              · {{ entry.variantLabel }}
            </template>
          </span>
          <button
            type="button"
            class="text-link"
            :aria-label="`Remove ${entry.title} from compare`"
            @click="store.remove(entry.productId)"
          >
            Remove
          </button>
        </li>
      </ul>
      <a v-if="tray.length >= 2" class="text-link" :href="url">Compare items</a>
      <p v-else class="theme-purchase-note">Add one more item to compare.</p>
    </section>
    <p v-if="announcement !== ''" class="theme-purchase-note" role="status">{{ announcement }}</p>
  </div>
</template>
