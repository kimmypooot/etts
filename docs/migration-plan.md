# ETTS v2 migration plan

ETTS (Examination Transaction and Tracking System) is being rebuilt from the
legacy framework-less PHP app at `../etts` (reference only, never edit it) as
a Laravel + Vue 3 + Inertia + Tailwind + MySQL app. It is a **separate app
under the IMIS hub** (`../new-imis`), like JPortal, PROCTAD and `../new-eris`.
Staff sign in only through IMIS SSO. IMIS lists ETTS under the code name
`eris`; `../new-eris` is a different rebuild (ENLOOKUP, ONSA, COPI, COMEX) and
is not extended here.

This plan comes from reading the legacy code (`../etts`, `CLAUDE.md`) and the
production dump `../etts/etts_prod-db.sql` (taken 2026-11-04). Items marked
**(ask)** need an answer before that phase starts.

## What the legacy app contains

| Area | Where | Main tables | Notes |
| --- | --- | --- | --- |
| ESD transactions (list, process, batch status, edit, delete) | `views/esd/{all_transactions,process_transaction,dashboard,etts_dashboard}.php`, `handlers/transaction_handler.php` (1.7k lines), `revert_status.php` | `esd_casts_records`, `esd_casts_issuance_requests` | Status flow `submitted → forwarded → received → terminal`; two families (certs vs SPELS). |
| Field Office portal | `views/fo/*`, `handlers/fo_*_handler.php` | same, scoped by `office_id` | FO users see only their own office. |
| Transmittals | `views/{esd,fo}/transmittal.php`, `handlers/{transmittal,fo_transmittal}_handler.php`, `transmittal_shared.php` | `esd_casts_transmittals` (`direction` ESD_TO_FO / FO_TO_RO), `esd_casts_transmittal_items` | Includes manual (non-record) items and printable routing slips. |
| Deletion / archive | `views/esd/deleted_records.php`, `views/fo/deleted_records.php`, `archive_handler.php`, `deletion_reasons.php` | `esd_casts_records_deleted`, `esd_casts_issuance_requests_deleted` | Fixed reason vocabulary. |
| Activity logs and timeline | `views/esd/activity.php`, `log_handler.php` | `esd_etts_activity_logs` (~25k rows), `esd_casts_activity_logs` (~8.6k, older SETS-era) | |
| Dashboards, statistics, reports | `dashboard_handler.php`, `fo_dashboard_handler.php`, `report_handler.php`, `views/esd/stat_*.php` | read-only over records | Large JS: `dashboard.js` 4.9k, `fo_dashboard.js` 2.2k, `statistics.js` 1.1k lines. |
| Eligibility management | `eligibility_handler.php`, `views/esd/eligibilities.php` | `esd_casts_eligibility_tbl` (11 rows) | Admin only. Smallest module, used as the pilot. |
| SPELS duplicate check and audit | `core/SpelsDuplicateCheck.php`, `core/SpelsDuplicateAudit.php`, `duplicate_audit_handler.php` | records | Preventive gate (kiosk) vs detective sweep (admin); must stay separate classes. |
| ESD walk-in kiosk | `views/kiosk/*`, `handlers/client_handler.php` | records, issuance | Subdomain `kiosk.cscro8.com`. No login, public endpoint, own session/CSRF, tight CSP. |
| PACD kiosk | `views/pacd_kiosk/*` | records | Same pattern, hardcoded cookie params. **(ask)** whether still in use. |
| Dead / ignore | `core/Auth.php`, `handlers/auth_handler.php`, `views/auth/*`, `*copy.php`, `*.old.php`, `dashboard2.php`, `views/client/`, `main/` | | Not ported. |

## Production data (from `etts_prod-db.sql`)

| Table | Rows (approx.) | Notes |
| --- | --- | --- |
| `esd_casts_records` | 8,479 | Core. `latin1_swedish_ci`, `int(11)` ids, no FK to office. `timestamp` column is the only audit time. |
| `esd_casts_issuance_requests` | 3,536 | Child of records (`sets_records_id`), enums for sex, civil status, employment status. |
| `esd_casts_transmittals` | 133 | `direction` enum already present (migration 010 confirmed live). |
| `esd_casts_transmittal_items` | 1,233 | Only table with real FKs (`transmittal_id`, `record_id`, cascade). |
| `esd_casts_records_deleted` | 146 | Archive (migration 009 confirmed live). |
| `esd_casts_record_old` | 98 | Unreferenced older copy. **(ask)** drop or import. |
| `esd_casts_returned_documents` | 21 | |
| `esd_casts_office` | 10 | `office_id`, `office_name`, `office_code`. |
| `esd_casts_users` | 65 | `u_id`, `users_cscro8_id`, `office_id`. User-to-office map only; accounts live in IMIS. |
| `esd_casts_eligibility_tbl` | 11 | |
| `esd_etts_activity_logs` | ~25,255 | Current audit trail. |
| `esd_casts_activity_logs` | ~8,598 | Old SETS-era log keyed by `client`. **(ask)** import or leave archived. |

Observations that shape the rebuild:

- **No `created_at`/`updated_at`** on records; `timestamp` has `ON UPDATE CURRENT_TIMESTAMP`. Keep it as `updated_at`; do not invent a `created_at` (use `transaction_date`).
- **Mixed charsets** (latin1 vs utf8mb4) and **dirty identity columns** (NULL vs `''`, untrimmed names). The duplicate rule depends on this; normalise in the import, keep the old comparison until the import is verified.
- **Status strings** are free `varchar`. Port as a PHP backed enum (`TransactionStatus`); the import report must list any value outside the enum.
- **Migrations 008, 009, 010** are live in production but have no files in `../etts/db/migrations`. The new app writes its own schema, so this only matters for the import mapping.
- `users_cscro8` and `system_access` are **not** in this dump. They belong to IMIS and are replaced by SSO.

