import { createHmac } from 'node:crypto'

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

export function totpCode(secret: string, timestamp = Math.floor(Date.now() / 1000)): string {
  const counter = Math.floor(timestamp / 30)
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
  return String(binary % 1_000_000).padStart(6, '0')
}
