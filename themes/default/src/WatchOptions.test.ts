import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WatchOptions from './WatchOptions.vue'
import { parseWatchOptions } from './watchData'

const variantsJson = JSON.stringify([
  { id: '10000000-0000-4000-8000-000000000002', label: 'Pine' },
  { id: '10000000-0000-4000-8000-000000000003', label: 'Chalk' },
])

describe('parseWatchOptions', () => {
  it('parses a complete dataset and keeps a known selected variant', () => {
    const input = parseWatchOptions({
      endpoint: '/watch/subscriptions',
      csrf: 'csrf-token',
      email: 'member@example.test',
      variants: variantsJson,
      selectedId: '10000000-0000-4000-8000-000000000003',
    })
    expect(input).not.toBeNull()
    expect(input?.endpoint).toBe('/watch/subscriptions')
    expect(input?.csrfToken).toBe('csrf-token')
    expect(input?.email).toBe('member@example.test')
    expect(input?.selectedId).toBe('10000000-0000-4000-8000-000000000003')
    expect(input?.variants).toHaveLength(2)
  })

  it('drops an unknown selected variant and defaults the email to empty', () => {
    const input = parseWatchOptions({
      endpoint: '/watch/subscriptions',
      csrf: 'csrf-token',
      variants: variantsJson,
      selectedId: '99999999-9999-4999-8999-999999999999',
    })
    expect(input?.selectedId).toBeNull()
    expect(input?.email).toBe('')
  })

  it('rejects malformed datasets so the native form stays in place', () => {
    expect(parseWatchOptions({ csrf: 'x', variants: variantsJson })).toBeNull()
    expect(
      parseWatchOptions({ endpoint: 'https://evil.test/x', csrf: 'x', variants: variantsJson }),
    ).toBeNull()
    expect(
      parseWatchOptions({ endpoint: '/watch/subscriptions', variants: variantsJson }),
    ).toBeNull()
    expect(
      parseWatchOptions({ endpoint: '/watch/subscriptions', csrf: 'x', variants: 'not json' }),
    ).toBeNull()
    expect(
      parseWatchOptions({ endpoint: '/watch/subscriptions', csrf: 'x', variants: '[]' }),
    ).toBeNull()
    expect(
      parseWatchOptions({
        endpoint: '/watch/subscriptions',
        csrf: 'x',
        variants: JSON.stringify([{ id: '', label: 'Pine' }]),
      }),
    ).toBeNull()
  })
})

describe('WatchOptions', () => {
  it('renders the native-shaped form and gates submission until a variant, email and alert type exist', async () => {
    const wrapper = mount(WatchOptions, {
      props: {
        endpoint: '/watch/subscriptions',
        csrfToken: 'csrf-token',
        email: '',
        selectedId: '10000000-0000-4000-8000-000000000002',
        variants: [
          { id: '10000000-0000-4000-8000-000000000002', label: 'Pine' },
          { id: '10000000-0000-4000-8000-000000000003', label: 'Chalk' },
        ],
      },
    })
    const form = wrapper.find('form')
    expect(form.attributes('action')).toBe('/watch/subscriptions')
    expect(form.attributes('method')).toBe('post')
    expect(wrapper.find('input[name="_token"]').element).toHaveProperty('value', 'csrf-token')
    const select = wrapper.find('select[name="variant_id"]')
    expect((select.element as HTMLSelectElement).value).toBe('10000000-0000-4000-8000-000000000002')
    const boxes = wrapper.findAll('input[name="types[]"]')
    expect(boxes.map((box) => box.attributes('value'))).toEqual(['stock', 'price'])
    const button = wrapper.find('button[type="submit"]')
    expect(button.attributes('disabled')).toBeDefined()
    await wrapper.find('input[name="email"]').setValue('watcher@example.test')
    expect(button.attributes('disabled')).toBeDefined()
    await boxes[0]!.setValue(true)
    expect(button.attributes('disabled')).toBeUndefined()
    await boxes[0]!.setValue(false)
    await boxes[1]!.setValue(true)
    expect(button.attributes('disabled')).toBeUndefined()
  })

  it('preselects the only variant when the product has a single listed variant', () => {
    const wrapper = mount(WatchOptions, {
      props: {
        endpoint: '/watch/subscriptions',
        csrfToken: 'csrf-token',
        email: 'guest@example.test',
        selectedId: null,
        variants: [{ id: '10000000-0000-4000-8000-000000000002', label: 'Pine' }],
      },
    })
    expect((wrapper.find('select[name="variant_id"]').element as HTMLSelectElement).value).toBe(
      '10000000-0000-4000-8000-000000000002',
    )
    expect((wrapper.find('input[name="email"]').element as HTMLInputElement).value).toBe(
      'guest@example.test',
    )
  })
})
