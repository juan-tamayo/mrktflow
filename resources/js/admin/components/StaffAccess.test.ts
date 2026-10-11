import { mount } from '@vue/test-utils'
import { afterEach, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import StaffAccess from './StaffAccess.vue'

const account = {
  id: '00000000-0000-4000-8000-000000000001',
  name: 'Example Owner',
  email: 'owner@example.test',
  role: 'owner',
  is_active: true,
  version: 1,
}
afterEach(() => vi.restoreAllMocks())

it('keeps the submitted version after a conflicting account refresh', async () => {
  const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined)
  const wrapper = mount(StaffAccess, { props: { account } })
  await wrapper.setProps({ account: { ...account, version: 2, role: 'manager' } })
  await wrapper.find('form').trigger('submit')
  expect(patch).toHaveBeenCalledWith(
    `/admin/staff/${account.id}`,
    { access: { role: 'owner', is_active: true, version: 1 } },
    expect.any(Object),
  )
})

it('explains role powers and escapes account display text', () => {
  const wrapper = mount(StaffAccess, {
    props: { account: { ...account, name: '<img src=x onerror=alert(1)>' } },
  })
  expect(wrapper.find('img').exists()).toBe(false)
  expect(wrapper.text()).toContain('Full store control')
  expect(wrapper.findAll('select')).toHaveLength(2)
})
