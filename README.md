# MRKTFlow

A free, open-source, self-hosted commerce platform built with Laravel and Vue. One installation runs one independent store selling physical and digital products, with native shipping, secure downloads, watchlists, comparisons, extensible themes and marketing/SEO tools.

**Project state: specification audited; ready to begin foundation implementation.** There is no Laravel scaffold, dependency installation, database, or running application yet. All implementation tasks remain unchecked.

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

There are **no runnable project commands yet**. [The coding handoff](docs/coding-handoff.md) defines bootstrap steps and the commands the first implementation milestone must provide. Do not run migrations or installation commands against an unrelated project.

## Workspace boundary

This folder was empty at specification time. Git currently resolves to the parent `/Users/juantamayo/Sites` repository, which contains unrelated existing changes. Keep work inside `mrktflow`; the first coding task must establish a dedicated project repository without staging, resetting, or committing parent-directory changes.
