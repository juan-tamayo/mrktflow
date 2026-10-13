<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import AttributeFields, { type SchemaField } from '../components/AttributeFields.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'ProductSchema' })

const props = defineProps<{
  schema: {
    id: string
    key: string
    label: string
    comparison_group: string
    default_template_key: string
    version: number
    attributes: SchemaField[]
  } | null
  saved: boolean
}>()
const errors = ref<HTMLElement | null>(null)
const form = useForm({
  schema: {
    key: props.schema?.key ?? '',
    label: props.schema?.label ?? '',
    comparison_group: props.schema?.comparison_group ?? '',
    default_template_key: props.schema?.default_template_key ?? 'product.default',
    version: props.schema?.version,
    attributes: (props.schema?.attributes ?? []).map((field) => ({
      ...field,
      unit: field.unit ?? '',
      options: field.options ?? [],
    })),
  },
})
function submit() {
  form.transform((data) => ({
    schema: {
      ...data.schema,
      attributes: data.schema.attributes.map((field) => {
        const payload: SchemaField = {
          key: field.key,
          type: field.type,
          required: field.required,
          comparable: field.comparable,
          filterable: field.filterable,
          label: field.label,
          unit: field.unit === '' ? null : field.unit,
        }
        if (field.type === 'enum') {
          payload.options = field.options ?? []
        }
        return payload
      }),
    },
  }))
  const options = {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  }
  if (props.schema === null) {
    form.post('/admin/schemas', options)
    return
  }
  form.patch(`/admin/schemas/${props.schema.id}`, options)
}
</script>

<template>
  <Head :title="schema ? 'Edit product schema' : 'Create a product schema'" />
  <a class="skip-link" href="#schema-main">Skip to schema</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin/schemas" class="text-link">Product schemas</a>
    </header>
    <main id="schema-main" class="setup-main">
      <p class="eyebrow">Typed catalog fields</p>
      <h1>
        {{ schema ? 'Edit product schema' : 'Create a product schema'
        }}<span class="accent">.</span>
      </h1>
      <p class="lede">
        Define typed fields, enum choices, and comparison flags. Assigned products validate against
        the current version. Changing fields on products in use waits for a later migration preview.
      </p>
      <p v-if="saved" role="status" class="success-notice">Schema saved.</p>
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
          <label for="schema-label">Label</label>
          <input
            id="schema-label"
            v-model="form.schema.label"
            name="label"
            maxlength="80"
            required
          />
        </div>
        <div class="setup-grid">
          <div class="setup-field">
            <label for="schema-key">Key</label>
            <input id="schema-key" v-model="form.schema.key" name="key" maxlength="40" required />
          </div>
          <div class="setup-field">
            <label for="schema-group">Comparison group</label>
            <input
              id="schema-group"
              v-model="form.schema.comparison_group"
              name="comparison_group"
              maxlength="40"
              required
            />
          </div>
        </div>
        <input
          type="hidden"
          name="default_template_key"
          :value="form.schema.default_template_key"
        />
        <AttributeFields v-model:attributes="form.schema.attributes" mode="definition" />
        <button type="submit" class="primary-button" :disabled="form.processing">
          {{ form.processing ? 'Saving…' : 'Save schema' }}
        </button>
      </form>
    </main>
    <footer class="footer">
      <span>MRKTFlow</span><a href="/admin/schemas">Return to schemas</a>
    </footer>
  </div>
</template>
