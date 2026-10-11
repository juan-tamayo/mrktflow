# ADR-002: Blade storefront, Vue interactions, Inertia admin

Status: Accepted for the rendering architecture · Date: 2026-10-10.

T010 implements a bounded prototype, not the complete theme registry or default storefront.

## Context

The user requests Laravel/Vue and developer templates using PHP/HTML/CSS/Vue. Store pages need public HTML and installations should have few required services.

## Decision

Render public pages in Blade; add compiled Vue components for interactive features. Use Vue/TypeScript with Inertia for the authenticated admin. Supply public DTOs to templates and preserve server ownership of commerce actions.

## Alternatives

Inertia SSR for every page is viable but requires a separate rendering runtime and makes PHP templates less central. A client-only SPA simplifies one rendering path but weakens the initial public HTML experience. A separate Nuxt frontend introduces another application/deployment.

## Consequences

Two presentation surfaces share data contracts and design tokens, not necessarily every component. No required production Node SSR process. Vue SFC development still requires a build step before distributing compiled themes.

## Rendering spike findings

The trusted default theme renders a complete example product title, plain-text description, image, selected price, variant options and specifications in Blade. The native GET form selects one supplied variant and returns updated HTML with JavaScript disabled. A separately compiled Vue island replaces that form after validating its bounded public payload; it updates supplied display text and has no cart, stock or payment authority. Blade and Vue share the public `ProductView` variant projection, not Eloquent objects. Unknown/private fields, unsafe image paths, oversized lists and amounts outside the JavaScript safe-integer range are rejected. All product text is escaped.

The preview route is `/admin/theme-preview`, requires current staff authentication plus `content.read`, and serves explicit example data. It is private/no-store/noindex; `/` remains closed. The renderer always uses a reviewed namespace and fixed product template. Request parameters cannot choose a PHP file. Developer Blade remains trusted PHP with application privileges: DTO arguments reduce accidental disclosure, but do not sandbox arbitrary trusted PHP. No executable theme upload or AI-template evaluation was introduced.

Vite builds theme entries alongside core entries into the application's normal `public/build` manifest. PHP serves the resulting CSS/ES modules as static assets. There is no Node SSR service, no on-demand compiler and no remotely fetched font/library. Node is needed to build and run frontend tests, not to serve the compiled storefront. The `theme.json` asset entries document this build boundary; the manifest is informational until T061 implements installed registry/compatibility/activation. Imported data cannot add build entries or executable code.

Money display uses integer division and remainder with an explicit exponent and ISO currency code, including zero- and three-decimal examples. The result is a deterministic code-prefixed amount rather than localized financial formatting. Future catalog/pricing projections must supply validated currency metadata and authoritative availability/actions; this spike does not price an order or assert that a supplied exponent matches a real selling configuration. The description is plain text. Sanitized rich-text rendering, media bindings, category breadcrumbs, complete route coverage, configurable merchant identity and theme activation/fallback remain the content/theme milestones.

Theme source now participates in TypeScript, ESLint, Prettier and unit-test discovery. It does not escape the existing quality gates by living outside `resources/js`. Browser checks exercise compiled Vue selection and native selection without JavaScript, enforce current permission rules, and check overflow/accessibility at four widths. The example has no purchase control or fabricated stock signal.

## Local evidence

Final verification passed 221 PHP tests (1,300 assertions), 19 frontend tests and 10 Chromium journeys, plus Pint, PHPStan level 8, TypeScript, ESLint, Prettier and production build. Eleven new PHP cases cover rendering, malicious/private fields, bounded money, escaping and staff/customer isolation; three Vue cases cover safe display and bounded payload parsing. Browser coverage verifies compiled selection, native selection without JavaScript, private cache/index headers, no public storefront opening, four accessibility/overflow widths and a minimum 44px variant-control height. Desktop/mobile screenshots were inspected after refining the control and shared border tokens.

The full PHP run also found an older production-host test leaking Symfony's static host patterns into following tests. Its cleanup now restores the previous patterns in finally; all existing production HTTPS/attacker-host assertions remain intact. No guard or production configuration was relaxed. No dependency or database migration was added, no sample catalog record was created, and no commerce readiness is asserted by this spike.
