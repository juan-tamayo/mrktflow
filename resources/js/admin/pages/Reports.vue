<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'

defineOptions({ name: 'SalesReports' })

type Money = { amount: number; currency: string; exponent: number; formatted: string }
type Totals = {
  paid_orders: number
  gross: Money
  discounts: Money
  refunds: Money
  net: Money
  tax: Money
  shipping: Money
  aov: Money
}
defineProps<{
  report: {
    timezone: string
    from: string
    to: string
    currency: string
    exponent: number
    rules: {
      include_paid: boolean
      include_test: boolean
      include_unpaid: boolean
      date_basis: string
      capture_date: string
      refund_date: string
      cohort_refunds: string
    }
    definitions: Record<string, string>
    activity: Totals
    cohort: Totals
  }
}>()
</script>

<template>
  <Head title="Sales reports" />
  <a class="skip-link" href="#reports-main">Skip to sales reports</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="reports-main" class="setup-main">
      <p class="eyebrow">Operations</p>
      <h1>Sales reports<span class="accent">.</span></h1>
      <p class="lede">
        Paid live captures only. Test-mode Stripe attempts and unpaid orders are excluded. Dates use
        the store timezone. Activity refunds land on the succeeded refund date; cohort detail keeps
        refunds on the original capture date.
      </p>
      <p role="note" class="hint">
        Timezone {{ report.timezone }}. Paid included:
        {{ report.rules.include_paid ? 'yes' : 'no' }}. Test included:
        {{ report.rules.include_test ? 'yes' : 'no' }}. Unpaid included:
        {{ report.rules.include_unpaid ? 'yes' : 'no' }}. Activity refund date:
        {{ report.rules.refund_date }}. Cohort refunds: {{ report.rules.cohort_refunds }}.
      </p>
      <form method="get" action="/admin/reports" class="setup-form">
        <label>
          From date
          <input type="date" name="from" :value="report.from" required />
        </label>
        <label>
          To date
          <input type="date" name="to" :value="report.to" required />
        </label>
        <button type="submit" class="primary-button">Update range</button>
      </form>
      <p
        v-if="
          report.activity.paid_orders === 0 &&
          report.activity.refunds.amount === 0 &&
          report.cohort.paid_orders === 0
        "
        class="workspace-note"
      >
        No paid live captures or succeeded merchandise refunds in this range.
      </p>
      <section aria-labelledby="activity-heading">
        <h2 id="activity-heading">Activity</h2>
        <p class="hint">Captures by captured time; refunds by succeeded refund time.</p>
        <ul class="product-list">
          <li>Paid orders {{ report.activity.paid_orders }}</li>
          <li>Gross merchandise {{ report.activity.gross.formatted }}</li>
          <li>Merchandise discounts {{ report.activity.discounts.formatted }}</li>
          <li>Merchandise refunds {{ report.activity.refunds.formatted }}</li>
          <li>Net merchandise {{ report.activity.net.formatted }}</li>
          <li>Tax {{ report.activity.tax.formatted }}</li>
          <li>Shipping {{ report.activity.shipping.formatted }}</li>
          <li>Average order value {{ report.activity.aov.formatted }}</li>
        </ul>
      </section>
      <section aria-labelledby="cohort-heading">
        <h2 id="cohort-heading">Order cohort</h2>
        <p class="hint">Same captures; refunds stay with the original capture date.</p>
        <ul class="product-list">
          <li>Paid orders {{ report.cohort.paid_orders }}</li>
          <li>Gross merchandise {{ report.cohort.gross.formatted }}</li>
          <li>Merchandise discounts {{ report.cohort.discounts.formatted }}</li>
          <li>Merchandise refunds {{ report.cohort.refunds.formatted }}</li>
          <li>Net merchandise {{ report.cohort.net.formatted }}</li>
          <li>Tax {{ report.cohort.tax.formatted }}</li>
          <li>Shipping {{ report.cohort.shipping.formatted }}</li>
          <li>Average order value {{ report.cohort.aov.formatted }}</li>
        </ul>
      </section>
      <section aria-labelledby="definitions-heading">
        <h2 id="definitions-heading">Definitions</h2>
        <ul class="product-list">
          <li v-for="(text, key) in report.definitions" :key="key">
            <strong>{{ key }}.</strong> {{ text }}
          </li>
        </ul>
      </section>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