## Target design

- Same conventions as IMIS, JPortal and ERIS: JavaScript only (no TypeScript), thin controllers, business logic in the backend, Vue pages render props, design tokens in `resources/css/app.css` under `@theme`.
- **Scaffold:** copy `../jportal`'s shell (layout, components, SSO controller, audit log, tests) rather than `new-imis`.
- **Authentication:** IMIS SSO only (`Auth\ImisSsoController`, `POST /api/v1/sso/validate`). Never add a staff password sign-in. Register the host in IMIS `SSO_HUB_HOSTS`.
- **Roles:** map IMIS `application_roles.code` to `App\Enums\UserRole`: `admin` (legacy `Admin`, sees all offices), `esd_staff` if the ESD users differ from admin **(ask)**, `field_office` (legacy `User` and `Field Office`, scoped to their office).
- **Office scoping:** from the SSO response (`office_code`, `office_type`, `headed_offices`), mapped to the local `offices` table by `office_code`. Enforced in a global query scope plus policies, never only in the frontend. The legacy `fo_rsu` free-text label is display-only.
- **Authorization:** a policy per model plus route `->can()`/`permission` checks, as JPortal does.
- **Database:** own database `etts`. The legacy database is read through a `legacy` connection (`LEGACY_DB_*`) by import commands only. Commands are idempotent, keep legacy ids, print a report of skipped rows and unknown values.
- **Table names:** keep the legacy column names in v1 so the import is a straight copy; rename tables to `etts_*`/plain names in the new schema.
- **Audit:** `AuditLog::record()` on every mutation; reads of applicant data by staff are audited (RA 10173). Legacy `esd_etts_activity_logs` is imported as history.
- **Frontend:** reproduce the current ESD/FO look with Tailwind v4 tokens (themes `default` / `legacy` as design tokens, Inter font), Chart.js via `vue-chartjs` or the existing wrapper, SweetAlert2 replaced by the hub's modal/toast components. Each large legacy JS module becomes a set of Vue pages and composables, not one file.
- **Kiosk:** a separate public route group (`/kiosk/*`) with its own session cookie, CSRF, throttling, a `transaction_type` whitelist, and a narrow response from the duplicate check. Served on `kiosk.cscro8.com` via a second document root or host routing. Never mixed with the staff session.
- **Executive feed:** expose `/api/imis/summary` (contract in `../new-imis/docs/executive-feed.md`) once the figures are agreed.
- **Tests:** PHPUnit, `php artisan test --compact`. Write characterization tests from the legacy rules *before* porting each module.

## Phases

0. **Discovery.** *(in progress)* This document. Get the **(ask)** answers; run `SHOW CREATE TABLE` and distinct-value queries on the live database for `transaction_status`, `transaction_type`, `document_status`, `elig_code`, `office_id` orphans, duplicate SPELS groups, and NULL vs `''` columns; list every action of every handler with its RBAC rule; decide the role mapping; confirm hosting for the app and the kiosk subdomain.
1. **Foundation.** `etts-v2` scaffold from JPortal; IMIS SSO sign-in and role mapping; layout, sidebar (data-driven, like `Modules`), theme tokens; audit log; offices and users tables; `legacy` DB connection; base import command and its report format; CI-style test run.
2. **Reference data and domain core.** Offices, eligibilities (pilot module, admin CRUD with usage count), `TransactionStatus` enum, `OfficeIdentity`, deletion reasons enum, `SpelsDuplicateCheck` and `SpelsDuplicateAudit` as separate services with tests.
3. **Transactions: data and import.** Schema for records and issuance requests; `etts:import-legacy` for records, issuance, deleted/archive and activity logs; verification report (row counts, orphan issuance rows, unknown statuses).
4. **ESD transactions.** All-transactions list with SQL-level filters and pagination, process/edit forms, batch status update with the uniform-status and flow rules, revert status, delete with reason, archive page, timeline drawer.
5. **Transmittals.** Both directions on one table with `direction` filter; eligible-status rules per direction; manual items; printable routing slips; defaults classes for signatory and body text.
6. **Field Office portal.** FO dashboard, command centre, all transactions and deleted records, FO transmittals, office-scoped everywhere.
7. **Dashboards, statistics and reports.** ESD and FO dashboards, aging queue, realtime matrix, request and SPELS statistics, report exports, duplicate-audit page, executive feed.
8. **Kiosks.** Public kiosk (index, menu, request form, setup) with its own session and CSRF, rate limits, CSP; PACD kiosk if still needed; the submission endpoint with the duplicate gate.
9. **Cutover.** `CUTOVER_RUNBOOK.md` and `etts:cutover-check` (as JPortal); UAT with ESD and a field office; final import on a frozen legacy app; uploads folder strategy (existing attachments keep their paths); redirect or retire the old URLs; rollback plan.

Phases 4 to 7 can overlap once Phase 3 is verified. Estimate: 6 to 10 weeks with
AI-assisted development and one reviewer, 2 to 3 months for one developer alone.

## Open questions

1. **(ask)** Role mapping: are there roles beyond Admin, User and Field Office (for example ESD encoders vs ESD head)?
2. **(ask)** Is the PACD kiosk (`views/pacd_kiosk/`) still used, or only the ESD kiosk?
3. **(ask)** Import `esd_casts_record_old` (98 rows) and `esd_casts_activity_logs` (8.6k old SETS-era rows), or leave them in the legacy database?
4. **(ask)** Uploads: are attachments in `uploads/` referenced from the database? Where do they live after cutover?
5. **(ask)** Hosting: subdomain for the app (for example `etts.cscro8.com`), and keep `kiosk.cscro8.com` pointing at the new kiosk?
6. **(ask)** Should the old `/etts/` URLs redirect to the new app after cutover?
7. **(ask)** Which figures does the Executive Dashboard want from ETTS (counts by type and status, aging, monthly totals)?
8. **(ask)** Feature freeze: can the legacy app be frozen from the start of Phase 3 until cutover?

