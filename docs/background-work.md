# Background work and diagnostics

MRKTFlow uses MySQL-backed queues and a minute scheduler. This foundation has no public operations endpoint: `/up` reports process liveness only. Owner-facing diagnostics will follow staff authorization. Run commands as the installation's restricted application user with its private environment file and preserved application key. Apply migrations before starting workers/scheduling.

## Workers

Supervise these processes independently from the project directory:

| Command | Queues | Timeout / reservation |
| --- | --- | --- |
| `bin/worker outbox` | `outbox` | 60 / 90 seconds |
| `bin/worker media` | `media` | 60 / 90 seconds |
| `bin/worker critical` | `payments`, `transactional-mail`, `default` | 60 / 90 seconds |
| `bin/worker marketing` | `marketing`, `hubs` | 60 / 90 seconds |
| `bin/worker long-work` | `long-work` | 300 / 360 seconds |

Each wrapper exits after at most an hour; the process supervisor must restart it. The timeout must remain shorter than the reservation. Outbox jobs make one queue attempt per delivery, while durable receipts own six application attempts with five retry delays of 10, 30, 120, 300 and 900 seconds. A killed worker leaves recoverable pending intent. A stale queue reservation can become a framework failed job while recovery publishes another ID-only delivery; completed receipts prevent duplicate local effects.

A minimal Supervisor program (adjust installation path and user) is:

```ini
[program:mrktflow-outbox]
directory=/srv/mrktflow
command=/srv/mrktflow/bin/worker outbox
user=mrktflow
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/log/mrktflow/outbox-worker.log
```

Create separate programs for media, critical, marketing and long-work queues; use a stop grace above 300 seconds for long work. Configure restricted log permissions/rotation and persistent private storage. Do not let bulk marketing occupy payment/mail workers. Later adapters must select their documented lane and persist delivery intent before external I/O.

## Scheduler and health

Install one cron entry under the same application user (example path):

```cron
* * * * * cd /srv/mrktflow && /srv/mrktflow/bin/php artisan schedule:run >> /var/log/mrktflow/scheduler.log 2>&1
```

The scheduler records its heartbeat and recovers up to 100 due outbox receipts and 100 pending images per minute. `outbox:recover` has a shared cache overlap lock; use the database cache on the reference deployment. No provider call runs in the local event consumer transaction.

`bin/php artisan platform:health` prints heartbeat freshness, pending/failed receipt counts, queue/failed-job counts and the oldest due receipt age. It exits 1 for a missing/older-than-180-second heartbeat, any quarantined/failed job, an unpurged failed media draft, or due outbox/media work older than 300 seconds. Freshness and queue age are observations, not proof of a running worker or a verified email sender. Monitor the command and alert operators on a failing exit status. `/up` alone cannot confirm readiness.

## Recovery

1. Read `platform:health` and the redacted `outbox.consumer_failed` / `outbox.publication_failed` diagnostics. Request responses carry a server-generated `X-Request-ID`; durable intent retains that correlation ID.
2. Repair the underlying configuration/consumer failure. Preserve encrypted intent and the application key; never edit payloads or completed receipts.
3. Run `bin/php artisan outbox:failed` for up to 50 quarantined event/consumer identifiers without payloads. After repair, run `bin/php artisan outbox:retry EVENT_UUID CONSUMER_ID`. Only failed receipts can be reset; completed/pending work stays protected. Periodic recovery handles a failed publication even if the explicit command cannot reach the queue.
4. Run `bin/php artisan outbox:recover`, supervise/restart the workers, and confirm pending age falls and health returns to current. Review framework failed jobs with restricted CLI access before forgetting obsolete reservations. Do not blindly replay all failed jobs or delete receipt history.

Framework failed-job records and logs are private operational data. All queued work carrying business data must implement `ShouldBeEncrypted`, keep payloads bounded, and avoid credentials/personal data in exception messages. The outbox delivery contains event/consumer IDs only and is encrypted. General application error logs record exception class/source location/correlation only, omitting exception text and trace arguments.

## Adapter contract

Record local event intent inside the same transaction as the business state. Bind consumers in server-owned `config/outbox.php`; never accept a consumer class from HTTP/template/AI input. Consumer effects and completion receipts share a transaction. Consumers may persist another durable intent, but may not perform external network calls inside that transaction.

Use `OutboundHttp::client()` for provider calls: 5-second connection, 20-second total timeout, TLS verification, redirects disabled, and no implicit mutation retries. Adapters must validate provider endpoints and apply provider-specific idempotency/reconciliation before retrying an external mutation. Signed Stripe webhooks persist an encrypted inbox row before 2xx and enqueue ID-only `ProcessPaymentEvent` work on the `payments` lane. Ordinary SMTP cannot guarantee exactly-once delivery. [Laravel timeout behavior](https://laravel.com/framework/docs/13.x/http-client#timeout).

## Local verification

`bin/php artisan test --filter=PlatformOutbox` includes real MySQL recording/replay/failure tests plus a subprocess interruption rehearsal. The test consumer pauses after an insert in an open transaction; the test sends SIGKILL, verifies rollback, advances only its isolated reservation/lease past expiry, and restarts processing. No fixture consumer is registered in production, no external provider is invoked, and no merchant database is used. The default test queue remains synchronous; only the explicit guarded outbox connection uses a worker.

For a manual scheduler rehearsal, apply migrations to the isolated test database, then run `APP_ENV=testing bin/php artisan schedule:run --env=testing` followed by `bin/php artisan platform:health --env=testing`. Do not use a merchant database for a rehearsal. Hosted CI, deployment supervision, provider delivery and production capacity remain separate release evidence.

## Media maintenance

Use the separate media worker and minute `media:recover` task. `media:clean` inspects old records and files in bounded batches after a 24-hour grace period. It retains ready originals/current derivatives and pending originals, removes known generated orphans/temporary files, and purges abandoned staging/failed drafts while retaining metadata/audit history. Cleanup failure is reported with a fixed error code and a failing command. A purged draft needs a new upload. See [media evidence](media-progress.md). Keep the minute scheduler and shared database cache enabled; monitor media failure counts and oldest pending age alongside outbox health.
