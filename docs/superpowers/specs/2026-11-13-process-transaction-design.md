# Process Transaction page — design

**Date:** 2026-11-13
**Status:** Approved in conversation; awaiting spec review
**Reference:** `docs/PROCESS_TRANSACTION_FLOW.md` (legacy behaviour and findings D1–D9). That document defines the process only. Visual design follows the existing ETTS layout, tokens and components.

## 1. Goal

On `/admin/transactions`, the Edit button for an issuance record (Certification, Authentication, Certification and Authentication) opens a guided processing page instead of the generic edit form. The page takes an ESD processor from data entry to Released. SPELS records keep the existing edit form.

### Success criteria

- An ESD admin can fully process an issuance record on one page: personal data, eligibility, verification references, payment and files, printing, VCAS hand-off, Mark completed and Release.
- Work is saved step by step. Closing the page loses at most the step in progress.
- Every status change goes through `TransactionWorkflow`, so the page and the board apply the same rules.
- Legacy defects D1–D5, D7 and D8 are not carried over.
- Field office users never reach the page.

## 2. Scope

**In:** the page, per-step saves, status lock, VCAS link with audit, upload cleanup on a failed update, Edit link routing, tests.

**Out (follow-ups):**
- D9: Release overwrites `date_completed`. Needs a `date_released` column, which affects imports and reports.
- Concurrency check on status moves (legacy finding 10).
- Storing the signatory on the record. The print audit already records it.
- Eligibility as a list instead of free text.
- Field office edit form: unchanged.
- Board quick edit: unchanged.

## 3. Behaviour

### 3.1 Entry

- `GET /admin/transactions/{id}/process` opens the page for issuance records only. SPELS returns 404.
- `GET /admin/transactions/{id}/edit` redirects issuance records to `/process`. SPELS still gets the edit form.
- On the admin board, the Edit link points to `/process` for issuance rows.
- On first open, the active step is the first step with a missing required field. If every step is complete, the page opens on step 5.

### 3.2 Layout

Uses `StaffLayout`, `PageHeader`, the existing cards, buttons, form components and status badge. Header: applicant name, type, office, status badge, OR number. Below it, a step bar. Main column: the active step. Right column: attachments panel. Below the `lg` breakpoint the attachments panel stacks under the step content.

### 3.3 Steps

Each **Next** (steps 1–4) validates the step on the server, saves it and moves on. Any step already reached can be reopened. **Back** does not save. Leaving a step with unsaved edits asks for confirmation (in-app modal, not a browser dialog).

| Step | Fields | Required |
|---|---|---|
| 1 Personal info | `lname`, `fname`, `mname`, `extn`, `date_of_birth`, `sex`, `place_of_birth`, `civil_status`, `current_address`, `mobile`, `email`, `purpose_of_request` (+ `purpose_others` when "Others"), `employment_status`, `company_name`, `company_address` | `lname`, `fname`, `purpose_of_request` (and `purpose_others` when "Others") |
| 2 Eligibility | `eligibility`, `date_of_exam_issuance`, `place_of_exam_issuance`, `used_lastname`, `used_firstname`, `used_middlename`, `used_extn` | `eligibility`, `date_of_exam_issuance`, `place_of_exam_issuance` |
| 3 Verification | `book`, `page`, `sequence`, `examinee_no`, `cert_no`, `rating`, `release_date`, `effectivity_date` | all except `effectivity_date` |
| 4 Payment & files | `transaction_type` (issuance types only), `coe_copies`, `auth_copies`, `official_receipt`, `or_date`, `attachment_1` (Valid ID), `attachment_2` (Original document) | `transaction_type`, `coe_copies + auth_copies ≥ 1`, `official_receipt` |
| 5 Print & finish | action cards and status button (3.4) | — |

- Field formats reuse the rules in `TransactionFormRequest` (lengths, enums, OR pattern, date format, attachment types and size).
- `or_date` defaults to today in the step 4 form when empty.
- The amount and the `COE-n, AUTH-m` details are computed on the server from the copies (`config('etts.prices')`). The page shows the computed total as a preview.
- Saving step 4 with an OR on a Submitted record receives it (existing `receiveOnPayment`), which also sets the action officer.

### 3.4 Step 5 — Print & finish

Action cards, shown by type:

| Card | Certification | Authentication | Cert. and Auth. |
|---|---|---|---|
| Print request form (ERRF) | yes | yes | yes |
| Process COE (VCAS) | yes | no | yes |
| Print authenticated copy | no | yes | yes |

- **ERRF:** opens the existing `/errf` print (audited).
- **Process COE:** the page calls the audit endpoint, then navigates to the `vcas://` link built on the server from the saved record (3.6).
- **Authenticated copy:** existing `/authenticated-copy` print with the existing people-directory signatory picker (name, position, office). Enabled when an original document (`attachment_2`) is saved and a signatory name is entered.

Status button, from the current status:

| Status | Shown |
|---|---|
| Submitted | Note: "Save the OR number in step 4 to receive this record before it can be completed." |
| Received | **Mark completed** (confirm modal) |
| Completed | **Release** (confirm modal). On success, back to the board with a flash message. |
| Released, Cancelled | nothing |

Both buttons post to the existing `/admin/transactions/{id}/status` route (`TransactionWorkflow::changeStatus`).

