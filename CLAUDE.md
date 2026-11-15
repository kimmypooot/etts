# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# ETTS

Laravel 13 + Vue 3 + Inertia 3 + Tailwind v4 + MySQL. See `README.md` for
setup and `docs/migration-plan.md` for the rebuild plan, findings and phases.
`docs/CUTOVER_RUNBOOK.md` covers the legacy sync, final import and cutover.
`docs/PROCESS_TRANSACTION_FLOW.md` records how the legacy issuance
processing page behaves, as the baseline for its rebuild.

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
- Status strings come from the enums in `app/Enums`. Never use raw status
  strings in code or SQL.

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
`composer test` does the same as `php artisan test` but clears cached config
first.

The Laravel Boost MCP server (`.mcp.json`, `php artisan boost:mcp`) gives
schema, query, log and docs-search tools for this app.

Artisan commands (`app/Console/Commands`), all about the legacy migration:
`etts:import-legacy`, `etts:import-legacy-transactions [--fresh]`,
`etts:sync-legacy [--apply]` (preview unless `--apply`; needs
`ETTS_LEGACY_SYNC=true`), `etts:verify-import`, `etts:verify-attachments`,
`etts:cutover-check`, `etts:eris-access-plan`.

## Architecture

- **Thin controllers, logic in `app/Services/*`**: `Transactions`
  (`TransactionRecorder`, `TransactionWorkflow`, `TransactionActions`,
  `TransactionArchiver`, `AttachmentStore`, `BoardFilters`, `ProcessSteps`,
  `DocumentLogistics`, `VcasLink`, `LookupLists`),
  `Transmittals`, `Spels` (duplicate
  check vs duplicate audit are deliberately separate classes), `Reports`,
  `Kiosk`, `Imis` (people directory), `Legacy` (import and sync).
  Authorization is a policy per model (`app/Policies`) plus route `->can()`.
- **Office scoping**: `App\Models\Scopes\OfficeScope` plus policies. `Fo\*`
  controllers are the field-office portal; the root controllers are the ESD
  (admin) side. Users who work for several offices pick one via `/office`.
  `ApplyActiveOffice` middleware then sets `$user->office_id` to the chosen
  office for the rest of the request. The choice is kept in the session and
  checked against the offices IMIS returned at sign-in. Scopes and policies
  just read `office_id`. Never save the chosen office to the account.
- **Row buttons**: `TransactionActions` is the one place that decides which
  buttons a board row shows, by portal (ESD or field office), type, status and
  payment. Both boards render its list (`RowActions.vue`, `ActionDialog.vue`);
  `TransactionWorkflow` only says what is legal (`canForward`, `canReceive`,
  `canMove`, `previousStatus`). A field office works on a record only while it
  is Submitted; a SPELS application is forwarded and received only once it has
  an OR number.
- **Issuance processing page**: `ProcessSteps` is the one place that sets
  the fields per step, the required fields, the current step and whether a
  record can still be edited. The controller, the form request and the page
  all use it; `App\Support\ProcessPage` builds the page props for its three
  modes (ESD `process`, field office `view` and `attachments`). A record in a
  final status is locked everywhere (`TransactionFormPresenter::isLocked`).
  `VcasLink` builds the `vcas://` launcher URL from the saved record, encoding
  each value on its own.
- **Pick lists**: `LookupLists` gives the forms (kiosk, ESD, FO, processing
  page) their eligibility and place of examination choices from the Settings
  tables (`eligibilities`, `exam_centers`) and the matching validation rules.
  SPELS offers only `AcquisitionCategory::SpecialGrant` eligibilities. A
  record's current value always stays a valid choice. Seed the lists with
  `php artisan db:seed`.
- **Enums** (`app/Enums`): `TransactionStatus`, `TransactionType`,
  `TransmittalStatus`, `TransmittalDirection` (`ESD_TO_FO`, `FO_TO_RO`),
  `DeletionReason`, `UserRole`, `AcquisitionCategory`, `PositionLevel`.
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
