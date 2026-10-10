# Official reference notes

Initial review October 5, 2026; Laravel release/queue and Stripe session-expiry constraints rechecked October 10, 2026. These sources substantiate framework/provider constraints. Product behavior, limits, architecture, and release targets elsewhere are MRKTFlow design proposals, not vendor promises. Recheck exact package compatibility when coding starts.

| Source | Fact used | Design consequence |
| --- | --- | --- |
| [Laravel 13 release notes](https://laravel.com/docs/13.x/releases) | Supported release and PHP range | Laravel 13 / PHP 8.4 baseline |
| [Laravel deployment](https://laravel.com/docs/13.x/deployment) | Webroot, runtime and deployment requirements | Serve only public directory; deployment preflight |
| [Laravel queues](https://laravel.com/docs/13.x/queues) | Queue drivers, transaction-aware dispatch and timeout below retry-after | Database queue initially; outbox, retry-safe consumers and explicit 60/90 and 300/360 timeout pairs |
| [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk) | Unified provider abstractions and structured output support | Candidate infrastructure inside the application AI adapter |
| [Vue quick start](https://vuejs.org/guide/quick-start.html) | Vue SFC, Vite and TypeScript tooling | Vue 3/TypeScript and compatible locked build toolchain |
| [Vue security](https://vuejs.org/guide/best-practices/security.html) | Untrusted content must not become Vue templates | Data-only merchant/AI authoring; reviewed compiled code path |
| [Inertia SSR](https://inertiajs.com/server-side-rendering) | SSR needs a separate rendering runtime | Inertia admin; Blade storefront avoids mandatory Node SSR |
| [MySQL locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html) | Transactional locking reads protect concurrent mutations | Reservation and refund concurrency tests on MySQL |
| [Stripe webhooks](https://docs.stripe.com/webhooks) | Verify signatures; handle duplicates and unordered deliveries | Durable inbox, unique provider event IDs, state reconciliation |
| [Stripe idempotent requests](https://docs.stripe.com/api/idempotent_requests) | Provider idempotency is bounded and request-sensitive | Durable local intent records; no unsafe blind retries |
| [WCAG 2.2](https://www.w3.org/TR/WCAG22/) | Accessibility criteria | Default admin/theme target AA, with manual keyboard/screen-reader checks |

The initial review could not retrieve the Stripe fulfillment guide. The October 10 recheck verified the [Checkout Session API expiry range](https://docs.stripe.com/api/checkout/sessions/create) of 30 minutes to 24 hours and Laravel 13 support for PHP 8.3–8.5; PHP 8.4 remains the project baseline. Cancellation, reconciliation and exact charge mapping still require the payment sandbox spike. Documentation review does not establish a tested integration.

## Marketing and SEO expansion references

Checked October 5, 2026. These support provider-specific design constraints; local validation cannot promise inbox placement, indexing or ranking.

| Source | Applied guidance |
| --- | --- |
| [Google ecommerce URL structure](https://developers.google.com/search/docs/specialty/ecommerce/designing-a-url-structure-for-ecommerce-sites) | Stable variant/pagination URLs, crawlable links, consistent canonicals/sitemaps and empty-page handling |
| [Google product structured data](https://developers.google.com/search/docs/appearance/structured-data/product) | Actual product/offer and variant data, truthful visible review information |
| [Google Search spam policies](https://developers.google.com/search/docs/essentials/spam-policies) | Generated hub quality/intent gates; avoid scaled low-value or doorway pages |
| [Gmail sender guidelines](https://support.google.com/mail/answer/81126) | Sender-authentication setup, one-click unsubscribe and provider delivery expectations |
