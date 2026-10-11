let invitationToken = ''

// Run before Inertia initializes: it otherwise copies the URL fragment into page history.
export function captureInvitationToken() {
  if (window.location.pathname !== '/staff/invitation' || !window.location.hash) return
  const token = window.location.hash.slice(1)
  if (token.length === 64 && /^[a-f0-9]+$/.test(token)) invitationToken = token
  window.history.replaceState(null, '', '/staff/invitation')
}

export function takeInvitationToken() {
  const token = invitationToken
  invitationToken = ''
  return token
}
