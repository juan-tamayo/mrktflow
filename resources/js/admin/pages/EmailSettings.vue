<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'EmailSettings' })

type Template = { key: string; label: string; policyClass: string; purpose: string | null }
const props = defineProps<{
  sender: { email: string | null; name: string | null }
  mailerReady: boolean
  exactlyOnce: boolean
  disclaimer: string
  templates: Template[]
  preview: { template: string; html: string }
  tested: boolean
}>()
const errors = ref<HTMLElement | null>(null)
const form = useForm({
  template: props.preview.template,
  idempotency_key: crypto.randomUUID(),
})
function submit() {
  form.idempotency_key = crypto.randomUUID()
  form.post('/admin/email/test', {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
</script>

<template>
  <Head title="Email settings" />
  <a class="skip-link" href="#email-main">Skip to email settings</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="email-main" class="setup-main">
      <p class="eyebrow">Operations</p>
      <h1>Transactional email<span class="accent">.</span></h1>
      <p class="lede">
        Transactional templates stay deliverable when a contact opts out of marketing. Marketing
        templates recheck purpose grants and suppression immediately before send.
      </p>
      <p role="note" class="hint">{{ disclaimer }}</p>
      <p v-if="!exactlyOnce" class="hint">Exactly-once SMTP is not claimed.</p>
      <p v-if="tested" role="status" class="success-notice">
        Test message queued to your staff address.
      </p>
      <p class="lede">
        Sender {{ sender.name ?? 'not configured' }} &lt;{{ sender.email ?? 'not configured' }}&gt;.
        {{
          mailerReady
            ? 'The reviewed mailer is ready for the array or SMTP transport.'
            : 'Mailer configuration is not ready.'
        }}
        SMTP cannot report bounces; use the preference center or provider feedback for suppression.
      </p>
      <p>
        <a class="text-link" href="/admin/notifications">Notification failures</a>
      </p>
      <form class="setup-form" @submit.prevent="submit">
        <div
          v-if="Object.keys(form.errors).length"
          ref="errors"
          role="alert"
          tabindex="-1"
          class="error-summary"
        >
          <p v-for="(message, field) in form.errors" :key="field">{{ message }}</p>
        </div>
        <div class="setup-field">
          <label for="email-template">Template</label>
          <select id="email-template" v-model="form.template" required>
            <option v-for="item in templates" :key="item.key" :value="item.key">
              {{ item.label }} ({{ item.policyClass }})
            </option>
          </select>
        </div>
        <p class="hint">
          Preview a template:
          <a
            v-for="item in templates"
            :key="item.key"
            class="text-link"
            :href="`/admin/email?template=${item.key}`"
            >{{ item.label }}</a
          >
        </p>
        <button type="submit" class="primary-button" :disabled="form.processing">
          {{ form.processing ? 'Sending test…' : 'Send test to my staff address' }}
        </button>
      </form>
      <section class="setup-field" aria-labelledby="email-preview-heading">
        <h2 id="email-preview-heading">Preview</h2>
        <p class="hint">Sample copy only. Preview does not send mail.</p>
        <iframe
          title="Email preview"
          sandbox=""
          :srcdoc="preview.html"
          style="width: 100%; min-height: 16rem; background: #fff"
        />
      </section>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
