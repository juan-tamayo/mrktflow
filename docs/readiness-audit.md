# Coding readiness audit

October 10, 2026 · Specification revision 0.3 · Documentation and read-only environment review.

## Verdict

**Ready to begin M0 foundation implementation.** Scope, module ownership, critical commerce rules, interface contracts, acceptance criteria and ordered tasks are specified. The local runtime still needs the setup already assigned to M0. No application scaffold exists, so this is planning readiness, not a working-build or release certification.

The confirmed first-release scope includes physical and digital products, mixed carts, shipping, watchlists, comparisons, trusted developer themes, safe template imports, optional built-in AI, abandoned-cart recovery, truthful urgency/social proof, product landing hubs, coupons, referrals and SEO. All remain required; the first checkout demo is an internal milestone.

## Findings corrected

| Finding | Correction and implementation owner |
| --- | --- |
| Quality commands assumed tooling that no task explicitly established | Added T003a for PHP tooling, T004a for isolated browser tests and T004b for CI/coverage setup. |
| Early seeders, identity work and spikes assumed tables from later milestones | Foundation uses synthetic fixtures; catalog/cart seeders land in T020/T025, historical guest-order claims in T043/T050. Provider checks retain explicit evidence gates. |
| Watchlist delivery preceded shared contact preferences | Moved T091 into M5 before subscription consumers; the module ownership remains platform. |
| Worker examples did not fully specify retry visibility | T006 now owns explicit 60/90-second and 300/360-second timeout/retry-after pairs, bounded calls and queue routing. |
| Distinct idempotency keys could imply duplicate checkout orders | A unique cart/revision intent binds clients to one order; replacement attempts require a conclusively non-payable earlier attempt. |
| Hosted checkout could drift from store totals | Freeze exact amounts, disable provider-side changes that bypass the quote, validate provider limits and test odd-cent mappings in T035. |
| Partial allocation/refund behavior and delayed payment events were ambiguous | Quantity counters, immediate refund holds and authoritative line eligibility prevent premature stock release, shipping refunded quantities and stale access/reward grants. |
| Download start-token expiry conflicted with large-file resume | Five-minute start token, explicit start POST and one-hour protected resumable session; recover lost responses without spending another allowance. |
| Verification and unsubscribe methods needed clearer boundaries | Scanner GETs never consume guest-access tokens or change preferences; signed one-click unsubscribe has a narrow CSRF exemption. |

The detailed contracts live in [commerce rules](commerce-rules.md), [interfaces](interfaces.md), [data model](data-model.md) and [the backlog](../tasks/todo.md).

## Local environment evidence

Read-only checks were performed on this workspace's host. Versions below describe the audit moment, not supported project substitutions.

| Item | Observed | Next step |
| --- | --- | --- |
| Project Git boundary | Git resolves to parent `/Users/juantamayo/Sites`, containing unrelated work | T001 establishes a dedicated `mrktflow` repository and preserves parent changes. |
| PHP | 8.3.31 on PATH | T002 selects project-scoped PHP 8.4 to match the chosen matrix; recheck extensions/platform requirements there. |
| Composer | 2.8.10 | Resolve and lock compatible packages in T002. |
| Node / npm | 22.21.1 / 11.17.0 | T003 selects and pins a compatible build toolchain. |
| MySQL | Client 9.6.0; no server binary on PATH or observed TCP listener on 3306 | A different endpoint/socket remains unverified. T002 provisions or verifies isolated MySQL 8.4 dev/test databases and server version. |
| Docker | Not on PATH | Optional provisioning choice; not evidence of a working container environment. |
| PHP coverage | No Xdebug/PCOV observed in the inspected PHP module list | T004b configures the chosen coverage runtime in CI. |
| Application | No scaffold, package manifests, lockfiles or application test suites | M0 creates these while preserving the planning documents. |

No runtimes were installed, database contents changed, repository initialized or application code created during this audit.

## Verification performed

- 108 unique requirements across 15 module specifications map to 123 unique, unchecked implementation tasks.
- Every task has dependencies, acceptance, verification, likely files and scope; all listed task dependencies precede their consumers in document order.
- The module dependency graph is acyclic. Task checkpoints cover every task, with at most three tasks per checkpoint; there are 42 checkpoints.
- Local Markdown document links, fenced examples and machine-readable JSON examples were checked. The traceability table was regenerated from the specifications and backlog.
- Official Laravel release/queue guidance and Stripe Checkout expiry limits were rechecked; see [sources](sources.md). Provider behavior has not been tested in a running integration.

These are documentation consistency checks. No application tests, builds, browser journeys, load tests or live provider tests could run because the application does not exist yet.

## Decisions and evidence due later

| Gate | Required before |
| --- | --- |
| Project runtime, isolated databases and compatible locked packages | Scaffold/database work in T002; guarded test execution in T004 |
| Payment provider test account and supported merchant market | T035 sandbox acceptance; missing credentials do not block independent catalog/theme work |
| Initial sales territory and validated physical/digital tax rules | Tax acceptance and any live sales; unconfigured destinations stay blocked |
| Selected AI provider/model and test credentials | T074 live-provider acceptance; fixture-based contract work may proceed earlier |
| Sender authentication, purpose grants, suppression and campaign policies | Marketing activation and release evidence |
| Exact open-source license, maintainer ownership and retention policy | Public release |

No remaining product clarification blocks starting the foundation with the recorded defaults. These later gates must not be silently marked complete. See [open decisions](open-decisions.md) for the full register.

## Starting point

Follow [the coding handoff](coding-handoff.md) and [M0 tasks](../tasks/todo.md). Establish the repository boundary first, then the project runtime/scaffold, test isolation and tooling. Preserve this specification and record real evidence as implementation tasks complete.
