import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import SystemHealth from './SystemHealth.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

const health = {
  version: '1.0.0',
  generated_at: '2026-10-16T14:00:00+00:00',
  thresholds: {
    scheduler_stale_seconds: 180,
    worker_lag_seconds: 300,
    unresolved_payment_seconds: 600,
    backup_max_age_seconds: 86400,
    disk_warn_percent: 90,
  },
  scheduler: { current: false, lag_seconds: 240, warning: true },
  worker: { queue_pending: 1, outbox_pending: 0, oldest_due_seconds: 480, warning: true },
  payments: { unknown_count: 1, oldest_unknown_seconds: 900, warning: true },
  jobs: {
    failed: 1,
    errors: [
      {
        uuid: '11111111-1111-4111-8111-111111111111',
        queue: 'payments',
        error_code: 'RuntimeException',
        failed_at: '2026-10-16 14:00:00',
      },
    ],
    warning: true,
  },
  email: { ready: true, failed_deliveries: 0, warning: false },
  disk: {
    path: '/srv/mrktflow/storage',
    free_bytes: 5,
    total_bytes: 100,
    used_percent: 95,
    warning: true,
  },
  storage: { private_writable: true, public_writable: true, warning: false },
  backup: { observed_at: '2026-10-15 12:00:00', age_seconds: 90000, warning: true },
  warnings: [
    { code: 'scheduler_stale', message: 'Restart schedule:run.' },
    { code: 'worker_lag', message: 'Restart database workers.' },
    { code: 'unknown_payments', message: 'Run payments:reconcile.' },
    { code: 'failed_jobs', message: 'Redacted error codes only.' },
    { code: 'backup_stale', message: 'Record operations:backup-observed.' },
    { code: 'disk_full', message: 'Disk usage is 95%.' },
  ],
}

it('shows lag, unknown payments, disk, backups, and redacted job errors', () => {
  const wrapper = mount(SystemHealth, { props: { health } })
  expect(wrapper.get('h1').text()).toContain('System health')
  expect(wrapper.get('[role="note"]').text()).toContain('1.0.0')
  expect(wrapper.get('[aria-label="Operational warnings"]').text()).toContain('scheduler_stale')
  expect(wrapper.text()).toContain('Scheduler stale')
  expect(wrapper.text()).toContain('oldest due 480s')
  expect(wrapper.text()).toContain('1 unknown attempt')
  expect(wrapper.text()).toContain('RuntimeException')
  expect(wrapper.text()).not.toContain('sk_live')
  expect(wrapper.text()).toContain('Disk used 95%')
  expect(wrapper.text()).toContain('Backup age 90000s')
  expect(wrapper.get('form').attributes('action')).toBe('/admin/health/support-export')
  expect(wrapper.find('a[href="/admin/audit"]').exists()).toBe(true)
})