## Decisions (2026-11-04)

1. **Roles.** Two roles: `admin` (legacy Admin, ESD staff, all offices) and `field_office` (legacy User and Field Office, scoped to their own office). Recommendation: keep it at two until ESD asks for a read-only or encoder tier; a role can be added later in IMIS and as one enum case here.
2. **PACD kiosk.** Still used, so it is ported in Phase 8. Note: `htdocs/etts-kiosk` holds only three identical copies of the Hostinger default placeholder page (16 KB each), not kiosk code. The real kiosk code is `../etts/views/kiosk` (ESD) and `../etts/views/pacd_kiosk` (PACD). **(ask)** whether PACD has its own subdomain.
3. **Old tables.** Import `esd_etts_activity_logs` (latest entry 2026-11-04). Do not import `esd_casts_activity_logs` (latest entry 2026-02-06, SETS-era) or `esd_casts_record_old` (98 rows, 97 of them duplicate ids in `esd_casts_records`); they stay in the legacy database.
4. **Attachments.** Private disk, see "Attachments" below.
5. **Hosting.** Production hostname `etts.cscro8.com`. For now: local development only.
6. **Freeze.** The legacy app in htdocs is a copy of production, so no freeze is needed until the final import before cutover.

## Phase 0 findings (from the production dump, loaded locally as `etts_legacy`)

Clean:
- 0 records pointing at a missing office; 0 issuance rows without a record; 0 records with more than one issuance row.
- 0 transmittal items pointing at a missing record.
- 0 zero-dates (`0000-00-00`).
- 0 approved SPELS duplicate groups: the duplicate rule currently holds in production data.
- All `transaction_status` values are already inside the known set: Approved 3,935; Released 3,418; Approved/Released 680; received 146; forwarded 108; Cancelled 82; submitted 47; Returned 42; Completed 12; Disapproved 9. No cert record carries a SPELS-only status or the reverse.
- Transaction types: SPELS 4,944; Authentication 1,910; Certification and Authentication 1,242; Certification 383.
- Records by office: ESD 5,154; FO-Northern Samar 922; FO-Southern Leyte 591; FO-Samar 508; FO-Biliran 391; FO-Eastern Samar 352; SO-Western Leyte 270; FO-Leyte 1 265; FO-Leyte 2 23; PALD 3.
- Transmittals: ESD_TO_FO 130 (129 Released, 1 Draft); FO_TO_RO 3 (all Draft).

Needs handling in the import:
- **Names:** 219 records have NULL middle name, 116 have an empty one, 6 have untrimmed names. Trim all, and store NULL for empty, but keep the legacy comparison until the import is verified.
- **`elig_code`:** 3,535 NULL (expected: cert types have none), 67 `CSE-PR` and 5 empty values are not in the eligibility table. Decide with ESD whether `CSE-PR` is a missing eligibility row.
- **Issuance rows:** 70 SPELS records have an issuance row and 69 certification-type records have none. Import as is; the new code must not assume either.
- **Activity log orphans:** 817 of about 25,000 log rows point at a `record_id` that is in neither `esd_casts_records` nor `esd_casts_records_deleted` (522 ADD_RECORD, 207 DELETE_RECORD, 71 UPDATE_RECORD). Import with `record_id` kept and no foreign key, and list them in the import report.
- **Users:** 34 of 65 `esd_casts_users` rows have no office. They are replaced by SSO; office comes from the IMIS `office_code`.

## Attachments

Legacy behaviour: files sit in `../etts/uploads` (2,746 files, 2.0 GB; jpg 2,344 including 4 uppercase `.JPG`, pdf 331, png 49, jpeg 21, and 1 stray `.crdownload`). The database stores only the random filename in `attachment_1` / `attachment_2` of `esd_casts_issuance_requests` (and its `_deleted` copy). The folder was served publicly by URL, so anyone with a link could open an applicant ID or certificate.

Best practice applied in ETTS v2:
- Files go on the private `attachments` disk (`storage/app/private/attachments`, configurable by `ATTACHMENTS_ROOT`), outside the web root and never served directly.
- They are opened only through a controller route that checks the policy (office scope) and writes an audit log row for every view.
- The same random filenames are kept, so database values carry over unchanged and no rewrite is needed.
- Uploads are validated by extension, real MIME type and size, stored under a random name, and never executed.

Reconciliation against the dump (2026-11-04): 2,307 distinct filenames are referenced in the database; 1 referenced file is missing on disk (`06a43babc955d0ca858388829c1f4ddb.pdf`); 440 files on disk are not referenced by any issuance row. Keep the 440 in a separate `attachments/_unreferenced` folder until ESD confirms they can go; do not delete them.

How to transfer (local rehearsal, then production at cutover):
1. Copy, never move, so the legacy app keeps working: `robocopy C:\xampp\htdocs\etts\uploads C:\xampp\htdocs\etts-v2\storage\app\private\attachments /E /XF .htaccess *.crdownload`
2. Run the verification command (added in Phase 3 with the import): `php artisan etts:verify-attachments`. It reads every referenced filename, checks the file exists, matches the allowed types and has a readable size, and prints missing, unreferenced and mismatched files without changing anything.
3. On production at cutover, repeat with a final sync after the last legacy write: from the Hostinger shell, `rsync -a --ignore-existing uploads/ <new-app>/storage/app/private/attachments/`, then run the verification command again.
4. Keep the legacy `uploads/` folder read-only for at least one month after cutover as the rollback copy, then archive it.

## Phase status

