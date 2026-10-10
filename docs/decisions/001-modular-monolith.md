# ADR-001: Single-store modular monolith

Status: Proposed · Date: 2026-10-05.

## Context

The platform must be installed and operated by individual merchants while covering integrated catalog, checkout, content and fulfillment.

## Decision

One Laravel application and database per independent store. Organize domain ownership through the capability map, explicit contracts and consumer-owned listeners. Keep deployment as one release with worker/scheduler companions.

## Alternatives

Multi-tenant SaaS adds tenancy/billing/control-plane obligations outside the requested product. Microservices add deployment and transaction recovery complexity. An undifferentiated monolith reduces initial structure but makes themes/AI likely to couple to commerce internals.

## Consequences

Local transactions remain useful and installation stays understandable. Multi-store control and distributed scaling are future projects. Contracts are application boundaries, not a plugin marketplace promise.
