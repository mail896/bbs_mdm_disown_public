# Release and Smoke-Check

This checklist describes the neutral public release workflow. Adapt paths and
URLs to the protected local deployment before using it in production.

## Scope

- Test changes in a development checkout first.
- Keep production changes small and already verified.
- Keep public releases sanitized: no credentials, private host names, private
  paths, project-state files or person-specific data.
- Do not publish local `PROJECT_STATE.*` files.

## Pre-Release Checklist

1. Check all working trees:

   ```bash
   git status --short
   ```

2. Run a code and database backup before production changes.

3. Run syntax and smoke checks in the development checkout:

   ```bash
   php -l admin.php
   php -l ade.php
   php -l audit_log.php
   php -l kuk.php
   php -l auth.php
   node --check assets/search.js
   php scripts/smoke_check.php --url=https://example.org/disown-dev
   ```

4. Test touched browser workflows, especially search, filters, mail preview,
   clarification cases, ADE, audit and KUK views.

## Production Release

1. Apply the tested changes to production.
2. Run syntax and smoke checks:

   ```bash
   php scripts/smoke_check.php --url=https://example.org/disown
   ```

3. Verify project-state files and internal directories are not web-accessible.
   Expected status for private resources is `403`.

4. Commit and push the private repository branches.

## Public Repository

1. Copy only sanitized code, docs, examples and assets.
2. Scan before committing:

   ```bash
   rg -n 'private-host.example|/private/path|BEGIN (RSA |OPENSSH |PRIVATE )?KEY|Bearer [A-Za-z0-9._-]+' . --glob '!images/**'
   ```

3. Run syntax checks.
4. Commit and push the public repository.
5. Verify GitHub Pages and public assets return `200`.

## Smoke Check

`scripts/smoke_check.php` is read-only. It does not write database rows, send
mail, call Jamf mutations or trigger ASM/ADE release.

It checks:

- required PHP files and frontend assets are readable;
- PHP version and required extensions;
- PHP syntax of core files;
- runtime config readability;
- database connectivity and expected tables;
- local NanoDEP release-broker health endpoint;
- optional HTTP status checks when `--url=...` is supplied.

Warnings are operational hints. A non-zero exit means at least one hard failure
was found and the release should stop.

## Rollback

If a production release behaves unexpectedly:

1. Stop making further workflow changes.
2. Restore the code archive and database dump from backup.
3. If Apple release behavior is involved, consider isolating the release broker.
4. Review affected requests in the audit log.
5. Document the incident in the private project state.
