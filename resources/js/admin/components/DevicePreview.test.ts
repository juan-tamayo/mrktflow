import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DevicePreview from './DevicePreview.vue'

describe('DevicePreview', () => {
  it('shows an empty state until a preview exists', () => {
    const wrapper = mount(DevicePreview, { props: { html: null, loading: false } })
    expect(wrapper.text()).toContain('No preview yet')
    expect(wrapper.find('iframe').exists()).toBe(false)
  })

  it('renders the server html into the frame and switches device widths', async () => {
    const wrapper = mount(DevicePreview, {
      props: { html: '<section data-section-type="hero">Hi</section>', loading: false },
    })
    const frame = wrapper.get('iframe')
    expect(frame.attributes('srcdoc')).toContain('data-section-type="hero"')
    expect(frame.attributes('style')).toContain('width: 100%')
    expect(wrapper.get('[data-device="desktop"]').attributes('aria-pressed')).toBe('true')

    await wrapper.get('[data-device="mobile"]').trigger('click')
    expect(wrapper.get('iframe').attributes('style')).toContain('width: 390px')
    expect(wrapper.get('[data-device="mobile"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-device="desktop"]').attributes('aria-pressed')).toBe('false')
  })

  it('emits refresh and disables it while rendering', async () => {
    const wrapper = mount(DevicePreview, { props: { html: null, loading: true } })
    const refresh = wrapper.get('[data-action="refresh-preview"]')
    expect(refresh.attributes('disabled')).toBeDefined()
    expect(refresh.text()).toContain('Rendering…')
    await wrapper.setProps({ loading: false })
    await wrapper.get('[data-action="refresh-preview"]').trigger('click')
    expect(wrapper.emitted('refresh')).toHaveLength(1)
  })
})
