import { createHmac } from 'node:crypto'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'

const TOTP_STEP_PATH = '.runtime/browser-owner-totp-step'

export function browserOwnerTotpSecret(): string {
  const fromEnv =
    process.env.BROWSER_OWNER_TOTP_SECRET ?? process.env.MRKTFLOW_BROWSER_TOTP_SECRET ?? ''
  if (fromEnv !== '') {
    return fromEnv
  }
  try {
    return readFileSync('.runtime/browser-owner-totp', 'utf8').trim()
  } catch {
    return ''
  }
}

function decodeBase32(secret: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  const bits = secret
    .toUpperCase()
    .replace(/[^A-Z2-7]/g, '')
    .split('')
    .map((character) => alphabet.indexOf(character).toString(2).padStart(5, '0'))
    .join('')
  const bytes: number[] = []
  for (let index = 0; index + 8 <= bits.length; index += 8) {
    bytes.push(Number.parseInt(bits.slice(index, index + 8), 2))
  }
  return Buffer.from(bytes)
}

function byte(buffer: Buffer, index: number): number {
  const value = buffer[index]
  if (value === undefined) {
    throw new Error('TOTP digest is truncated.')
  }
  return value
}

function lastIssuedStep(): number | null {
  try {
    const raw = Number.parseInt(readFileSync(TOTP_STEP_PATH, 'utf8').trim(), 10)
    return Number.isInteger(raw) ? raw : null
  } catch {
    return null
  }
}

function rememberStep(step: number): void {
  mkdirSync('.runtime', { recursive: true })
  writeFileSync(TOTP_STEP_PATH, String(step), { encoding: 'utf8', mode: 0o600 })
}

function waitUntil(epochMs: number): void {
  const ms = epochMs - Date.now()
  if (ms > 0) {
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms)
  }
}

export function totpCode(secret: string, timestamp = Math.floor(Date.now() / 1000)): string {
  let at = timestamp
  let counter = Math.floor(at / 30)
  const previous = lastIssuedStep()
  while (previous !== null && previous === counter) {
    waitUntil((counter + 1) * 30_000 + 200)
    at = Math.floor(Date.now() / 1000)
    counter = Math.floor(at / 30)
  }
  const buffer = Buffer.alloc(8)
  buffer.writeUInt32BE(Math.floor(counter / 0x100000000), 0)
  buffer.writeUInt32BE(counter >>> 0, 4)
  const hmac = createHmac('sha1', decodeBase32(secret)).update(buffer).digest()
  const offset = byte(hmac, hmac.length - 1) & 0x0f
  const binary =
    ((byte(hmac, offset) & 0x7f) << 24) |
    ((byte(hmac, offset + 1) & 0xff) << 16) |
    ((byte(hmac, offset + 2) & 0xff) << 8) |
    (byte(hmac, offset + 3) & 0xff)
  rememberStep(counter)
  return String(binary % 1_000_000).padStart(6, '0')
}
