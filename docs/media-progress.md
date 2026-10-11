# Media boundary implementation

T007 is in progress. October 10, 2026: private intake is implemented and locally verified; safe public derivatives are implemented; queued processing recovery and abandoned-file cleanup remain subsequent slices.

`StoreMedia::store(UploadedFile, kind, MediaUploadAuthorization)` accepts only `public_image` or `private_file`. The authorization object is server-owned: identity must authorize the current staff session and return its UUID; it must never be populated from submitted owner/permission fields. There is no production implementation or HTTP upload route until T008 policies exist. Tests use synthetic authorization as specified by T007.

Intake checks actual file bytes/MIME rather than browser labels, matching allowed filename extensions, uploaded-file validity, safe names and image dimensions/pixel count. Defaults: images at most 10 MiB / 6000 pixels per side / 16 million pixels; private files at most 500 MiB. The server-owned private MIME allowlist supports PDF, ZIP, plain text, MP3 and MP4; PHP, HTML and SVG are rejected. ZIP files are stored for download without extraction; template/archive extraction security belongs to its own import boundary.

All originals use a generated private key. Client filenames are encrypted; SHA-256, actual size/type, owner and dimensions remain private metadata. Images start pending with no public key. The internal processor rebuilds decoded pixels as PNG before recording a public derivative. Private files are ready after validation and explicitly labeled `not_scanned`; no malware scanning claim is made. Scanner enablement/distribution policy remains a release decision. Readiness here does not grant a purchased download; fulfillment still requires payment/entitlement authorization.

The intake action refuses an enclosing business transaction, records committed staging metadata before filesystem I/O, streams the write, then records readiness and minimal audit intent. A failure leaves a failed draft and fixed error code. A stopped process leaves an identifiable staging record; abandoned/failed-file cleanup and queued processing recovery are pending. No original is placed on the public disk, and no anonymous/admin media route is mounted.

Evidence: seven focused media tests / 39 assertions; full PHP suite 90 tests / 389 assertions, Pint and PHPStan level 8 passed. Tests cover private bytes/checksum/ownership/encrypted names, anonymous paths, denied authority, executable/spoofed/SVG/name rejection, actual byte/pixel/dimension limits, failed writes, transaction refusal, and an accidentally public private-storage root. The storage-root regression failed before the guard was added. No frontend behavior changed in this slice; prior frontend/build/browser checks remain current. Large-file streaming capacity on the reference host is later CAT-06/release evidence, not claimed by tiny fixtures.

References: [Laravel file streaming/storage](https://laravel.com/framework/docs/13.x/filesystem#automatic-streaming), [PHP image decoding](https://www.php.net/manual/en/function.imagecreatefromstring.php). Public image decoding/re-encoding will happen before publication.

## Safe image derivative slice

`ProcessMedia::process(assetUUID)` is an internal service, with no HTTP route. It locks pending image metadata, rechecks storage separation and source checksum/size/dimensions/type, decodes JPEG/PNG/WebP with GD, and writes a fresh PNG. Original metadata and appended bytes are not copied. Generated output is bounded to 32 MiB and named by its SHA-256 checksum. A generated temporary file on the public local disk is renamed into place before the ready/public metadata is committed. Both disks must be distinct, non-overlapping local directories; originals remain outside the web directory. Reprocessing a ready asset does nothing; private downloads are never processed as public images.

A failed processing attempt records a fixed error code and preserves a failed draft. Normal failure removes the temporary derivative. A process crash or database failure after the file rename can leave a safe generated orphan; the recovery/cleanup slice must reconcile it. This service is intentionally separate from the outbox database-only consumer contract because it performs local filesystem effects. No network call or client-provided path is used. Queued execution and recovery remain pending, so T007 is still incomplete.

Evidence: 14 media tests / 65 assertions, full PHP suite 97 tests / 415 assertions, Pint and PHPStan level 8 passed. Added cases include appended script stripping with readable output, checksum tampering, output-size failure, private-root recheck, overlapping disks, failed atomic publication cleanup and private-download isolation. No frontend or public route changed.

## Queued processing and interrupted publication

Images are queued automatically after intake commits, using an encrypted ID-only job on the separate `media` lane. The queue insert and its 90-second asset lease share a MySQL transaction. A failed enqueue keeps the validated original pending; `media:recover` scans bounded batches every minute and republishes expired leases. The `bin/worker media` wrapper uses timeout/reservation 60/90 seconds. A completed or failed draft is never automatically reopened. Processing clears its lease on success/failure.

The real worker fixture pauses after the generated file is atomically renamed but before metadata commits, receives SIGKILL, then proves that metadata remains pending with one safe generated orphan. Recovery expires only the isolated fixture's reservation/lease, starts another encrypted delivery and restarts the worker. The result is one ready asset, one derivative and no pending media jobs. The fixture uses guarded MySQL, private ignored directories and no external service.

Evidence: three queue tests / 18 assertions; full PHP suite 100 tests / 433 assertions. Pint and PHPStan passed. No HTTP upload route or staff bypass was added. Abandoned-file cleanup is the remaining T007 implementation slice.
