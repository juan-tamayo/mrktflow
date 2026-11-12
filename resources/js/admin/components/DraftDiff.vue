<script setup lang="ts">
defineOptions({ name: 'DraftDiff' })

const props = defineProps<{
  beforeTitle: string
  afterTitle: string
  beforeSummary: string
  afterSummary: string
}>()
</script>

<template>
  <section aria-labelledby="draft-diff-heading" class="draft-diff">
    <h2 id="draft-diff-heading">Proposed changes</h2>
    <div class="draft-diff-grid">
      <div>
        <h3>Current draft</h3>
        <p>
          <strong>Title.</strong>
          {{ props.beforeTitle }}
        </p>
        <pre>{{ props.beforeSummary }}</pre>
      </div>
      <div>
        <h3>AI candidate</h3>
        <p>
          <strong>Title.</strong>
          {{ props.afterTitle }}
        </p>
        <pre>{{ props.afterSummary }}</pre>
      </div>
    </div>
  </section>
</template>
