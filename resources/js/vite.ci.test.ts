/** @vitest-environment node */
import { spawnSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { resolveConfig } from 'vite'

const root = path.resolve(fileURLToPath(new URL('../..', import.meta.url)))

function restoreEnv(name: 'CI', previous: string | undefined): void {
  if (previous === undefined) {
    delete process.env[name]
    return
  }

  process.env[name] = previous
}

describe('Vite configuration in CI', () => {
  it('lets Vitest resolve the shared config while CI is set', async () => {
    const previousCi = process.env.CI
    process.env.CI = 'true'
    try {
      await expect(
        resolveConfig({ root, configFile: path.join(root, 'vite.config.ts') }, 'serve'),
      ).resolves.toEqual(expect.objectContaining({ command: 'serve' }))
    } finally {
      restoreEnv('CI', previousCi)
    }
  })

  it('still refuses a real Vite HMR server in CI', () => {
    const env: NodeJS.ProcessEnv = { ...process.env, CI: 'true' }
    delete env.VITEST
    const result = spawnSync(
      process.execPath,
      [
        '--input-type=module',
        '-e',
        'const { resolveConfig } = await import("vite"); await resolveConfig({ configFile: "./vite.config.ts" }, "serve");',
      ],
      { cwd: root, encoding: 'utf8', env },
    )
    expect(result.status).not.toBe(0)
    expect(`${result.stderr}${result.stdout}`).toContain(
      'You should not run the Vite HMR server in CI environments',
    )
  })
})
