# MRKTFlow

A free, open-source, self-hosted commerce platform built with Laravel and Vue. One installation runs one independent store selling physical and digital products, with native shipping, secure downloads, watchlists, comparisons, extensible themes and marketing/SEO tools.

**Project state: secure foundation implemented and locally verified.** Laravel/Vue entrypoints, isolated MySQL test environments, security headers and automated checks are runnable. The unopened storefront and administration return HTTP 503. Installer, identities, commerce, themes and marketing remain in the ordered backlog; this is not a production-ready store.

## Start here

1. Read [SPEC.md](SPEC.md) for product scope, defaults, and release criteria.
2. Read [CAPABILITY-MAP.md](CAPABILITY-MAP.md) for module ownership and dependencies.
3. Read [architecture](docs/architecture.md), [data model](docs/data-model.md), and [template contracts](docs/template-contracts.md).
4. Follow [the implementation plan](tasks/plan.md) and [ordered backlog](tasks/todo.md).
5. Read [the readiness audit](docs/readiness-audit.md) for evidence and remaining setup, then use [the coding handoff](docs/coding-handoff.md) when implementation is requested.

## Confirmed direction

- Laravel + Vue; commerce independent of WordPress.
- Install on infrastructure controlled by the store owner; no mandatory platform subscription.
- Free/open-source distribution. Exact license is a release decision; MIT is the proposed default.
- Physical products, digital products, and mixed carts in the first public release.
- PHP/Blade, HTML, CSS, and Vue developer themes; reusable product data templates and visual templates.
- Built-in AI-assisted authoring plus imports of externally authored templates.
- Merchants preview and explicitly publish changes; the store works without an AI account.
- Native recovery campaigns, urgency/social proof, verified reviews, automatic product hubs, coupon campaigns and referral rewards are first-release features.

## Specification index

| Document | Purpose |
| --- | --- |
| [Product specification](SPEC.md) | Users, scope, business rules, definition of release |
| [Capability map](CAPABILITY-MAP.md) | Index of the fifteen module specifications |
| [Architecture](docs/architecture.md) | Stack, application structure, runtime, coding conventions |
| [Data model](docs/data-model.md) | Entities, ownership, constraints, historical records |
| [Commerce rules](docs/commerce-rules.md) | Money, inventory, payments, refunds, digital entitlements |
| [Native marketing and SEO](docs/marketing-rules.md) | Recovery, coupons/referrals, proof/reviews, landing hubs and attribution |
| [Interface contracts](docs/interfaces.md) | Routes, response shapes, service boundaries, retries |
| [Template contracts](docs/template-contracts.md) | Themes, sections, content, product schemas, AI output |
| [Experience map](docs/experience-map.md) | Admin and customer screens, journeys, state requirements |
| [Quality and operations](docs/quality-and-operations.md) | Security, tests, performance, installation, recovery |
| [Decision records](docs/decisions/README.md) | Reasons behind the architecture choices |
| [Readiness audit](docs/readiness-audit.md) | Audit findings, local environment evidence and phase gates |
| [Open decisions](docs/open-decisions.md) | Assumptions, defaults, and deadlines for remaining choices |
| [Requirements traceability](docs/traceability.md) | All module requirements mapped to the 123-task backlog |
| [Research sources](docs/sources.md) | Official references, with an October 10 readiness recheck |

## Commands

See [development](docs/development.md) for installation, isolated database provisioning, running the application and all quality checks. See [continuous integration](docs/continuous-integration.md) for the workflow and pending hosted-run evidence. Use `bin/php`, `bin/composer` and `bin/npm` to keep runtime selection consistent.

## Workspace boundary

This folder now has an independent Git repository. Implementation is on `codex/foundation`; unrelated parent-directory changes were not staged or modified. Environment files, local database data, dependencies, build artifacts and browser traces are ignored. No remote repository is configured.
