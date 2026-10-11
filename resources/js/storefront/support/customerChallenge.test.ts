import { describe, expect, it } from 'vitest'
import { captureCustomerChallenge } from './customerChallenge'

describe('customer credential fragments', () => {
  it('fills only the code field and immediately retires the fragment from history', () => {
    document.body.innerHTML = '<input id="challenge-token" type="password">'
    const token = 'a'.repeat(64)
    history.replaceState({}, '', `/account/reset-password#${token}`)
    captureCustomerChallenge()
    expect((document.getElementById('challenge-token') as HTMLInputElement).value).toBe(token)
    expect(location.hash).toBe('')
    expect(location.pathname).toBe('/account/reset-password')
  })

  it('discards invalid fragments without interpreting markup or storing a value', () => {
    document.body.innerHTML = '<input id="challenge-token" type="password">'
    history.replaceState({}, '', '/account/verify#<img%20src=x>')
    captureCustomerChallenge()
    expect((document.getElementById('challenge-token') as HTMLInputElement).value).toBe('')
    expect(location.hash).toBe('')
    expect(document.querySelector('img')).toBeNull()
  })

  it('preserves unrelated page fragments and does not overwrite manual input without a fragment', () => {
    document.body.innerHTML = '<main></main>'
    history.replaceState({}, '', '/#details')
    captureCustomerChallenge()
    expect(location.hash).toBe('#details')
    document.body.innerHTML = '<input id="challenge-token" value="manual-code">'
    history.replaceState({}, '', '/account/verify')
    captureCustomerChallenge()
    expect((document.getElementById('challenge-token') as HTMLInputElement).value).toBe(
      'manual-code',
    )
  })
})
