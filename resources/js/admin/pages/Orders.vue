<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'

defineOptions({ name: 'AdminOrders' })

type Money = { amount: number; currency: string; exponent: number; formatted: string }
type OrderRow = {
  id: string
  number: string
  email: string
  status: string
  financial_status: string
  fulfillment_status: string
  download_status: string
  created_at: string
  grand_total: Money
}
defineProps<{
  orders: {
    data: OrderRow[]
    next_page_url: string | null
    prev_page_url: string | null
    q: string
    from: string
    to: string
  }
  canWriteNotes: boolean
}>()
</script>

<template>
  <Head title="Orders" />
  <a class="skip-link" href="#orders-main">Skip to orders</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="orders-main" class="setup-main">
      <p class="eyebrow">Orders</p>
      <h1>Orders<span class="accent">.</span></h1>
      <p class="lede">
        Search frozen checkout orders. Financial, shipment, and download states stay independent.
        The store homepage remains closed.
      </p>
      <form method="get" action="/admin/orders" class="setup-form">
        <label>
          Search
          <input
            type="search"
            name="q"
            :value="orders.q"
            maxlength="191"
            autocomplete="off"
            placeholder="Order number or customer email"
          />
        </label>
        <label>
          From date
          <input type="date" name="from" :value="orders.from" />
        </label>
        <label>
          To date
          <input type="date" name="to" :value="orders.to" />
        </label>
        <button type="submit" class="primary-button">Search orders</button>
      </form>
      <p v-if="orders.data.length === 0" class="workspace-note">No orders match this search.</p>
      <ul v-else class="product-list">
        <li v-for="order in orders.data" :key="order.id">
          <a class="tool-link" :href="`/admin/orders/${order.id}`">{{ order.number }}</a>
          <p>
            {{ order.email }} · {{ order.grand_total.formatted }} · {{ order.status }} · financial
            {{ order.financial_status }} · fulfillment {{ order.fulfillment_status }} · downloads
            {{ order.download_status }}
          </p>
        </li>
      </ul>
      <nav aria-label="Order list pages">
        <a v-if="orders.prev_page_url" :href="orders.prev_page_url" class="text-link"
          >Previous orders</a
        >
        <a v-if="orders.next_page_url" :href="orders.next_page_url" class="text-link"
          >More orders</a
        >
      </nav>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
