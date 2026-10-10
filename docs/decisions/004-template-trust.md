# ADR-004: Two template trust levels

Status: Proposed · Date: 2026-10-05.

## Context

Merchants need AI/imported templates; developers need executable PHP/Vue customization. Treating both as arbitrary uploaded source would grant server/browser code execution to ordinary content editing.

## Decision

Admin/AI uses validated data-only section documents and typed style settings. Developer-installed themes may execute PHP/Blade and compiled JavaScript after normal code review/deployment. The application does not claim to sandbox trusted theme code.

## Alternatives

Arbitrary PHP/Vue uploads in admin violate the content-editor permission boundary. A general sandbox adds a substantial security/runtime product. A single rigid theme would fail the requested customization goal.

## Consequences

New executable blocks require developer installation before AI can compose them. Data imports remain portable and previewable. Product schema and visual templates are separately versioned.
