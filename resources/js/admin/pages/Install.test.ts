import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import Install from './Install.vue'

describe('protected setup', () => {
  it('shows only the private secret entry while locked', () => {
    const wrapper = mount(Install, {
      props: {
        unlocked: false,
        settings: null,
        version: 0,
        status: null,
        checks: [],
        saved: false,
        countries: [],
        currencies: [],
        timezones: [],
      },
    })
    expect(wrapper.find('input[type="password"]').exists()).toBe(true)
    expect(wrapper.find('input[name="name"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Unlock your store setup')
  })

  it('blocks saving while a required prerequisite fails', () => {
    const wrapper = mount(Install, {
      props: {
        unlocked: true,
        settings: null,
        version: 0,
        status: 'unconfigured',
        saved: false,
        countries: [],
        currencies: [],
        timezones: [],
        checks: [
          {
            id: 'private_storage',
            passed: false,
            required: true,
            message: 'Private storage needs attention.',
          },
        ],
      },
    })
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Private storage needs attention.')
  })
})

it('restores saved details and escapes merchant text on a fresh visit', () => {
  const wrapper = mount(Install, {
    props: {
      unlocked: true,
      version: 1,
      status: 'pending_owner',
      checks: [],
      saved: false,
      countries: [{ code: 'US', label: 'United States' }],
      currencies: [{ code: 'USD', label: 'US Dollar' }],
      timezones: ['UTC'],
      settings: {
        name: '<img src=x onerror=alert(1)>',
        currency: 'USD',
        locale: 'en',
        timezone: 'UTC',
        contact_email: 'help@example.test',
        sender_email: 'store@example.test',
        sender_name: 'Example Supply',
        sales_countries: ['US'],
        weight_unit: 'kg',
        length_unit: 'cm',
        policies: {
          privacy: 'Privacy',
          terms: 'Terms',
          returns: 'Returns',
          shipping: 'Shipping',
          digital: 'Digital',
        },
      },
    },
  })
  expect(wrapper.find<HTMLInputElement>('input[name="name"]').element.value).toContain('<img')
  expect(wrapper.find('img').exists()).toBe(false)
})

it('restores saved fields after re-unlocking an interrupted setup', async () => {
  const wrapper = mount(Install, {
    props: {
      unlocked: false,
      settings: null,
      version: 0,
      status: null,
      checks: [],
      saved: false,
      countries: [],
      currencies: [],
      timezones: [],
    },
  })
  await wrapper.setProps({
    unlocked: true,
    version: 1,
    settings: {
      name: 'Resumed store',
      currency: 'USD',
      locale: 'en',
      timezone: 'UTC',
      contact_email: 'help@example.test',
      sender_email: 'store@example.test',
      sender_name: 'Example Supply',
      sales_countries: ['US'],
      weight_unit: 'kg',
      length_unit: 'cm',
      policies: {
        privacy: 'Privacy',
        terms: 'Terms',
        returns: 'Returns',
        shipping: 'Shipping',
        digital: 'Digital',
      },
    },
  })
  expect(wrapper.find<HTMLInputElement>('input[name="name"]').element.value).toBe('Resumed store')
})

it('shows owner creation only after settings are saved', async () => {
  const wrapper = mount(Install, {
    props: {
      unlocked: true,
      settings: null,
      version: 0,
      status: 'unconfigured',
      checks: [],
      saved: false,
      countries: [],
      currencies: [],
      timezones: [],
    },
  })
  expect(wrapper.find('input[autocomplete="new-password"]').exists()).toBe(false)
  await wrapper.setProps({ status: 'pending_owner' })
  expect(wrapper.findAll('input[autocomplete="new-password"]')).toHaveLength(2)
  expect(wrapper.text()).toContain('Create owner account')
})
