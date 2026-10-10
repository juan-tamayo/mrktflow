# Architecture decision records

All records are **Proposed**, dated October 5, 2026. The user confirmed Laravel/Vue, self-hosting, physical/digital goods, open source and both AI/import workflows. The records below explain implementation choices; they are not a claim of approval or working code.

| Record | Decision |
| --- | --- |
| [001](001-modular-monolith.md) | Single-store modular monolith |
| [002](002-rendering-and-themes.md) | Blade storefront, Vue interactions, Inertia admin |
| [003](003-durable-commerce.md) | MySQL transactions and durable payment/event intents |
| [004](004-template-trust.md) | Separate data templates from trusted executable themes |
| [005](005-open-source-runtime.md) | Independent open-source runtime with optional services |
| [006](006-native-marketing.md) | Native marketing consumes commerce facts and reuses pricing/content |

When a decision changes, add a superseding record and update the affected specs. Preserve prior reasoning.
