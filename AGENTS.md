# MRKTFlow development

Read SPEC.md, CAPABILITY-MAP.md, the current task in tasks/todo.md, and docs/security-foundation.md before changing behavior. Follow the module boundaries and commerce invariants.

- This directory is an independent Git repository. Never stage or modify unrelated parent work. Use codex/ branches.
- Use bin/php and bin/composer for PHP 8.4 and bin/npm for the pinned Node/npm runtime. Do not replace global runtimes.
- Tests require isolated MySQL 8.4 databases and database-only accounts. Never bypass TestDatabaseGuard, use SQLite as a concurrency substitute, or point tests at a store database.
- Write a failing behavior/security test before implementing a change. Zero tests is a failure. Do not add suppressions, skip tests or reduce quality thresholds to make checks pass.
- Run PHP tests, Pint, PHPStan, frontend type/lint/unit/build checks and relevant browser tests before completing a task. Commands/evidence are in docs/development.md.
- npm install scripts stay disabled. Composer plugins require an allowlist entry and source review. Keep dependencies locked; never force audit fixes blindly.
- Never commit environment files, credentials, runtime databases, logs or browser traces. Treat imported templates and AI output as untrusted data.
- Secure first-owner setup and separate staff/customer authorization are required before exposing management. Server policies enforce access; UI visibility is insufficient.
- Update the backlog only with actual verification evidence. Record missing provider/remote CI evidence honestly.