- Phase 0: findings above recorded; the remaining open items are the **(ask)** lines.
- Phase 1 (done): scaffold created at `C:\xampp\htdocs\etts-v2` (JPortal shell, IMIS SSO, `admin` / `field_office` roles, offices table and SSO office mapping, audit log, private attachments disk, `legacy` connection, 18 passing tests). Not yet done: IMIS-side registration (`SSO_HUB_HOSTS`, application and roles), and the base import command.

## Update (Phase 2 complete, local)

Answers received: `CSE-PR` is a real eligibility (added by the import as "CSE-Preference Rating"; its exact title still to be confirmed by ESD); the PACD kiosk has its own subdomain **(ask)** which one, for the Phase 8 host routing.

Phase 2 delivered:
- **Enums** `TransactionStatus`, `TransactionType`, `DeletionReason`: the single source of truth, with the legacy board rules (Completed stays on the Active board) and `allowedFor(type)`. All ten production status strings and four type strings are covered, so the import needs no rewriting.
- **Schema** `offices` (legacy ids kept), `eligibilities`, `transactions` (legacy column names; issuance requests, archive and transmittals arrive in Phase 3 and 5).
- **Services** `App\Services\Spels\DuplicateCheck` (the preventive gate, with `blocksApproval()` and the shared refusal notice) and `DuplicateAudit` (the detective sweep), kept as separate classes with the same narrow-vs-whole-record split as legacy. The check queries the table directly so it spans offices on purpose.
- **Eligibilities module** at `/admin/eligibilities`, admin only: search, sort, usage count, add, edit, delete, every change audited. Two deliberate differences from legacy: the code format now accepts the codes production actually holds (`MC 11`, `CSE-PR (JOCOSC6)`; the legacy form refused them, so it could not re-save its own rows), and a code that records use can no longer be renamed (legacy allowed it, which would orphan those records). The title can always be edited.
- **`php artisan etts:import-legacy`**: idempotent copy of offices and eligibilities from the read-only `legacy` connection, keeping ids, adding the confirmed `CSE-PR`, and reporting unknown codes and the 5 records with an empty code.
- **Tests:** 63 passing (status/board rules, duplicate gate and audit semantics including NULL vs empty middle name, padding, cross-office, approval transition and range filtering, eligibility RBAC and rules, import idempotence).

Known limit: tests run on SQLite, which compares text case-sensitively. The production duplicate rule relies on the MySQL collation (utf8mb4_unicode_ci, case and accent insensitive, as the legacy latin1_swedish_ci was). Before Phase 3 sign-off, run the duplicate audit against the imported data on MySQL and confirm it finds the same groups as legacy (legacy result: 0 groups).

Next: Phase 3, the transactions import (records, issuance requests, archive, activity log) with the verification report, and `etts:verify-attachments`.

## Update (Phase 3 complete, local)

Delivered:
- **Schema:** `issuance_requests` (legacy `sets_records_id` is now `transaction_id`, with a foreign key), `deleted_transactions` and `deleted_issuance_requests` (the archive, no foreign keys on purpose), `legacy_activity_logs` (read-only history; new activity goes to `audit_logs`). Models `IssuanceRequest`, `DeletedTransaction`, `LegacyActivityLog`.
- **`php artisan etts:import-legacy-transactions`** (`App\Services\Legacy\TransactionImporter`): idempotent upsert by legacy id, chunked, read-only on the legacy side. `--fresh` empties the transaction tables first, for the final import at cutover (it asks for confirmation interactively). It skips and reports any row that cannot be linked (missing office or transaction) and exits non-zero if it skipped anything.
- **`php artisan etts:verify-import`**: compares counts, the sum of amounts and the highest id with the legacy database, and checks status and type vocabularies, family mismatches, orphans, id clashes between live and archive, archive reason codes, eligibility codes, and runs the SPELS duplicate audit. Exits non-zero on any mismatch.
- **`php artisan etts:verify-attachments [--list] [--quarantine]`**: checks every file name in the database exists on the private disk, has an allowed type and is not a path; reports files nothing refers to; `--quarantine` moves those to `attachments/_unreferenced` inside this app (never touches the legacy folder, never deletes).
- **Time zones:** the `legacy` connection reads TIMESTAMP columns as Manila time (`+08:00`), as this app does. Checked on record 10588: stored as 03:48:46 UTC in the dump, imported as 11:48:46, which matches the activity log entry for the same save.

