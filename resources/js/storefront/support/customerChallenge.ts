/** Read a credential only into its form field; never into a URL, storage or rendered markup. */
export function captureCustomerChallenge(): void {
  const input = document.getElementById('challenge-token')
  if (!(input instanceof HTMLInputElement) || !location.hash) return
  const token = location.hash.slice(1)
  history.replaceState(history.state, '', location.pathname + location.search)
  if (/^[a-f0-9]{64}$/.test(token)) input.value = token
}
