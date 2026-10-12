# MRKTFlow visual identity

Independent commerce in motion. A folded path forms an abstract M; the two rising ribbons express building and growing a store on your own terms. The lowercase wordmark keeps the platform approachable. This is the implemented initial identity, October 10, 2026.

Open the [responsive preview](preview.html), or review the [vector identity board](identity-board.svg) and [interface direction](interface-direction.md) for logo applications and the planned catalog, order, marketing and storefront styling.

## Assets

- `public/brand/mark.svg`: pine symbol, intended for light backgrounds.
- `public/brand/mark-light.svg`: mint symbol for dark backgrounds.
- `public/brand/favicon.svg`: pine tile with mint symbol.
- `public/brand/wordmark.svg`: portable SVG lockup with live text; its font uses Arial/Helvetica fallbacks, so outlines would be needed for exact print reproduction.
- `resources/js/admin/components/BrandLogo.vue`: accessible application lockup; decorative mark plus visible name, one link label.
- [Concept board](concept-board.png): AI-generated art direction reference. Its mockup applications and small lettering are illustrative, not shipped features or authoritative logo geometry. The authored SVG assets are the implementation source.

Keep at least one quarter of the mark's width clear around standalone marks. Don't stretch, rotate, add gradients, or place pine on dark backgrounds. Use the mint variant there. Application header marks use a 36px square (30px on narrow screens); the favicon has its own inset tile.

## Tokens and typography

| Role | Color |
| --- | --- |
| Pine / action | `#153E35` |
| Mint / inverse action | `#D9F3DF` |
| Ink / primary text | `#182923` |
| Fog / canvas | `#F2F5F3` |
| White / surface | `#FFFFFF` |
| Muted text | `#53635B` |
| Border | `#D7DFDA` |

Semantic tokens live in `resources/css/app.css`. Use the existing system sans stack; no external font requests or additional runtime packages. Tight tracking belongs to the wordmark and large headings, not body copy. Body copy uses comfortable line spacing and normal tracking. Interactive controls use 6px corners, major workspace panels 12px, status badges a pill. Minimum action height is 44px. Maintain visible focus and WCAG AA contrast; statuses always include readable text.

## Product application

The workspace separates current storefront status from available team actions. It displays only implemented capabilities and permission-filtered staff actions; no sample revenue, fabricated urgency, or dead navigation. Storefront status is closed throughout the current foundation. The neutral form screens retain the existing validation, secret handling, session behavior, and error focus.

Branding at this stage describes the platform foundation. Later merchant theme work must make store identity configurable rather than permanently imposing the platform logo on every shop.

## Verification

Added workspace tests for truthful launch status, permission-filtered actions and escaped staff names. Existing merchant-name escaping test now allows only the known brand image and still rejects injected image/event markup. Browser checks cover logo delivery, favicon delivery, closed pages and protected setup/staff workflows; workspace overflow and Axe checks run at 320, 768, 1024 and 1440px. Final check results are recorded after execution, not inferred from this design document.

Final local verification: 128 PHP tests (708 assertions), 13 Vue tests, and 10 Chromium browser tests passed. Pint, PHPStan level 8, TypeScript, ESLint, Prettier, production build, and diff whitespace checks passed. The final SVG refinement was exercised in the final browser run; desktop and mobile workspace screenshots were inspected. No dependency, database, authorization, session, or CSP configuration changes were introduced.
