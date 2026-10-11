import { createApp } from 'vue'
import StoreNotice from './storefront/components/StoreNotice.vue'

const outlet = document.getElementById('store-notice')
if (outlet) {
  createApp(StoreNotice, {
    question: outlet.dataset.question ?? '',
    answer: outlet.dataset.answer ?? '',
  }).mount(outlet)
}
