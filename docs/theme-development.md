# Developer theme format

Contract version: 1.0. This is the trusted executable-theme workflow (THEME-01, THEME-04). It is not a sandbox.

## Trust levels

| Package | Who installs it | What it may contain | Trust |
| --- | --- | --- | --- |
| Merchant data package | Staff at `/admin/content/import` | JSON section documents and JPEG/PNG/WebP under `media/` | Untrusted data. PHP, Blade, Vue, scripts and remote executable entries are refused. |
| Developer code theme | A reviewer copies the theme under `themes/` and runs `bin/php artisan theme:validate` | PHP/Blade, HTML, CSS, Vue source, and Vite-compiled assets | **trusted-developer-code**. PHP and compiled JavaScript run with application and browser privileges. **sandbox: none**. **admin_upload: refused**. |

The application does not sandbox trusted theme PHP. Treat a custom theme like application code: review it, keep it in the repository or a reviewed release, then validate it. A checksum in `installed_themes` proves the files match the last registration; it does not prove the author is safe.

Admin import and this CLI are separate on purpose. Uploading `theme.json`, `.blade.php` or `.vue` through the merchant importer is rejected and writes no `installed_themes` row.

## Layout of a theme

Place one directory under `themes/`:

```
themes/example/
  theme.json
  views/product.blade.php
  src/theme.css
  src/product.ts
  src/SpecSheet.vue
```

`themes/example` is the reviewed custom product technical-sheet template shipped with this slice. It does not replace the default theme and it is not coverage-complete, so validation registers it as `valid` without activating it.

Manifest fields are closed. Unknown keys fail registration. Paths are theme-relative (`views/product.blade.php`) or root-relative naming this theme (`themes/example/src/product.ts`). Traversal, absolute paths, schemes, remote entries, symlink escapes and missing files fail.

```json
{
  "schemaVersion": "1.0",
  "id": "mrktflow/example",
  "version": "1.0.0",
  "name": "Example technical sheet",
  "requires": { "core": ">=1.0.0 <2.0.0", "themeApi": "1" },
  "templates": { "product.default": "views/product.blade.php" },
  "sections": ["hero", "rich-text"],
  "assets": {
    "entry": "themes/example/src/product.ts",
    "style": "themes/example/src/theme.css"
  }
}
```

Templates receive public view objects (`ProductView` and the other contracts in [template contracts](template-contracts.md)), never Eloquent models or payment services. Escape all merchant text. Platform slots (`storefront.slots.*`) stay platform-owned; a file in the theme directory cannot shadow them. Vue islands may enhance display after validating a bounded JSON payload. They have no cart, stock or checkout authority.

## Validate and install

In a clean development store, after migrations:

```sh
bin/php artisan theme:validate themes/example
bin/npm run build
```

The command registers the directory through `ThemeRegistry`. It prints `trust: trusted-developer-code`, `sandbox: none` and `admin_upload: refused`. A valid registration is recorded in `installed_themes`. The command does not call `ActivateTheme`, does not rewrite catalog or order rows, and does not open `GET /`.

`activatable: yes` means the registration is valid **and** `ThemeCoverage` has a template for every required storefront surface. The example theme only ships a custom product sheet, so it stays `activatable: no` until a later theme supplies the remaining routes. Activation remains the staff `content.publish` flow from T068.

Vite reads `assets` from every `themes/*/theme.json` at build time. Adding a reviewed theme does not require editing catalog, pricing or checkout PHP. Default-theme islands that are not listed in that theme's manifest stay in `vite.config.ts` as default-theme source, not commerce core.

## Review checklist

- Manifest `id` is `vendor/name`, `requires.core` / `themeApi` match the platform, and every declared file exists inside the theme directory.
- Blade extends the storefront layout slot contract or a reviewed replacement; it does not invent prices, stock or ratings.
- CSS and fonts are same-origin. Remote stylesheets are blocked by CSP.
- Vue parses a closed public payload and leaves the native HTML working with JavaScript disabled.
- `bin/php artisan test --filter=ThemesContentDeveloper` and `bin/npm run build` succeed after the theme is on disk.