Result on the production dump (2026-11-04), all on MySQL:
- Transactions 8,479; issuance requests 3,536; deleted transactions 146; deleted issuance requests 96; activity rows 25,255; sum of amounts 13,139,178; highest id 10,591. **All match the legacy counts.** 0 unknown statuses or types, 0 family mismatches, 0 orphans, 0 id clashes between live and archive, 0 bad archive reason codes, 0 eligibility codes outside the list (after adding `CSE-PR`), and **0 SPELS duplicate groups**, the same as the legacy data. That settles the earlier worry about the SQLite test collation: the rule behaves the same on the real MySQL data.
- Normalised in the copy: 283 values trimmed, 122 empty values set to NULL, 3 zero dates cleared.
- **Two records had a zero transaction date** (#5236, #7979; both SPELS, approved). The column is required, so the import set it to the completion date (2026-04-27 and 2026-08-17) and reported it. **(ask)** ESD to confirm or correct these two dates.
- **One issuance request in the legacy data has a zero exam-issuance date**; stored as NULL.
- 817 activity rows point at records that no longer exist (hard-deleted before the archive existed). Kept as history with no link.

Attachments (rehearsal): the 2,745 files were copied (robocopy, never moved) into `storage/app/private/attachments`. 2,307 are referenced and present, **1 is missing** (`06a43babc955d0ca858388829c1f4ddb.pdf`, belonging to transaction #10588, created 2026-11-04 10:29, i.e. after the htdocs copy of `uploads/` was taken; the final sync at cutover will bring it), and 439 are unreferenced and now sit in `_unreferenced` (the 440th, a `.crdownload` leftover, was excluded from the copy). The legacy `uploads/` folder is untouched (2,746 files).

Not in Phase 3 (by design): transmittals and their items (Phase 5) and returned documents (21 rows, with Phase 5 or 6, **(ask)** whether the feature is still used).

Tests: 81 passing.

## Update (Phase 4a complete, local)

Phase 4 is split in two. **4a (done)** is the status workflow, the board, delete and archive, and the history drawer. **4b (next)** is creating and editing a transaction (the 2.2k-line `process_transaction.php` form, SPELS gate on save, issuance details, attachment upload and the audited attachment viewer), plus inline edit and the payment update.

Delivered in 4a:
- **`TransactionWorkflow`**: the one place status rules live. SPELS: submitted -> forwarded -> received -> Approved / Disapproved / Returned, then Approved -> Approved/Released. Certification / authentication: received -> Completed -> Released, Cancelled until finalised. A filing made at ESD has no forwarding leg and may be received straight from submitted; a field office filing must be forwarded first. A reason is required to cancel, disapprove or return. Approving passes the one-approval-per-eligibility guard on a locked row. Remarks are appended as `[date Status]: text`, as in legacy. Every change writes one audit entry.
- **Batch**: all records must share one status and each must be allowed to make the move; two rival applications in one batch cannot both be approved; all or nothing.
- **Revert** (admin): back to submitted / forwarded / received / completed, never to a stage the type cannot have; the old status is audited.
- **`TransactionArchiver`**: delete moves the record and its issuance request to the archive with who, when, why; reason and a 5 to 1000 character note are mandatory; all or nothing; attachments stay in place.
- **Screens** (`/admin/transactions`, `/admin/archive`): Active and History boards, filtering, search, sorting and paging in SQL (the legacy page downloaded every record and filtered in the browser), per-row actions offered by the workflow, a batch bar, date/remarks dialogs, a history modal merging the imported legacy trail with new audit entries (with the deletion banner for archived records), revert and delete.
- **`OfficeScope`** on `Transaction`: field office users see only their own office in SQL; a signed-in user with no office sees nothing. The ESD screens are administrators only (policy); the field office portal (Phase 6) reuses the scope.
- **Tests:** 130 passing, including the workflow rules, archive, access control and the screens.

Behaviour that differs from legacy, on purpose:
- The legacy handlers disagreed: `forward_to_ro`, `receive_by_ao` and `update_status` checked no flow at all, while `req_modal_update_status` enforced it strictly. The new service applies the strict rules everywhere.
- The legacy batch could apply Approved to a Certification. It cannot now.
- The legacy revert tool wrote no audit entry and no flow check. It now does both.

Found on the real data while testing:
- **Two records carried the status `completed` in lower case** (written by the legacy revert tool). The legacy column is case-insensitive so nothing noticed, but the new status enum is exact. The import now stores the canonical spelling and reports the count (2). `etts:verify-import` now compares status and type spelling exactly (`BINARY` on MySQL), because a plain `NOT IN` would have passed them.
- Active board on the imported data: 313 records (SPELS 123 received, 108 forwarded, 47 submitted; 28 certification or authentication received; 12 completed). Of the 47 submitted SPELS, 21 were filed at field offices and need forwarding, 26 were filed at ESD and can be received directly.

Not yet in 4a: creating and editing, inline edit, the payment update (OR number, amount), CSV export of the board, the activity log page (`views/esd/activity.php`).

## Update (Phase 4b complete, local)

Delivered:
- **Create and edit** at `/admin/transactions/create` and `/admin/transactions/{id}/edit` (`TransactionRecorder`, `TransactionFormRequest`, `Transactions/Form.vue`). One form for all four types; certification and authentication show the request form (address, civil status, purpose with "Others", employment, name used, eligibility record, book/page/sequence, certificate number, dates) and are priced from copies (certificate copies x 100 + authentication copies x 50, details "COE-1, AUTH-2"; prices in `config/etts.php`); SPELS shows eligibility and a free amount.
  - A new SPELS application starts `submitted`; a new certification or authentication starts `received` with both dates set to the filing date.
  - The one-approval-per-eligibility rule is enforced on create and edit.
  - A record cannot change family (SPELS <-> certification/authentication). Legacy allowed it, which orphaned or invented the issuance row.
  - Status is not editable in the form; it moves only through the workflow. Legacy let the edit modal write any status string.
  - An edit carries the record version (`updated_at`); a stale save is refused instead of overwriting someone else's change (legacy did this only on the request form).
  - Saving a SPELS application filed at ESD with an OR number confirms the walk-in and marks it received (legacy "details and payment" behaviour). Legacy did it on any save; requiring the OR number is deliberate, so a save without payment does not receive the application.
  - A certification with no issuance row (69 in the production data) gets one when saved, instead of failing.
  - Audit entries list the changed field names only, never the values, so names and phone numbers are not copied into the log.
- **Quick edit** (OR number, details, remarks, three dates; dates must run in order) from the history window.
- **Attachments**: stored on the private disk under a random name, accepted only as PDF/JPG/PNG within 5 MB **and** only if the real file content matches (a PHP file renamed `.jpg` is refused; the framework's test fakes cannot check this, so it is tested with a real file). Opened only through `/admin/transactions/{id}/attachments/{1|2}`: authorised, name taken from the record never the request, path-like names refused, `nosniff`, `no-store`, and every view audited. Archived records keep their attachments viewable. A replaced file stays on disk, unreferenced, for `etts:verify-attachments --quarantine` to sweep later.
- **CSV export** of the board with the same filters as the screen (`BoardFilters`, shared); policy-restricted, audited, UTF-8 for Excel, and cells starting with `=`, `+`, `-` or `@` are defused against spreadsheet formula injection.
- **Activity log** at `/admin/activity`: current entries and the imported legacy history as two filtered, paged lists.
- New records get ids from 10,592 on (`transactions.id` and `issuance_requests.id` are now auto-increment; legacy ids are still kept on import).
- The import now also writes the sex in full (277 records had "M"/"F" mixed with "Male"/"Female").
- 161 tests passing.

Found on the real data: running every imported record through the new form rules, 8,479 records, ~40 would be refused on re-save until corrected: 21 emails with a typo (`...@gmail,com`), 10 mobile numbers containing a letter, 5 SPELS records with no eligibility, 4 OR numbers with stray characters (`10804060\``), and 1 amount that is plainly a mistyped OR number (record #5004, 11,545,329). They open normally and the form points at the field to fix. A list for ESD can be produced on request.

Still to come in Phase 4: nothing blocking. The legacy "SPELS details" modal and "request form" modal are folded into the single edit form; if ESD wants the two-step flow back it is a UI change only.

## Update (IMIS registration, local)

ETTS v2 is registered with the local IMIS and sign-in works end to end.

Local ports (the hub convention is 8010 to 8070, one per system): **ETTS v2 runs on `http://localhost:8080`**. Port 8040 belongs to `new-eris` (IMIS already lists ERIS at `localhost:8040`); an earlier ETTS dev server had been started there by mistake and was stopped.

What was changed in `new-imis` (all reversible):
1. `new-imis/.env`: `localhost:8080` appended to `SSO_HUB_HOSTS` (the original file is kept as `new-imis/.env.bak-before-etts`).
2. IMIS database `csc_ro8_imis`: a new application **ETTS** (#29, url `http://localhost:8080/sso`, requires access) with two roles, `admin` ("Administrator") and `field_office` ("Field Office"), the `application_roles.code` values `App\Enums\UserRole` expects. It was created through the `Application` model, the same way the other systems are listed.
3. One access grant: `kmbanoyo` (Kim Benedick M. Banoyo, already an ERIS Admin) now holds the ETTS role `admin`. Nobody else has ETTS access yet.

To undo: delete the ETTS application in IMIS (Admin, Systems), restore the `.env` backup.

ETTS settings (`etts-v2/.env`): `APP_URL=http://localhost:8080`, `IMIS_SSO_URL=http://localhost:8000/api/v1/sso/validate`, `IMIS_PORTAL_URL=http://localhost:8000`. The audience defaults to the app host and port (`localhost:8080`), which is what IMIS has in `SSO_HUB_HOSTS`.

Checked with a real hand-off (IMIS issued the launch URL for `kmbanoyo`; ETTS redeemed it server to server): the splash page loads, the token is accepted, the user is created in ETTS as `admin`, and `/dashboard` opens. With that session, every screen answers 200 on the imported data: dashboard, transactions (active and history), new and edit forms (including the record with the mistyped amount), deleted records, eligibilities, activity (current and legacy), staff, a record timeline, and the CSV export.

Findings:
- IMIS sends **no office** for this user (`office_code` is null), so his ETTS profile has no office. An administrator sees every office regardless; field office staff will need their IMIS organisational unit mapped to an ETTS office (`offices.imis_code`) before cutover. **(ask)** how field offices are represented in IMIS organisational units.
- Legacy access lives in the ERIS application: 11 Admin and 22 User holders. At cutover these become ETTS `admin` and `field_office` access, with the office from their IMIS unit. Not done yet, on purpose: only the one developer account has access today.

## Update (Phase 5 complete, local)

Phase 5 covers transmittals in both directions. The ESD screens are done; the field office screens (building and sending a FO_TO_RO batch) are Phase 6, on the same service.

Delivered:
- **Schema and import**: `transmittals` and `transmittal_items`, one table pair for both directions (`direction` is an enum, every query filters by it). `created_by` is stored as `created_by_imis_id` plus a name for new batches. An item points at its transaction **without a foreign key**, so a transaction deleted into the archive can never silently drop a row from a released batch (legacy had `ON DELETE CASCADE`, which did exactly that). `etts:import-legacy-transactions` now copies both tables (133 batches, 1,233 items, 126 of them manual) and `etts:verify-import` checks them.
- **`TransmittalService`**: the rules for both directions in one place.
  - Eligible statuses differ per direction: down = Completed, Approved, Disapproved, Returned; up = Submitted, Returned, SPELS only.
  - A transaction can sit in only one open draft, in either direction (a Returned record is eligible both ways; whichever batch is released first would invalidate the other).
  - An ESD batch is for one field office; a field office batch is for its own office and may carry other documents.
  - Only a Draft can be edited or deleted. Handing a batch over re-checks every record, all or nothing, because records change after being added.
  - Every change is audited.
- **Release behaves differently from legacy, on purpose.** Legacy release rewrote every SPELS record in the batch to Approved/Released, including Disapproved and Returned ones. That would turn a disapproval into an approval and count it against the applicant under the one-approval rule. Now: Approved -> Approved/Released, Completed -> Released, and a Disapproved or Returned application keeps its status and only has its document marked released. The completion date is no longer touched by a release.
- **A record in an open draft cannot be deleted** (it would vanish from under the batch); the message names the draft.
- **Screens** (`/admin/transmittals`): list with direction tabs, search, status and office filters; create (choose the office with how many transactions are waiting, then pick); a draft editor (add or remove transactions, add and edit other documents, edit each transaction's details and remarks note, remarks, release, delete); and a printable **routing slip** with editable addressee, wording and signatory. Defaults (RSU, signatory, designation, wording) moved out of `core/*Defaults.php` into `config/etts.php` and the `.env`; nothing typed on the slip is stored, as before.
- Tests: 202 passing.

Found on the real data:
- **3,923 transactions are eligible to send to a field office right now**, almost all Approved SPELS applications from the months since the transmittal feature started that were never put in a batch (about 150 to 280 a month). Legacy lists them all as well; there is no cut-off. **(ask)** whether ESD wants a cut-off date, or a "not yet claimed" flag, so the picker is not a wall of old approvals. 1,984 of them were filed at ESD itself.
- **Three field office drafts are stale** (all Northern Samar, 22, 25 and 23 rows): their applications have since been forwarded or decided outside the batch, so none can be transmitted. They need to be deleted by the office. One ESD draft for Southern Leyte (34 approved applications, 2026-07-09) has never been released.
- No transaction is in two drafts, and every row of the 129 released batches still resolves to a transaction.
- One released batch is addressed to ESD itself (a likely test); nothing forbids it, as in legacy.

IMIS people search for the addressee and signatory (and the authenticated-copy signatory): legacy read the IMIS user table directly; v2 asks IMIS's directory API instead (`GET /api/v1/etts/employees` in new-imis, bearer key `IMIS_DIRECTORY_KEY` here = IMIS `ETTS_API_TOKEN`). `App\Services\Imis\PeopleDirectory` caches the list for 10 minutes and returns name, position and office only; suggestions only fill the box, so the fields stay free text with the configured defaults, and with the key unset or IMIS down there are simply no suggestions. `esd_casts_returned_documents` (21 rows) is not ported: **(ask)** whether the feature is still used.

## Update (Phase 6 complete, local)

The Field Office portal lives under `/fo` and reuses the ESD rules and pages wherever it can. A `field_office` user is sent to it from `/dashboard`.

Delivered:
- **Access.** Separate abilities from the ESD ones, so the ESD routes stay administrator-only whatever is added: `TransactionPolicy::office`, `createAsOffice`, `workAsOffice` and `TransmittalPolicy::office`, `viewAsOffice`, `workAsOffice`. The model scopes keep every query to the office in SQL; another office's record answers 404, not "forbidden". A field office user with no office gets a clear message on the dashboard and 403 elsewhere; administrators get 403 on `/fo`.
- **Dashboard** (`/fo/dashboard`): to forward, returned, with the Regional Office, ready for release; a six-month chart (drawn without a chart library, with a text equivalent); a type-by-stage table; and the aging queue (submitted more than ten days ago). The legacy "team" panel is not ported (it read the IMIS user table).
- **Transactions** (`/fo/transactions`): Active and History boards for the office, filtered and paged in SQL; file a new SPELS application or certification/authentication (always at the user's own office, whatever is posted); edit, forward one application, delete into the archive with a reason, history window, attachments of own records. The same form page serves both portals (locked to the office here).
- **A field office may change a record only while it is still with the office**: a SPELS application that is submitted or returned, or a certification/authentication that is received but not completed. Once the Regional Office has acted on it, it is read-only for the office and only ESD can edit or delete it. Legacy let the office edit any record of its office at any time, including approved ones.
- **Forwarding** one application (submitted or corrected-returned) without a transmittal, through the same workflow ESD uses; `availableActions` now takes the actor so the office is only ever offered forwarding.
- **Deleted records** (`/fo/deleted`): the office's own, read-only.
- **Transmittals** (`/fo/transmittals`): build a batch of the office's new and corrected applications plus other documents, edit the draft, transmit it (the applications become forwarded, dated), delete a draft, print the slip; and open the released batches ESD has sent down. A batch ESD is still preparing is hidden from the office. ESD and FO use one controller and one set of pages; the FO controller changes only who may do what.
- **Office codes.** IMIS names offices by its own unit codes (`SFO`, `LFO-I`, `ESFO`, ...). `config/etts.php` now maps them to this system's codes and `etts:import-legacy` writes them into `offices.imis_code`, so sign-in puts field office staff at the right office. The command warns about any office with no mapping. Verified against the IMIS `organizational_units` table (10 of 10 offices map).
- 236 tests passing.

Checked on the real data with a simulated Southern Leyte user: sees 591 of the 8,479 transactions (all of its own); dashboard 0 to forward, 3 returned, 31 with the RO; the board shows 31 active and 560 in history; 16 released batches received from ESD; 3 applications are free to send up.

Not tried in a browser: a real field office sign-in. That needs an IMIS user at a field office to be given the ETTS role `field_office` (IMIS, Admin, System access). Only the developer account has ETTS access today.

Open points: **(ask)** whether a field office should be allowed to edit a record after forwarding it (kept read-only, stricter than legacy); and the ESD dashboard and statistics are still to do (Phase 7).

## Update (Phase 7 complete, local)

Phase 7 is the ESD dashboard, the statistics, the duplicate audit and the executive feed, together with a visual lift: the user asked for a richer UI.

**The UI work**
- A small design layer on top of the flat IMIS tokens (`resources/css/app.css`): raised cards with a soft shadow that lifts on hover (`surface-raised`, `surface-lift`), a brand-gradient hero (`hero-gradient`), and a wider chart palette (`--color-chart-3` to `-6`). Motion (charts drawing in, cards lifting) is switched off under `prefers-reduced-motion`.
- **Charts are plain SVG components with no new dependency**: `AreaChart` (smooth lines, gradient fill, hover or arrow-key read-out), `ColumnChart` (grouped or stacked, clickable bars), `DonutChart`, `RankedBars`, `Sparkline`, `AnimatedNumber`. Every chart has a text equivalent (a screen-reader table or a legend with values) and is sized to its container so text never stretches.
- **Dashboard components**: `HeroBanner`, `StatCard`, `ChartCard`, `SegmentedControl`, `DeltaBadge`.
- Applied to the ESD dashboard, the field office dashboard (redone), statistics and the duplicate audit. Tables and filter bars across the app now use the raised card style, and the transactions board was tightened (short type labels, wrapping action buttons) so it fits a normal screen without sideways scrolling.
- Looked at in a real browser against the imported data, which found three things the tests could not: a MySQL-only grouping error in a report query (SQLite accepts it; fixed by grouping on the column alias), a blank hero button, and a donut legend that truncated its labels. All fixed.

**ESD dashboard** (`/dashboard`, `DashboardMetrics`): six headline figures (filed today, open work, awaiting receipt, ready to release, released this month, collected this month); filings over 30 days or 12 months; the stage mix as a donut; filings by type, busiest offices and most applied-for eligibilities; "needs attention" lists (forwarded applications not yet received, documents ready to release by field office with a link to build the transmittal, transmittal drafts left open); average days to complete; and recent activity. Each figure is one grouped query, all of it loads in about half a second on the real data.
- A part-period figure is never compared with a whole one: "filed today" shows yesterday's total beside it, and the month-to-date figures compare with the same days of last month.

**Statistics** (`/admin/statistics`, `StatisticsService`): the two legacy reports. Certifications and authentications by type (filed, received, completed, released, cancelled, pending, with released-by-officer and by-sex); special eligibility by eligibility, where each column counts by its own date. Quick ranges, a date range, an office filter, a chart whose bars open the records, a table whose numbers open the records, and a CSV export. **Each column is defined once as a SQL condition used both to count and to list, and a test checks that every cell in both reports equals its drill-down list**, so the number and the list cannot disagree (legacy kept two copies).

**Duplicate audit** (`/admin/audit/duplicates`): the existing sweep as a page. 0 groups on the production data, as before.

**Executive feed** (`GET /api/imis/summary`): version 1 of the IMIS format, 8 figures (open work by office, filed this month by type, awaiting receipt, ready to release, special eligibility decisions, average days to complete for both families, fees collected). Off (404) until `IMIS_FEED_KEY` is set, 401 for a wrong key, totals only: a test confirms no name, number or officer appears anywhere in the response.

Tests: 270 passing, including the report logic, the cell-versus-list agreement, the feed's format rules and its privacy, and access control for every new screen.

Real figures worth knowing (the legacy figures are the same data, so these match what staff already see): 2,521 special eligibility applications filed so far this year; 2,228 approved (98% of decisions); 119 waiting for a decision; certifications and authentications: 3,535 filed, 3,418 released. The average special eligibility application takes about 10.7 days to complete, a certification or authentication under half a day.

**To switch the feed on**: in IMIS, Executive Dashboard, Settings, enter the ETTS address (`http://localhost:8080/api/imis/summary` locally), generate the key and put it in ETTS `.env` as `IMIS_FEED_KEY`.

Not ported: the legacy dashboard's second-level drill-down screens (`stat_*.php` modals beyond the record lists) and the legacy "reports" print layouts; the statistics page plus its CSV covers the same questions. **(ask)** whether ESD needs a printable report layout.


## Phase 8 update: the public kiosks (done)

One app now serves both kiosks. The PACD kiosk is simply a terminal paired to the PALD office; PALD requests are filed under ESD (`etts.kiosk.files_at_esd`).

**How it differs from the legacy kiosks**
- No password on a public screen. Staff generate a one-time pairing code (Admin, Kiosks) valid 15 minutes; entering it on the device pairs it. Only hashes are stored.
- The device keeps a random token in an HttpOnly, SameSite=Strict cookie (`etts_kiosk_device`); a revoked terminal stops on its next request.
- The office always comes from the terminal, never from the form. The visitor cannot set status, amount or office.
- Isolated session (`etts_kiosk`), own CSRF, strict CSP (no inline scripts), no framing, no-store, honeypot, rate limits (pairing 5 per 15 min; submissions 10/min per address and 30/min per terminal), idle guard that clears the form.
- Kiosk hosts (`ETTS_KIOSK_DOMAINS`) answer only kiosk routes; everything else returns 404.
- The SPELS duplicate rule applies, with a plain message that never reveals contact details.
- Tests: `tests/Feature/Kiosk/KioskTest.php` (29 tests; suite total 299).

**Setting up**: put the kiosk host in `ETTS_KIOSK_DOMAINS`, set `TRUSTED_PROXIES` behind Cloudflare, sign in as an administrator, open Kiosks, generate a code per terminal and enter it on the device at `/setup`.

**Still to do (ask)**: the PACD subdomain name, and cutover tasks (final import, attachment sync, mapping ERIS access holders).


## Phase 9 update: cutover preparation (done, local)

- `php artisan etts:cutover-check` (read-only pre-flight): configuration (key, debug, HTTPS, IMIS addresses, kiosk host, proxies, sessions, build), database (connection, pending migrations, imported data), offices and people (IMIS unit codes, field office users with an office), attachments (every referenced file present, nothing publicly reachable) and kiosks. FAIL blocks; warnings inform. `--local` turns the production-only checks into warnings for rehearsal.
- `docs/CUTOVER_RUNBOOK.md`: preparation, cutover day steps, first week, rollback, open items.
- Rehearsal result: the only FAIL is the one attachment created after the htdocs copy (see Phase 3), which the final sync brings. Tests: 302 passing.

### Access mapping dry-run (local IMIS, 2026-11-04)

`etts:eris-access-plan` found 33 ERIS holders: 24 ready, 8 to review, 1 already granted (the developer account). To review:
- 4 inactive IMIS accounts (1 admin, 3 users): do not grant.
- 1 user in HRD with no matching ETTS office: would see nothing; decide an office or skip.
- 1 admin in a field office (Northern Samar): ETTS admin sees every office, confirm.
- 1 ESD staff with the ERIS User role: ETTS has only admin for ESD, confirm.
Admins in ORD, HRD and PSED are fine: an admin sees every office regardless of unit.


### Decisions (2026-11-04)

- ESD needs no printable report layout: CSV exports and the routing slip are enough.
- The 8 ERIS holders needing review are skipped for now; 24 were granted.
- The PACD kiosk host name is still to be decided.
- A failed kiosk submission now removes its own uploaded files (tested).
