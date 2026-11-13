# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# ETTS

Laravel 13 + Vue 3 + Inertia 3 + Tailwind v4 + MySQL. See `README.md` for
setup and `docs/migration-plan.md` for the rebuild plan, findings and phases.
`docs/CUTOVER_RUNBOOK.md` covers the legacy sync, final import and cutover.

ESD Transactions Tracking System for CSC Regional Office VIII: certifications,
authentications and SPELS applications, transmittals between ESD and field
offices, and a walk-in kiosk.

## Rules

- JavaScript only, no TypeScript. Business logic in the backend; Vue pages
  render props.
- A separate app under the IMIS hub (`../new-imis`). Staff sign in only via
  IMIS SSO (`Auth\ImisSsoController`, same contract as `../jportal`); roles
  come from IMIS `application_roles.code` (`App\Enums\UserRole`: `admin`,
  `field_office`). Never add a password sign-in for staff.
- The legacy app (`../etts`) is reference only. Never edit it. Its database
  is read through the `legacy` connection by import commands only.
- Office scoping is enforced in SQL (query scope plus policy), never only in
  the frontend. Field office users see only records of their own office.
- Applicant attachments live on the private `attachments` disk and are served
  only through an authorised, audited controller. Never put them under
  `public/`.
- Status strings come from one enum (Phase 2). Never use raw status strings
  in code or SQL.

## Commands

```bash
composer setup                 # install, .env, key, migrate, npm install + build
composer dev                   # dev stack (php artisan dev)
npm run dev | npm run build    # Vite
php artisan test --compact     # all tests (PHPUnit, in-memory SQLite)
php artisan test --compact --filter=SomeTest          # one class or method
php artisan test --compact tests/Feature/Transactions # one directory
vendor/bin/pint                # code style (Laravel Pint)
```

Tests run on SQLite `:memory:` (see `phpunit.xml`), so SQL that goes through
`App\Support\Sql` must stay portable between MySQL and SQLite.

Artisan commands (`app/Console/Commands`), all about the legacy migration:
`etts:import-legacy`, `etts:import-legacy-transactions [--fresh]`,
`etts:sync-legacy [--apply]` (preview unless `--apply`; needs
`ETTS_LEGACY_SYNC=true`), `etts:verify-import`, `etts:verify-attachments`,
`etts:cutover-check`, `etts:eris-access-plan`.

## Architecture

- **Thin controllers, logic in `app/Services/*`**: `Transactions`
  (`TransactionRecorder`, `TransactionWorkflow`, `TransactionArchiver`,
  `AttachmentStore`, `BoardFilters`), `Transmittals`, `Spels` (duplicate
  check vs duplicate audit are deliberately separate classes), `Reports`,
  `Kiosk`, `Imis` (people directory), `Legacy` (import and sync).
  Authorization is a policy per model (`app/Policies`) plus route `->can()`.
- **Office scoping**: `App\Models\Scopes\OfficeScope` plus policies. `Fo\*`
  controllers are the field-office portal; the root controllers are the ESD
  (admin) side. Users who work for several offices pick one via `/office`.
- **Enums** (`app/Enums`): `TransactionStatus`, `TransactionType`,
  `TransmittalStatus`, `TransmittalDirection` (`ESD_TO_FO`, `FO_TO_RO`),
  `DeletionReason`, `UserRole`.
- **Kiosk is a separate public surface**: `routes/kiosk.php` with its own
  `kiosk` middleware group, session cookie, CSRF and policy. `bootstrap/app.php`
  serves it on `ETTS_KIOSK_DOMAINS` hosts when set, otherwise under `/kiosk`
  on the main host. Terminals are paired by code (`KioskPairing`).
- **Audit**: `AuditLog::record()` on every mutation, and on staff reads of
  applicant data (attachments included).
- **Legacy data**: keeps legacy column names and ids so imports are straight
  copies. Records have no `created_at`; the legacy `timestamp` becomes
  `updated_at` and `transaction_date` is the business date. Imports are
  idempotent and report skipped rows; they must never write to legacy.
- **IMIS integration**: SSO in (`/sso`, `/sso/logout`), and
  `/api/imis/summary` is the Executive Dashboard feed (bearer key
  `IMIS_FEED_KEY`, server to server).
- **Config**: `config/etts.php` holds prices, attachment limits, kiosk,
  legacy sync, ERIS links, routing slip text and IMIS office-code mapping.
- **Frontend**: `resources/js` (`Pages`, `Components` grouped by area,
  `Layouts`, `Composables`, `Utils`); design tokens in `resources/css/app.css`
  under `@theme`.