### 3.5 Lock

A record is **locked** when its status is Completed, Released or Cancelled.

- Steps 1–4 render read-only. Step 5 printing and VCAS stay available. Release stays available for Completed records.
- The server refuses step saves on a locked record. The existing full-form `PUT /admin/transactions/{id}` also refuses issuance records that are locked.
- The page tells the processor that corrections need an admin Revert first. Revert stays on the board.

### 3.6 VCAS link

- `vcas://` followed by 29 values joined with `/`, in the legacy order: type, last name, first name, middle name, extension, date of birth, place of birth, sex, civil status, current address, employment status, company name, company address, purpose, used last name, used first name, used middle name, used extension, eligibility, exam date, exam place, book, page, sequence, examinee no., certificate no., rating, release date, effectivity date.
- Each empty value becomes `NA`. Newlines collapse to a single space. Each value is encoded with `rawurlencode`, so `/` becomes `%2F` and can no longer shift fields.
- Built from the saved record, never from unsaved form input.
- **Risk to verify on a processor workstation:** the VCAS launcher must decode `%2F` and `%20`. If it does not, keep `rawurlencode` but agree a decoding fix in the launcher, or replace `/` inside values.

### 3.7 Concurrency

Each step save sends the record version (`updated_at`). A mismatch is refused with the existing conflict message and the page offers a reload. A successful save returns the new version.

## 4. Architecture

### 4.1 Routes (admin group)

| Method | Path | Ability | Action |
|---|---|---|---|
| GET | `/admin/transactions/{transaction}/process` | `manage` | `TransactionProcessController@show` |
| POST | `/admin/transactions/{transaction}/process/{step}` (`step` 1–4) | `manage` | `TransactionProcessController@save` |
| POST | `/admin/transactions/{transaction}/process/vcas` | `manage` | `TransactionProcessController@vcas` |

`{step}` is constrained to `[1-4]` so it never matches `vcas`. `TransactionFormController@edit` redirects issuance records to `show`.

### 4.2 Backend units

- **`App\Services\Transactions\ProcessSteps`**: the single source for step field lists, required rules, `firstIncompleteStep(Transaction)`, `isLocked(Transaction)` and `rules(int $step)` (format rules taken from `TransactionFormRequest`).
- **`App\Services\Transactions\VcasLink`**: `for(Transaction): string`. Pure, with no I/O, so it can be unit tested.
- **`App\Http\Controllers\TransactionProcessController`**: thin.
  - `show` renders `Transactions/Process` with the record (reusing `TransactionFormPresenter::edit` for values and attachments), the step state, the lock flag, the VCAS link and the options.
  - `save` validates the step, refuses locked records, merges the step's fields into the current values from the presenter, and calls `TransactionRecorder::update()` with the version and the step 4 files. It redirects back to the next step (or the same step on error).
  - `vcas` writes `AuditLog::record('transaction.vcas_sent', ...)` and returns 204.
- **`TransactionRecorder::update()`**: on any exception, deletes files stored during that call, matching `create()`. Fixes D8.
- **`TransactionFormRequest` / form update**: refuses locked issuance records.

### 4.3 Frontend units

- `resources/js/Pages/Transactions/Process.vue`: shell, step state, per-step `useForm`, unsaved-changes guard.
- `resources/js/Components/Process/StepBar.vue`: steps with reached, active and done states, keyboard reachable.
- `resources/js/Components/Process/PersonalStep.vue`, `EligibilityStep.vue`, `VerificationStep.vue`, `PaymentStep.vue`: field groups (markup from today's `Form.vue`).
- `resources/js/Components/Process/FinishStep.vue`: action cards and status button.
- `resources/js/Components/Process/AttachmentsPanel.vue`: two slots, image inline, PDF embedded, via the audited attachment route.
- `resources/js/Components/Transactions/SignatoryPicker.vue`: extracted from `Form.vue`, used by both pages.
- `resources/js/Pages/Transactions/Index.vue`: Edit link goes to `/process` for issuance rows.

## 5. Error handling

- Validation errors show on the fields of the active step. Nothing is saved.
- `WorkflowException` (conflict, invalid flow, locked) becomes a flash error. A conflict also offers **Reload**.
- A record that does not exist or is SPELS returns 404. A non-admin gets 403.
- A failed upload or save leaves no stored file behind.

## 6. Testing (PHPUnit feature tests, SQLite)

- An issuance record opens `/process`. SPELS gets 404. `/edit` redirects issuance records and still renders SPELS. A field office user gets 403.
- Each step enforces its required fields. Step 4 requires at least one copy and the OR.
- A step save keeps other steps' values (D1 regression, `company_address`).
- A stale version is refused.
- Saving step 4 with an OR on a Submitted record makes it Received and sets the action officer.
- Locked records (Completed, Released, Cancelled) refuse step saves and the full-form update.
- `firstIncompleteStep` and the status button state follow the rules in 3.1 and 3.4.
- `VcasLink`: 29 values in order, `NA` for empty values, a slash in a value encoded as `%2F`, newlines collapsed.
- The VCAS endpoint writes an audit entry.
- `TransactionRecorder::update()` removes stored uploads when the save fails.
- Browser check after implementation: a full run on a test record, then the test record is deleted.
