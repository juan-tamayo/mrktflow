# ADR-002: Blade storefront, Vue interactions, Inertia admin

Status: Proposed · Date: 2026-10-05.

## Context

The user requests Laravel/Vue and developer templates using PHP/HTML/CSS/Vue. Store pages need public HTML and installations should have few required services.

## Decision

Render public pages in Blade; add compiled Vue components for interactive features. Use Vue/TypeScript with Inertia for the authenticated admin. Supply public DTOs to templates and preserve server ownership of commerce actions.

## Alternatives

Inertia SSR for every page is viable but requires a separate rendering runtime and makes PHP templates less central. A client-only SPA simplifies one rendering path but weakens the initial public HTML experience. A separate Nuxt frontend introduces another application/deployment.

## Consequences

Two presentation surfaces share data contracts and design tokens, not necessarily every component. No required production Node SSR process. Vue SFC development still requires a build step before distributing compiled themes.
